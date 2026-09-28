<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class MariaDbBackupEngine implements BackupEngine
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function engine(): string
    {
        return 'mariadb';
    }

    /**
     * Always available. An empty password is legitimate here — MariaDB unix-socket
     * auth needs none, and `backup.db` worked that way before engines existed.
     * Refusing on an empty password would break those hosts; mysqldump reports a
     * real auth failure clearly enough on its own.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsDumpAll(): bool
    {
        return true;
    }

    public function dumpCommand(string $database): array
    {
        $which = trim($database);
        $cnf = $this->credentialsFile();
        $args = [
            '/usr/bin/mysqldump',
            '--defaults-extra-file=' . $cnf,
            '--protocol=socket',
            '--socket=' . $this->config->mysqlSocket,
            '--single-transaction',
            '--quick',
            '--routines',
            '--skip-comments',
        ];
        if ($which === '' || $which === 'all') {
            $args[] = '--all-databases';
            $name = 'all';
        } else {
            $name = Validator::dbName($which);
            $args[] = $name;
        }

        return [
            'command' => $args,
            'env' => [],
            'cleanup' => $this->cleanup($cnf),
            'name' => $name,
        ];
    }

    public function restoreCommand(string $target): array
    {
        $target = Validator::dbName($target);
        $cnf = $this->credentialsFile();

        return [
            'command' => ['/usr/bin/mysql', '--defaults-extra-file=' . $cnf, $target],
            'env' => [],
            'cleanup' => $this->cleanup($cnf),
        ];
    }

    public function targetExists(string $target): bool
    {
        // dbName() has already allowlisted this; MariaDB rejects bound params here.
        return $this->runtime->dbQuery("SHOW DATABASES LIKE '" . Validator::dbName($target) . "'") !== [];
    }

    public function prepareTarget(string $target): void
    {
        $this->runtime->dbExec(
            'CREATE DATABASE IF NOT EXISTS `' . Validator::dbName($target)
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    public function dropTarget(string $target): void
    {
        $this->runtime->dbExec('DROP DATABASE IF EXISTS `' . Validator::dbName($target) . '`');
    }

    public function countObjects(string $target): int
    {
        $rows = $this->runtime->dbQuery(
            "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = '" . Validator::dbName($target) . "'"
        );

        return (int) ($rows[0]['n'] ?? 0);
    }

    private function credentialsFile(): string
    {
        $path = rtrim($this->config->stagingDir, '/') . '/mysql-' . bin2hex(random_bytes(6)) . '.cnf';
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        $this->runtime->writeFile(
            $path,
            "[client]\nuser={$this->config->mysqlUser}\npassword={$this->config->mysqlPassword}\n"
            . "socket={$this->config->mysqlSocket}\n",
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
