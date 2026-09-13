<?php

namespace App\Console\Commands\Azerioid;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use App\Services\Broker\BrokerResponse;
use Illuminate\Console\Command;

trait CallsBroker
{
    protected function brokerCall(
        string $action,
        array $args = [],
        array $stdin = [],
        ?int $timeout = null,
        bool $audit = true,
    ): BrokerResponse {
        $this->configureBrokerForCli();

        // Tag audit trail so CLI vs UI is distinguishable without schema changes.
        $stdin = array_merge(['origin' => 'cli'], $stdin);

        /** @var BrokerClient $client */
        $client = app(BrokerClient::class);

        return $client->call($action, $args, $stdin, $timeout, $audit);
    }

    protected function brokerData(
        string $action,
        array $args = [],
        array $stdin = [],
        ?int $timeout = null,
        bool $audit = true,
    ): array {
        $response = $this->brokerCall($action, $args, $stdin, $timeout, $audit);
        if (! $response->ok) {
            throw new BrokerCallException((string) ($response->error ?: 'Broker call failed.'), $response->code);
        }

        return is_array($response->data) ? $response->data : [];
    }

    protected function configureBrokerForCli(): void
    {
        // PHPUnit sets APP_ENV=testing and BROKER_DRIVER=fake. Never promote to
        // the live sudo broker just because this host also has a panel install.
        if (app()->environment('testing')) {
            return;
        }
        $path = (string) config('azerioid.broker.path', '/usr/local/lib/azerioid-panel/broker');
        if (is_file($path) && is_executable($path)) {
            config([
                'azerioid.broker.driver' => 'sudo',
                // Root can invoke the broker binary directly; non-root uses sudoers.
                'azerioid.broker.use_sudo' => function_exists('posix_geteuid')
                    ? posix_geteuid() !== 0
                    : true,
            ]);
        }
    }

    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /** @param  array<int, string>  $headers  @param  list<list<string|int|null>>  $rows */
    protected function emitTable(array $headers, array $rows): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'headers' => $headers,
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $data */
    protected function emitData(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /**
     * Map CLI/broker failures to process exit codes.
     *
     * Convention (matches Symfony Console + broker JSON `code`):
     * - 0 = success
     * - 2 = validation / usage / policy refusal (Command::INVALID)
     * - 1 = operational / broker failure (Command::FAILURE)
     *
     * Never returns 0: plain RuntimeException defaults to getCode()===0, which
     * must not be treated as success if an error was printed.
     */
    protected function failBroker(\Throwable $e): int
    {
        $this->error($e->getMessage());

        $code = match (true) {
            $e instanceof BrokerCallException => $e->errorCode,
            $e instanceof \AzerioidPanel\Broker\BrokerException => $e->errorCode,
            default => (int) $e->getCode(),
        };

        // Broker: 2 = validation/usage, 3 = policy/refusal.
        if ($code === 2 || $code === 3) {
            return self::INVALID;
        }

        // CLI-local validation uses bare RuntimeException (default code 0).
        // Never treat code 0 as success after printing an error.
        if ($code <= 0 && $e instanceof \RuntimeException && ! $e instanceof BrokerCallException) {
            return self::INVALID;
        }

        return self::FAILURE;
    }

    /** Rethrow a failed BrokerResponse preserving its semantic exit code. */
    protected function throwBrokerFailure(BrokerResponse $response): never
    {
        throw new BrokerCallException(
            (string) ($response->error ?: 'Broker call failed.'),
            $response->code > 0 ? $response->code : 1,
        );
    }
}
