<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * PostgreSQL backup (A2.4).
 *
 * Two formats are unavoidable here, because the tools differ:
 *
 *   - a single database uses `pg_dump -Fc`, whose custom format supports
 *     selective restore and `pg_restore --list` verification;
 *   - `all` must use `pg_dumpall`, which only emits plain SQL — pg_dump has no
 *     `--all` option and pg_dumpall has no `-F`.
 *
 * Restore therefore sniffs the decrypted payload: the custom format starts with
 * the magic "PGDMP", so no extra metadata has to be carried alongside the archive.
 *
 * (The previous PostgreSQLDriver::dump() passed `pg_dump --all` for the cluster
 * case, which is not a valid option and always failed.)
 */
final class PostgreSqlBackupEngine implements BackupEngine
{
    public const CUSTOM_FORMAT_MAGIC = 'PGDMP';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function engine(): string
    {
        return 'postgresql';
    }

    public function isConfigured(): bool
    {
        return $this->config->postgresqlPassword !== '';
    }

    public function supportsDumpAll(): bool
    {
        return true;
    }

    public function dumpCommand(string $database): array
    {
        $which = trim($database);
        $pgpass = $this->pgpassFile();
        $conn = [
            '-h', $this->config->postgresqlHost,
            '-p', (string) $this->config->postgresqlPort,
            '-U', $this->config->postgresqlUser,
            '--no-password',
        ];

        if ($which === '' || $which === 'all') {
            // Cluster-wide: pg_dumpall, plain SQL only.
            $args = array_merge(['/usr/bin/pg_dumpall'], $conn);
            $name = 'all';
        } else {
            $name = Validator::dbName($which);
            $args = array_merge(['/usr/bin/pg_dump'], $conn, ['-Fc', $name]);
        }

        return [
            // PGPASSFILE carries only a path; the password stays in the 0600 file.
            'command' => array_merge(['/usr/bin/env', 'PGPASSFILE=' . $pgpass], $args),
            'env' => [],
            'cleanup' => $this->cleanup($pgpass),
            'name' => $name,
        ];
    }

    public function restoreCommand(string $target): array
    {
        throw new BrokerException(
            'PostgreSQL restore requires the archive payload to pick a tool; use restoreCommandFor().',
            1
        );
    }

    /**
     * @return array{command:list<string>, env:array<string,string>, cleanup:callable():void, tool:string}
     */
    public function restoreCommandFor(string $target, string $payloadPrefix): array
    {
        $target = Validator::dbName($target);
        $pgpass = $this->pgpassFile();
        $conn = [
            '-h', $this->config->postgresqlHost,
            '-p', (string) $this->config->postgresqlPort,
            '-U', $this->config->postgresqlUser,
            '--no-password',
        ];

        if (str_starts_with($payloadPrefix, self::CUSTOM_FORMAT_MAGIC)) {
            $args = array_merge(
                ['/usr/bin/pg_restore'],
                $conn,
                ['-d', $target, '--no-owner', '--no-privileges']
            );
            $tool = 'pg_restore';
        } else {
            // pg_dumpall output is a cluster script; it selects its own databases.
            $args = array_merge(['/usr/bin/psql'], $conn, ['-v', 'ON_ERROR_STOP=1', '-d', $target]);
            $tool = 'psql';
        }

        return [
            'command' => array_merge(['/usr/bin/env', 'PGPASSFILE=' . $pgpass], $args),
            'env' => [],
            'cleanup' => $this->cleanup($pgpass),
            'tool' => $tool,
        ];
    }

    public function targetExists(string $target): bool
    {
        $target = Validator::dbName($target);
        $pgpass = $this->pgpassFile();
        try {
            $result = $this->runtime->exec(array_merge(
                ['/usr/bin/env', 'PGPASSFILE=' . $pgpass, '/usr/bin/psql'],
                ['-h', $this->config->postgresqlHost, '-p', (string) $this->config->postgresqlPort],
                ['-U', $this->config->postgresqlUser, '--no-password', '-tAc'],
                ["SELECT 1 FROM pg_database WHERE datname = '" . $target . "'", 'postgres']
            ), null, 30);
        } finally {
            ($this->cleanup($pgpass))();
        }

        return trim($result->stdout) === '1';
    }

    public function prepareTarget(string $target): void
    {
        $target = Validator::dbName($target);
        if ($this->targetExists($target)) {
            return;
        }
        $pgpass = $this->pgpassFile();
        try {
            $result = $this->runtime->exec(array_merge(
                ['/usr/bin/env', 'PGPASSFILE=' . $pgpass, '/usr/bin/createdb'],
                ['-h', $this->config->postgresqlHost, '-p', (string) $this->config->postgresqlPort],
                ['-U', $this->config->postgresqlUser, '--no-password', $target]
            ), null, 60);
        } finally {
            ($this->cleanup($pgpass))();
        }
        if (!$result->ok()) {
            throw new BrokerException(
                'createdb failed: ' . (trim($result->stderr) !== '' ? trim($result->stderr) : 'unknown error'),
                1
            );
        }
    }

    public function dropTarget(string $target): void
    {
        $target = Validator::dbName($target);
        $pgpass = $this->pgpassFile();
        try {
            $this->runtime->exec(array_merge(
                ['/usr/bin/env', 'PGPASSFILE=' . $pgpass, '/usr/bin/dropdb'],
                ['-h', $this->config->postgresqlHost, '-p', (string) $this->config->postgresqlPort],
                ['-U', $this->config->postgresqlUser, '--no-password', '--if-exists', $target]
            ), null, 60);
        } finally {
            ($this->cleanup($pgpass))();
        }
    }

    public function countObjects(string $target): int
    {
        $target = Validator::dbName($target);
        $pgpass = $this->pgpassFile();
        try {
            $result = $this->runtime->exec(array_merge(
                ['/usr/bin/env', 'PGPASSFILE=' . $pgpass, '/usr/bin/psql'],
                ['-h', $this->config->postgresqlHost, '-p', (string) $this->config->postgresqlPort],
                ['-U', $this->config->postgresqlUser, '--no-password', '-tAc'],
                ["SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')", $target]
            ), null, 30);
        } finally {
            ($this->cleanup($pgpass))();
        }

        return (int) trim($result->stdout);
    }

    private function pgpassFile(): string
    {
        $path = rtrim($this->config->stagingDir, '/') . '/pgpass-' . bin2hex(random_bytes(6));
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        $this->runtime->writeFile($path, sprintf(
            "%s:%d:*:%s:%s\n",
            $this->config->postgresqlHost,
            $this->config->postgresqlPort,
            $this->config->postgresqlUser,
            str_replace([':', '\\'], ['\\:', '\\\\'], $this->config->postgresqlPassword)
        ), 0600);

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
