<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * MongoDB backup (A2.4).
 *
 * `mongodump --archive` (no filename) writes a single stream to stdout, which is
 * exactly what the pipeline wants. Credentials go in a `--config` YAML file
 * rather than argv, per A23 — mongodump has accepted `--config` for password
 * since the 4.2 tools.
 *
 * Caveat carried deliberately: unlike MariaDB and PostgreSQL there is no cheap
 * existence check here without a shell round-trip, and `mongorestore` merges into
 * an existing database rather than replacing it. targetExists() therefore reports
 * true so restore always demands the typed overwrite confirmation — refusing by
 * default is the safe direction when we cannot be certain.
 */
final class MongoDbBackupEngine implements BackupEngine
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function engine(): string
    {
        return 'mongodb';
    }

    public function isConfigured(): bool
    {
        return $this->config->mongodbPassword !== '';
    }

    public function supportsDumpAll(): bool
    {
        return true;
    }

    public function dumpCommand(string $database): array
    {
        $which = trim($database);
        $cfg = $this->credentialsFile();
        $args = [
            '/usr/bin/mongodump',
            '--config=' . $cfg,
            '--host=' . $this->config->mongodbHost,
            '--port=' . $this->config->mongodbPort,
            '--username=' . $this->config->mongodbUser,
            '--authenticationDatabase=admin',
            // Bare --archive streams to stdout.
            '--archive',
            '--quiet',
        ];
        if ($which === '' || $which === 'all') {
            $name = 'all';
        } else {
            $name = Validator::dbName($which);
            $args[] = '--db=' . $name;
        }

        return [
            'command' => $args,
            'env' => [],
            'cleanup' => $this->cleanup($cfg),
            'name' => $name,
        ];
    }

    public function restoreCommand(string $target): array
    {
        $target = Validator::dbName($target);
        $cfg = $this->credentialsFile();

        return [
            'command' => [
                '/usr/bin/mongorestore',
                '--config=' . $cfg,
                '--host=' . $this->config->mongodbHost,
                '--port=' . $this->config->mongodbPort,
                '--username=' . $this->config->mongodbUser,
                '--authenticationDatabase=admin',
                '--archive',
                '--quiet',
                '--drop',
                '--nsInclude=' . $target . '.*',
            ],
            'env' => [],
            'cleanup' => $this->cleanup($cfg),
        ];
    }

    /** Always true — see the class note; we refuse rather than guess. */
    public function targetExists(string $target): bool
    {
        Validator::dbName($target);

        return true;
    }

    public function prepareTarget(string $target): void
    {
        // mongorestore creates the database and collections as it goes.
        Validator::dbName($target);
    }

    private function credentialsFile(): string
    {
        if ($this->config->mongodbPassword === '') {
            throw new BrokerException('MongoDB password is not configured.', 3);
        }
        $path = rtrim($this->config->stagingDir, '/') . '/mongo-' . bin2hex(random_bytes(6)) . '.yaml';
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        // Quote the value so YAML cannot reinterpret characters in the password.
        $this->runtime->writeFile(
            $path,
            'password: "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->config->mongodbPassword) . "\"\n",
            0600
        );

        return $path;
    }

    private function cleanup(string $path): callable
    {
        $runtime = $this->runtime;

        return static function () use ($runtime, $path): void {
            if ($runtime->fileExists($path)) {
                $runtime->deleteFile($path);
            }
        };
    }
}
