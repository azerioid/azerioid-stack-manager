<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DbAccessPolicy;
use AzerioidPanel\Broker\Database\DbAccessStore;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;

final class MariadbBindFix
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $this->assertNoRemoteAccess($runtime);

        $path = $config->resolveMariadbServerCnf($runtime);
        if ($path === null) {
            throw new BrokerException('MariaDB server config not found.', 3);
        }
        $original = $runtime->readFile($path);
        $stamp = preg_replace('/[^0-9TZ]/', '', $runtime->now()) ?: gmdate('YmdHis');
        $backup = $path . '.lacmp-bak-' . $stamp;
        $runtime->writeFile($backup, $original, 0640);

        $cnf = $original;
        if (preg_match('/^\s*bind-address\s*=/mi', $cnf)) {
            $cnf = preg_replace('/^\s*bind-address\s*=.*$/mi', 'bind-address = 127.0.0.1', $cnf, 1) ?? $cnf;
        } elseif (preg_match('/^\[mysqld\]/mi', $cnf)) {
            $cnf = preg_replace('/^\[mysqld\]/mi', "[mysqld]\nbind-address = 127.0.0.1", $cnf, 1) ?? $cnf;
        } else {
            $cnf = "[mysqld]\nbind-address = 127.0.0.1\n\n" . $cnf;
        }
        $runtime->writeFile($path, $cnf, 0644);
        Systemd::control($runtime, 'restart', 'mariadb');

        return [
            'bind_address' => '127.0.0.1',
            'config_path' => $path,
            'backup_path' => $backup,
            'restarted' => true,
        ];
    }

    private function assertNoRemoteAccess(Runtime $runtime): void
    {
        $store = new DbAccessStore($runtime);
        $details = [];
        $names = [];
        foreach (array_keys((array) ($store->all()['mariadb'] ?? [])) as $name) {
            $name = (string) $name;
            $row = $store->get('mariadb', $name);
            if (!in_array($row['mode'], ['global', 'specific'], true)) {
                continue;
            }
            $details[] = "'{$name}' is set to " . DbAccessPolicy::modeLabel($row['mode'], $row['ips']);
            $names[] = $name;
        }
        if ($details === []) {
            return;
        }
        $change = count($names) === 1
            ? "Change {$names[0]}'s access mode to Localhost only first."
            : 'Change ' . implode(', ', $names) . ' to Localhost only first.';
        throw new BrokerException(
            'Cannot bind MariaDB to 127.0.0.1 while ' . implode(', and ', $details) . '. ' . $change,
            2
        );
    }
}
