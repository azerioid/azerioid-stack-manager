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
            // A71: restore under the target database's owning role, not the
            // connecting superuser. A tenant dump can carry a SECURITY DEFINER
            // function; with a plain --no-owner restore every object (that
            // function included) is created owned by the superuser, so the
            // tenant could then call it and run as superuser. --role issues
            // SET ROLE after connecting, so the objects are owned by the tenant
            // and carry only the tenant's privileges.
            $role = $this->ownerOf($target);
            if ($role === null || $role === '') {
                // Fail closed: if the owner cannot be resolved we must NOT fall
                // back to a superuser restore, which is exactly the escalation
                // this guard exists to prevent. prepareTarget has already run, so
                // the database exists and a healthy lookup returns its owner.
                throw new BrokerException(
                    'Refusing to restore "' . $target . '": could not determine its owning role '
                    . '(a superuser restore could let a SECURITY DEFINER object in the dump run as superuser).',
                    1
                );
            }
            // --role is dropped only when the owner is the admin role itself
            // (an admin-owned database restored by the admin — same principal).
            $roleArgs = $role !== $this->config->postgresqlUser ? ['--role=' . $role] : [];
            $args = array_merge(
                ['/usr/bin/pg_restore'],
                $conn,
                ['-d', $target, '--no-owner', '--no-privileges'],
                $roleArgs
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

    /**
     * The role that owns $target, or null if it cannot be determined. Used to
     * restore a tenant dump under the tenant's own role (A71).
     */
    private function ownerOf(string $target): ?string
    {
        $target = Validator::dbName($target);
        $pgpass = $this->pgpassFile();
        try {
            $result = $this->runtime->exec(array_merge(
                ['/usr/bin/env', 'PGPASSFILE=' . $pgpass, '/usr/bin/psql'],
                ['-h', $this->config->postgresqlHost, '-p', (string) $this->config->postgresqlPort],
                ['-U', $this->config->postgresqlUser, '--no-password', '-tAc'],
                [
                    "SELECT pg_catalog.pg_get_userbyid(datdba) FROM pg_catalog.pg_database WHERE datname = '" . $target . "'",
                    'postgres',
                ]
            ), null, 30);
        } finally {
            ($this->cleanup($pgpass))();
        }
        $owner = trim($result->stdout);

        return $owner !== '' ? $owner : null;
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
