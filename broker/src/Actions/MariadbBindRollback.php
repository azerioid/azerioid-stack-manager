<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;

final class MariadbBindRollback
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $path = $config->resolveMariadbServerCnf($runtime) ?? $config->mariadbServerCnf;
        $backup = (string) ($args[0] ?? $input['backup_path'] ?? '');
        $backup = trim($backup);
        $dir = dirname($path);
        if ($runtime->resolveUnderBase($backup, $dir) === null || !str_contains(basename($backup), '.lacmp-bak-')) {
            throw new BrokerException('Backup path is not a panel MariaDB backup.', 3);
        }
        if (!$runtime->fileExists($backup)) {
            throw new BrokerException('Backup file not found.', 3);
        }
        $runtime->writeFile($path, $runtime->readFile($backup), 0644);
        Systemd::control($runtime, 'restart', 'mariadb');
        return [
            'config_path' => $path,
            'restored_from' => $backup,
            'restarted' => true,
        ];
    }
}
