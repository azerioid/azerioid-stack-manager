<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

/**
 * firewalld backend (B2 / request #2).
 *
 * Two differences from ufw shape the design here:
 *
 * 1. **Rich rules carry no comment.** firewalld has nowhere to record who wrote a
 *    rule, so ownership lives in a sidecar index next to the panel's other state —
 *    the same approach DbAccessFirewall already takes. If that index is lost the
 *    rules keep working but stop being recognised as panel-written; that is the
 *    honest cost of the backend having no comment field, and it is why the index is
 *    keyed by rule identity rather than by any generated id.
 *
 * 2. **Every change is written to the permanent configuration and reloaded**, never
 *    to the runtime only. A runtime-only rule disappears on the next reload — the
 *    kind of rule that works all week and vanishes during an unrelated restart.
 */
final class FirewalldDriver implements FirewallDriver
{
    public const BIN = '/usr/bin/firewall-cmd';

    public function __construct(
        private readonly Runtime $runtime,
        private readonly string $sidecarPath = '/var/lib/azerioid-panel/firewall-managed.json',
    ) {
    }

    public function name(): string
    {
        return 'firewalld';
    }

    public function available(): bool
    {
        return $this->runtime->fileExists(self::BIN);
    }

    public function active(): bool
    {
        if (!$this->available()) {
            return false;
        }

        return trim($this->runtime->exec([self::BIN, '--state'], null, 15)->stdout) === 'running';
    }

    public function zone(): string
    {
        $zone = trim($this->runtime->exec([self::BIN, '--get-default-zone'], null, 15)->stdout);

        return $zone === '' ? 'public' : $zone;
    }

    /** @return list<FirewallRule> */
    public function rules(): array
    {
        $managed = $this->sidecar();
        $rules = [];

        // Plain open ports: `--add-port=8080/tcp`, no source restriction.
        foreach ($this->words($this->runtime->exec([self::BIN, '--permanent', '--list-ports'], null, 15)->stdout) as $port) {
            if (!preg_match('/^(\d+)\/(tcp|udp)$/', $port, $m)) {
                continue;
            }
            $rules[] = $this->observed(FirewallRule::ALLOW, (int) $m[1], $m[2], null, $managed);
        }

        foreach ($this->lines($this->runtime->exec([self::BIN, '--permanent', '--list-rich-rules'], null, 15)->stdout) as $rich) {
            $rule = $this->parseRich($rich, $managed);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * `rule family="ipv4" source address="10.0.0.5" port port="3306" protocol="tcp" accept`
     */
    private function parseRich(string $rich, array $managed): ?FirewallRule
    {
        if (!preg_match('/port port="(\d+)"/', $rich, $p)) {
            return null;
        }
        if (!preg_match('/protocol="(tcp|udp)"/', $rich, $proto)) {
            return null;
        }
        $source = null;
        if (preg_match('/source address="([^"]+)"/', $rich, $s)) {
            $source = $s[1];
        }
        $action = preg_match('/\b(reject|drop)\b/', $rich) === 1 ? FirewallRule::DENY : FirewallRule::ALLOW;

        return $this->observed($action, (int) $p[1], $proto[1], $source, $managed);
    }

    /** @param list<string> $managed */
    private function observed(string $action, int $port, string $protocol, ?string $source, array $managed): FirewallRule
    {
        $key = $action . ':' . $port . ':' . $protocol . ':' . ($source ?? 'any');
        $comment = $managed[$key] ?? '';

        return FirewallRule::observed($action, $port, $protocol, $source, is_string($comment) ? $comment : '');
    }

    public function add(FirewallRule $rule): void
    {
        $this->run(array_merge([self::BIN, '--permanent'], [$this->argument($rule)]));
        $this->reload();
        $this->remember($rule);
    }

    public function delete(FirewallRule $rule): void
    {
        $this->run([self::BIN, '--permanent', str_replace('--add-', '--remove-', $this->argument($rule))]);
        $this->reload();
        $this->forget($rule);
    }

    /**
     * An unrestricted allow becomes a plain port so it shows up in `--list-ports`
     * where an operator expects it; everything else needs a rich rule.
     */
    private function argument(FirewallRule $rule): string
    {
        if ($rule->action === FirewallRule::ALLOW && $rule->source === null) {
            return '--add-port=' . $rule->port . '/' . $rule->protocol;
        }

        $family = $rule->source !== null && str_contains($rule->source, ':') ? 'ipv6' : 'ipv4';
        $rich = 'rule family="' . $family . '"';
        if ($rule->source !== null) {
            $rich .= ' source address="' . $rule->source . '"';
        }
        $rich .= ' port port="' . $rule->port . '" protocol="' . $rule->protocol . '"';
        // reject, not drop: a refused connection fails fast and tells the operator
        // what happened, where a silent drop looks like a network fault.
        $rich .= $rule->action === FirewallRule::DENY ? ' reject' : ' accept';

        return '--add-rich-rule=' . $rich;
    }

    private function reload(): void
    {
        $this->run([self::BIN, '--reload']);
    }

    /**
     * The zone's permanent XML is the whole rule set, so it is both the snapshot and
     * the means to restore it.
     */
    public function snapshot(): string
    {
        $path = $this->zonePath();

        return (string) json_encode([
            'path' => $path,
            'xml' => $this->runtime->fileExists($path) ? $this->runtime->readFile($path) : null,
            'sidecar' => $this->runtime->fileExists($this->sidecarPath)
                ? $this->runtime->readFile($this->sidecarPath)
                : null,
        ]);
    }

    public function restore(string $snapshot): void
    {
        $state = json_decode($snapshot, true);
        if (!is_array($state) || !isset($state['path'])) {
            throw new BrokerException('Firewall snapshot is unreadable; refusing to restore.', 1);
        }
        if (is_string($state['xml'])) {
            $this->runtime->writeFile((string) $state['path'], $state['xml'], 0644);
        } elseif ($this->runtime->fileExists((string) $state['path'])) {
            // No XML in the snapshot means the zone had no custom configuration.
            $this->runtime->deleteFile((string) $state['path']);
        }
        if (is_string($state['sidecar'] ?? null)) {
            $this->runtime->writeFile($this->sidecarPath, (string) $state['sidecar'], 0640);
        }
        $this->reload();
    }

    private function zonePath(): string
    {
        return '/etc/firewalld/zones/' . $this->zone() . '.xml';
    }

    /** @return array<string,string> rule key => comment */
    private function sidecar(): array
    {
        if (!$this->runtime->fileExists($this->sidecarPath)) {
            return [];
        }
        $data = json_decode($this->runtime->readFile($this->sidecarPath), true);

        return is_array($data) ? $data : [];
    }

    private function remember(FirewallRule $rule): void
    {
        $index = $this->sidecar();
        $index[$rule->key()] = $rule->comment;
        $this->writeSidecar($index);
    }

    private function forget(FirewallRule $rule): void
    {
        $index = $this->sidecar();
        unset($index[$rule->key()]);
        $this->writeSidecar($index);
    }

    /** @param array<string,string> $index */
    private function writeSidecar(array $index): void
    {
        $this->runtime->mkdir(dirname($this->sidecarPath), 0750);
        $this->runtime->writeFile(
            $this->sidecarPath,
            (string) json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0640
        );
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        $result = $this->runtime->exec($command, null, 20);
        if (!$result->ok()) {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);
            throw new BrokerException('firewalld refused the rule: ' . ($detail === '' ? 'unknown error' : $detail), 1);
        }
    }

    /** @return list<string> */
    private function words(string $out): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($out)) ?: []));
    }

    /** @return list<string> */
    private function lines(string $out): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", trim($out)))));
    }
}
