<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class DatabaseManager
{
    public const ENGINES = ['mariadb', 'postgresql', 'mongodb'];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function resolveEngine(?string $engine): string
    {
        $engine = strtolower(trim((string) $engine));
        if ($engine === '') {
            if ($this->config->databaseEngine !== '') {
                $engine = $this->config->databaseEngine;
            } elseif ($this->config->mysqlPassword !== '') {
                $engine = 'mariadb';
            } elseif ($this->config->postgresqlPassword !== '') {
                $engine = 'postgresql';
            } elseif ($this->config->mongodbPassword !== '') {
                $engine = 'mongodb';
            }
        }
        if ($engine === '') {
            throw new BrokerException('No database engine is configured. Install MariaDB, PostgreSQL, or MongoDB from Components.', 3);
        }
        if (!in_array($engine, self::ENGINES, true)) {
            throw new BrokerException('Unknown database engine.', 2);
        }
        $driver = $this->driver($engine);
        if (!$driver->isConfigured()) {
            throw new BrokerException("Database engine {$engine} is not configured.", 3);
        }

        return $engine;
    }

    public function driver(?string $engine = null): DatabaseDriver
    {
        $engine = strtolower(trim((string) ($engine ?? $this->config->databaseEngine)));
        return match ($engine) {
            'mariadb' => new MariaDBDriver($this->config, $this->config->runtimeWithDb($this->runtime)),
            'postgresql' => new PostgreSQLDriver($this->config, $this->runtime),
            'mongodb' => new MongoDriver($this->config, $this->runtime),
            default => throw new BrokerException('Unknown database engine.', 2),
        };
    }

    /** @return list<array{id:string,label:string,configured:bool,active:bool}> */
    public function engines(): array
    {
        $active = $this->config->databaseEngine;
        $labels = ['mariadb' => 'MariaDB', 'postgresql' => 'PostgreSQL', 'mongodb' => 'MongoDB'];
        $list = [];
        foreach (self::ENGINES as $id) {
            $driver = $this->driver($id);
            $list[] = [
                'id' => $id,
                'label' => $labels[$id],
                'configured' => $driver->isConfigured(),
                'active' => $active === $id,
            ];
        }

        return $list;
    }

    public function dumpPath(string $engine, string $name): string
    {
        $engine = $this->resolveEngine($engine);
        $safeName = ($name === '' || $name === 'all') ? 'all' : Validator::dbName($name);
        $stamp = gmdate('Ymd\THis\Z');
        $ext = $engine === 'postgresql' ? '.pgdump' : '.sql.gz';

        return rtrim($this->config->stagingDir, '/') . '/dumps/' . $engine . '-' . $safeName . '-' . $stamp . $ext;
    }
}
