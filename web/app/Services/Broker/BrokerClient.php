<?php

namespace App\Services\Broker;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use AzerioidPanel\Broker\AuditLog as BrokerAudit;
use AzerioidPanel\Broker\Validator;
use Symfony\Component\Process\Process;

/**
 * Dual-tier client: re-validates the action name, then invokes the broker
 * via an argument array (never a shell string). Secrets travel on stdin JSON.
 */
final class BrokerClient
{
    public function __construct(private readonly FakeBroker $fake)
    {
    }

    public function call(string $action, array $args = [], array $stdin = [], ?int $timeout = null, bool $audit = true): BrokerResponse
    {
        Validator::action($action);
        $this->assertSafeArgs($args);

        if (! array_key_exists('origin', $stdin)) {
            $stdin['origin'] = Auth::check() ? 'ui' : 'internal';
        }

        $driver = (string) config('azerioid.broker.driver', 'fake');
        try {
            $response = match ($driver) {
                'sudo' => $this->viaSudo($action, $args, $stdin, $timeout),
                'in-process' => $this->viaInProcess($action, $args, $stdin),
                default => $this->fake->handle($action, $args, $stdin),
            };
        } catch (\Throwable $e) {
            $response = new BrokerResponse(false, null, $e->getMessage(), 1);
        }

        if ($audit) {
            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'args' => BrokerAudit::redact(array_merge(
                    ['argv' => $args],
                    $stdin
                )),
                'ok' => $response->ok,
                'code' => $response->code,
                'error' => $response->error,
                'ip' => request()?->ip(),
            ]);
        }

        return $response;
    }

    private function assertSafeArgs(array $args): void
    {
        foreach ($args as $arg) {
            if (! is_scalar($arg)) {
                throw new BrokerCallException('Broker arguments must be scalars.', 2);
            }
            $s = (string) $arg;
            if (str_contains($s, "\0") || str_contains($s, "\n")) {
                throw new BrokerCallException('Broker argument contains invalid characters.', 2);
            }
        }
    }

    private function viaSudo(string $action, array $args, array $stdin, ?int $timeout = null): BrokerResponse
    {
        $broker = (string) config('azerioid.broker.path');
        $cmd = [];
        if (config('azerioid.broker.use_sudo')) {
            $cmd[] = (string) config('azerioid.broker.sudo_path');
            $cmd[] = '-n';
        }
        $cmd[] = $broker;
        $cmd[] = $action;
        foreach ($args as $arg) {
            $cmd[] = (string) $arg;
        }

        $process = new Process($cmd);
        $process->setTimeout($timeout ?? (int) config('azerioid.broker.timeout', 45));
        if ($stdin !== []) {
            $process->setInput(json_encode($stdin, JSON_UNESCAPED_SLASHES));
        }
        $process->run();

        return $this->decodeBrokerOutput($process->getOutput(), $process->getErrorOutput());
    }

    private function viaInProcess(string $action, array $args, array $stdin): BrokerResponse
    {
        $configPath = getenv('AZERIOID_PANEL_CONFIG') ?: getenv('LACMP_PANEL_CONFIG') ?: '/etc/azerioid-panel/broker.json';
        $runtime = new \AzerioidPanel\Broker\PosixRuntime();
        $config = \AzerioidPanel\Broker\Config::load($configPath, $runtime);
        $kernel = new \AzerioidPanel\Broker\Kernel($config, $runtime);
        ob_start();
        $kernel->run(array_merge(['broker', $action], $args), $stdin);
        $out = ob_get_clean();

        return $this->decodeBrokerOutput((string) $out, '');
    }

    private function decodeBrokerOutput(string $stdout, string $stderr): BrokerResponse
    {
        $stdout = trim($stdout);
        $decoded = json_decode($stdout, true);
        if (! is_array($decoded) && $stdout !== '') {
            $start = strpos($stdout, '{');
            $end = strrpos($stdout, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($stdout, $start, $end - $start + 1), true);
            }
        }
        if (is_array($decoded)) {
            return BrokerResponse::fromArray($decoded);
        }

        $snippet = $this->safeOutputSnippet($stdout !== '' ? $stdout : $stderr);

        return new BrokerResponse(
            false,
            null,
            'Broker returned non-JSON output.'.($snippet !== '' ? ' '.$snippet : ''),
            1
        );
    }

    private function safeOutputSnippet(string $raw): string
    {
        $raw = preg_replace('/[^\P{C}\n\t]/u', '', $raw) ?? $raw;
        $raw = preg_replace('/(?i)(password|secret|token|passwd)\s*[:=]\s*\S+/', '$1=[redacted]', $raw) ?? $raw;
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if ($raw === '') {
            return '';
        }

        return '(' . mb_substr($raw, 0, 240) . ')';
    }
}
