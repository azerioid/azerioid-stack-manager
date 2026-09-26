<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Resolves the backup engine, mirroring Database\DatabaseManager so both agree on
 * which engine a host is running (A2.4).
 */
final class BackupEngines
{
    public const ENGINES = ['mariadb', 'postgresql', 'mongodb'];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function for(?string $engine = null): BackupEngine
    {
        $engine = strtolower(trim((string) ($engine ?? '')));
        if ($engine === '') {
            $engine = $this->detect();
        }
        if (!in_array($engine, self::ENGINES, true)) {
            throw new BrokerException('Unknown database engine: ' . $engine, 2);
        }
        $driver = match ($engine) {
            'mariadb' => new MariaDbBackupEngine($this->config, $this->config->runtimeWithDb($this->runtime)),
            'postgresql' => new PostgreSqlBackupEngine($this->config, $this->runtime),
            'mongodb' => new MongoDbBackupEngine($this->config, $this->runtime),
        };
        if (!$driver->isConfigured()) {
            throw new BrokerException(
                'Database engine ' . $engine . ' is not configured on this host.',
                3
            );
        }

        return $driver;
    }

    private function detect(): string
    {
        if ($this->config->databaseEngine !== '') {
            return strtolower(trim($this->config->databaseEngine));
        }
        if ($this->config->mysqlPassword !== '') {
            return 'mariadb';
        }
        if ($this->config->postgresqlPassword !== '') {
            return 'postgresql';
        }
        if ($this->config->mongodbPassword !== '') {
            return 'mongodb';
        }

        // `backup.db` has always meant mysqldump, so fall back to it rather than
        // refusing a host that never needed an explicit engine setting.
        return 'mariadb';
    }
}
