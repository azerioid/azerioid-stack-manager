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

    /**
     * A71: fresh restore targets are created owned by this dedicated,
     * unprivileged (NOLOGIN, NOSUPERUSER) role, and the restore runs under it,
     * so a SECURITY DEFINER object in the dump is owned by a powerless role
     * rather than the connecting superuser. An existing tenant database is
     * restored under its own owning role instead.
     */
    public const RESTORE_ROLE = 'azerioid_restore';

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
            // Fail closed. The restore must run under a non-admin owning role so
            // a SECURITY DEFINER object in the dump is owned by a limited role,
            // never the connecting superuser. prepareTarget has already created
            // a fresh target owned by the unprivileged restore role, so a healthy
            // lookup returns a non-admin owner; an unknown owner, or one equal to
            // the connecting admin, means the target is unsafe for tenant content.
            if ($role === null || $role === '' || $role === $this->config->postgresqlUser) {
                throw new BrokerException(
                    'Refusing to restore "' . $target . '": its owning role is '
                    . ($role === null || $role === '' ? 'unknown' : 'the admin role')
                    . ' — restore into a tenant-owned database (a superuser restore could let a '
                    . 'SECURITY DEFINER object in the dump run as superuser).',
                    1
                );
            }
            $roleArgs = ['--role=' . $role];
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
        $this->ensureRestoreRole();
        $pgpass = $this->pgpassFile();
        try {
            // -O: a fresh database is owned by the unprivileged restore role, so
            // the restore (run under that role) cannot create superuser-owned
            // objects. New PostgreSQL databases grant PUBLIC CONNECT and PUBLIC
            // EXECUTE by default, so a superuser-owned SECURITY DEFINER object
            // here would otherwise be callable by any tenant.
            $result = $this->runtime->exec(array_merge(
                ['/usr/bin/env', 'PGPASSFILE=' . $pgpass, '/usr/bin/createdb'],
                ['-h', $this->config->postgresqlHost, '-p', (string) $this->config->postgresqlPort],
                ['-U', $this->config->postgresqlUser, '--no-password', '-O', self::RESTORE_ROLE, $target]
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

    /**
     * Ensure the dedicated unprivileged restore role exists (idempotent). NOLOGIN
     * and NOSUPERUSER, so owning objects grants it nothing a tenant could abuse.
     */
    private function ensureRestoreRole(): void
    {
        $pgpass = $this->pgpassFile();
        $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '" . self::RESTORE_ROLE . "') "
            . 'THEN CREATE ROLE "' . self::RESTORE_ROLE . '" NOSUPERUSER NOCREATEDB NOCREATEROLE NOLOGIN; END IF; END $$;';
        try {
            $result = $this->runtime->exec(array_merge(
                ['/usr/bin/env', 'PGPASSFILE=' . $pgpass, '/usr/bin/psql'],
                ['-h', $this->config->postgresqlHost, '-p', (string) $this->config->postgresqlPort],
                ['-U', $this->config->postgresqlUser, '--no-password', '-v', 'ON_ERROR_STOP=1', '-tAc', $sql, 'postgres']
            ), null, 30);
        } finally {
            ($this->cleanup($pgpass))();
        }
        if (!$result->ok()) {
            throw new BrokerException(
                'Could not ensure the restore role: '
                . (trim($result->stderr) !== '' ? trim($result->stderr) : 'unknown error'),
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
