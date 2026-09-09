<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ProcMetrics;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;

final class StatusAll
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $controlled = [];
        foreach ($config->controllableServiceList($runtime) as $unit) {
            if (Systemd::loadState($runtime, $unit) === 'not-found') {
                continue;
            }
            $info = Systemd::show($runtime, $unit) + ['controllable' => true];
            if (($info['active_state'] ?? '') === 'failed') {
                $info['journal'] = Systemd::journal($runtime, $unit, 40);
            }
            $controlled[] = $info;
        }

        return [
            'controlled' => $controlled,
            'observed' => self::observed($runtime, $config),
            'warnings' => self::warnings($runtime, $config),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function observed(Runtime $runtime, Config $config): array
    {
        $seen = [];
        $out = [];

        $push = static function (array $row) use (&$seen, &$out): void {
            $key = (string) ($row['bind_hint'] ?? $row['unit'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $row['controllable'] = false;
            $out[] = $row;
        };

        foreach (['redis-server', 'redis'] as $unit) {
            if (!Systemd::isLoaded($runtime, $unit)) {
                continue;
            }
            $info = Systemd::show($runtime, $unit);
            if (!($info['running'] ?? false)) {
                continue;
            }
            $info['controllable'] = false;
            $push($info);
            break;
        }

        foreach (self::proxyUpstreams($runtime, $config) as $row) {
            if ($row['running']) {
                $push($row);
            }
        }

        foreach ($config->observedServices as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (self::isBindSpec($entry)) {
                [$host, $port] = self::splitBind($entry);
                $push(self::probePort($runtime, 'upstream-' . $host . '-' . $port, $host, $port, 'configured upstream ' . $host . ':' . $port));
                continue;
            }
            if (!preg_match(Validator::SERVICE_PATTERN, $entry)) {
                continue;
            }
            if (!Systemd::isLoaded($runtime, $entry)) {
                continue;
            }
            $info = Systemd::show($runtime, $entry);
            $info['controllable'] = false;
            $push($info);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function proxyUpstreams(Runtime $runtime, Config $config): array
    {
        $byUpstream = [];
        foreach (\AzerioidPanel\Broker\Web\WebServers::for($config)->listVhosts($runtime, $config) as $parsed) {
            $base = basename((string) ($parsed['source'] ?? ''), '.conf');
            if ($base === 'azerioid-panel' || $base === 'default') {
                continue;
            }
            $proxy = $parsed['reverse_proxy'] ?? null;
            if (!is_string($proxy) || !self::isBindSpec($proxy)) {
                continue;
            }
            $label = (string) ($parsed['domain'] ?? $base);
            $byUpstream[$proxy][] = $label;
        }

        $rows = [];
        foreach ($byUpstream as $proxy => $labels) {
            [$host, $port] = self::splitBind($proxy);
            $desc = implode(', ', array_unique($labels)) . ' reverse-proxy upstream ' . $proxy;
            $rows[] = self::probePort($runtime, 'upstream-' . $host . '-' . $port, $host, $port, $desc);
        }
        return $rows;
    }

    private static function isBindSpec(string $spec): bool
    {
        return (bool) preg_match('/^(?:127\.0\.0\.1|0\.0\.0\.0|localhost):[1-9][0-9]{0,4}$/', $spec);
    }

    /** @return array{0: string, 1: int} */
    private static function splitBind(string $spec): array
    {
        $spec = str_replace('localhost', '127.0.0.1', $spec);
        [$host, $port] = explode(':', $spec, 2);
        return [$host, (int) $port];
    }

    private static function probePort(Runtime $runtime, string $id, string $host, int $port, string $label): array
    {
        $listening = false;
        if ($port >= 1 && $port <= 65535) {
            $hexPort = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
            foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $path) {
                if (!$runtime->fileExists($path)) {
                    continue;
                }
                foreach (explode("\n", $runtime->readFile($path)) as $line) {
                    if (preg_match('/:[0-9A-F]*' . $hexPort . '\s+\S+\s+0A/i', $line)) {
                        $listening = true;
                        break 2;
                    }
                }
            }
        }
        return [
            'unit' => $id,
            'id' => $id,
            'description' => $label,
            'active_state' => $listening ? 'active' : 'inactive',
            'sub_state' => $listening ? 'running' : 'dead',
            'running' => $listening,
            'controllable' => false,
            'bind_hint' => $host . ':' . $port,
        ];
    }

    private static function warnings(Runtime $runtime, Config $config): array
    {
        $warnings = [];
        if (ProcMetrics::mariadbBindFromProcNet($runtime)) {
            $warnings[] = [
                'id' => 'mariadb_public_bind',
                'severity' => 'high',
                'title' => 'MariaDB is listening on 0.0.0.0:3306',
                'body' => 'The database port is reachable on all interfaces. Bind it to 127.0.0.1 unless a remote app genuinely needs network access. The panel will not change this automatically.',
            ];
        }
        return $warnings;
    }
}
