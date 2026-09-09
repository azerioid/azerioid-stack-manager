<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

final class Systemd
{
    public static function loadState(Runtime $runtime, string $unit): string
    {
        $result = $runtime->exec([
            '/usr/bin/systemctl',
            'show',
            $unit,
            '--property=LoadState',
            '--no-pager',
        ]);
        if (preg_match('/^LoadState=(.*)$/m', $result->stdout, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    public static function isLoaded(Runtime $runtime, string $unit): bool
    {
        return in_array(self::loadState($runtime, $unit), ['loaded', 'masked'], true);
    }

    public static function show(Runtime $runtime, string $unit): array
    {
        $result = $runtime->exec([
            '/usr/bin/systemctl',
            'show',
            $unit,
            '--property=Id,ActiveState,SubState,MainPID,NRestarts,ActiveEnterTimestamp,UnitFileState,Description',
            '--no-pager',
        ]);
        $parsed = [
            'unit' => $unit,
            'id' => $unit,
            'active_state' => 'unknown',
            'sub_state' => 'unknown',
            'main_pid' => 0,
            'n_restarts' => 0,
            'active_enter_timestamp' => null,
            'unit_file_state' => 'unknown',
            'description' => $unit,
            'running' => false,
        ];
        foreach (explode("\n", $result->stdout) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            match ($k) {
                'Id' => $parsed['id'] = $v,
                'ActiveState' => $parsed['active_state'] = $v,
                'SubState' => $parsed['sub_state'] = $v,
                'MainPID' => $parsed['main_pid'] = (int) $v,
                'NRestarts' => $parsed['n_restarts'] = (int) $v,
                'ActiveEnterTimestamp' => $parsed['active_enter_timestamp'] = $v !== '' ? $v : null,
                'UnitFileState' => $parsed['unit_file_state'] = $v,
                'Description' => $parsed['description'] = $v,
                default => null,
            };
        }
        $parsed['running'] = $parsed['active_state'] === 'active';
        $parsed['ok'] = $result->ok();
        return $parsed;
    }

    public static function control(Runtime $runtime, string $action, string $unit): array
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'reload'], true)) {
            throw new BrokerException('Invalid systemd action.', 2);
        }
        $result = $runtime->exec(['/usr/bin/systemctl', $action, $unit], null, 60);
        if (!$result->ok()) {
            $sys = trim($result->stderr . "\n" . $result->stdout);
            if ($sys === '') {
                $sys = "systemctl {$action} {$unit} failed.";
            }
            throw new BrokerException(
                $sys . "\n\n--- journalctl -xeu {$unit} ---\n" . self::journal($runtime, $unit),
                1
            );
        }
        return [
            'unit' => $unit,
            'action' => $action,
            'status' => self::show($runtime, $unit),
        ];
    }

    /**
     * @param  list<int>  $expectPorts
     * @return array{path: string, address: string, admin_spec: string, admin_enabled: bool}
     */
    public static function applyCaddy(Runtime $runtime, Config $config, string $mode = 'auto', array $expectPorts = []): array
    {
        return \AzerioidPanel\Broker\Web\WebServers::for($config)->reload($runtime, $config, $mode, $expectPorts);
    }

    public static function statusRaw(Runtime $runtime, string $unit): string
    {
        $result = $runtime->exec(['/usr/bin/systemctl', '--no-pager', '-l', 'status', $unit]);
        return $result->stdout !== '' ? $result->stdout : $result->stderr;
    }

    public static function journal(Runtime $runtime, string $unit, int $lines = 80): string
    {
        $lines = max(1, min(200, $lines));
        $result = $runtime->exec([
            '/usr/bin/journalctl',
            '-xeu',
            $unit,
            '-n',
            (string) $lines,
            '--no-pager',
        ], null, 15);
        $body = trim($result->stdout !== '' ? $result->stdout : $result->stderr);
        return $body !== '' ? $body : '(no journal lines)';
    }
}
