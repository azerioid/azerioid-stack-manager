<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Backup\BackupEngines;
use AzerioidPanel\Broker\Backup\MariaDbBackupEngine;
use AzerioidPanel\Broker\Backup\MongoDbBackupEngine;
use AzerioidPanel\Broker\Backup\PostgreSqlBackupEngine;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A2.4 — `backup.db` used to hardcode mysqldump, so PostgreSQL and MongoDB could
 * not be backed up at all. These pin each engine's command shape and, critically,
 * that no credential ever reaches argv (A23).
 */
final class BackupEngineTest extends TestCase
{
    private const MYSQL_PW = 'mysql-secret-value';

    private const PG_PW = 'pg-secret-value';

    private const MONGO_PW = 'mongo-secret-value';

    private FakeRuntime $rt;

    private Config $cfg;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->mysqlPassword = self::MYSQL_PW;
        $this->cfg->postgresqlPassword = self::PG_PW;
        $this->cfg->mongodbPassword = self::MONGO_PW;
    }

    /** @param list<string> $argv */
    private function assertNoSecretsIn(array $argv): void
    {
        $joined = implode(' ', $argv);
        foreach ([self::MYSQL_PW, self::PG_PW, self::MONGO_PW] as $secret) {
            $this->assertStringNotContainsString($secret, $joined, 'credentials must never reach argv');
        }
    }

    // ----------------------------------------------------------------- MariaDB

    public function test_mariadb_dump_shape_and_credentials_file(): void
    {
        $engine = new MariaDbBackupEngine($this->cfg, $this->rt);
        $spec = $engine->dumpCommand('all');

        $this->assertSame('/usr/bin/mysqldump', $spec['command'][0]);
        $this->assertContains('--all-databases', $spec['command']);
        $this->assertContains('--single-transaction', $spec['command']);
        $this->assertSame('all', $spec['name']);
        $this->assertNoSecretsIn($spec['command']);

        $cnf = str_replace('--defaults-extra-file=', '', $spec['command'][1]);
        $this->assertStringContainsString(self::MYSQL_PW, $this->rt->files[$cnf], 'password lives in the file');

        ($spec['cleanup'])();
        $this->assertArrayNotHasKey($cnf, $this->rt->files, 'cleanup must remove the credentials file');
    }

    public function test_mariadb_named_database_is_validated(): void
    {
        $engine = new MariaDbBackupEngine($this->cfg, $this->rt);
        $this->assertSame('shop', $engine->dumpCommand('shop')['name']);

        $this->expectException(BrokerException::class);
        $engine->dumpCommand('bad;name');
    }

    /** An empty password is valid (unix socket auth), so this must not refuse. */
    public function test_mariadb_is_configured_even_without_a_password(): void
    {
        $cfg = new Config();
        $cfg->mysqlPassword = '';

        $this->assertTrue((new MariaDbBackupEngine($cfg, $this->rt))->isConfigured());
    }

    // -------------------------------------------------------------- PostgreSQL

    public function test_postgres_single_database_uses_custom_format(): void
    {
        $engine = new PostgreSqlBackupEngine($this->cfg, $this->rt);
        $spec = $engine->dumpCommand('shop');

        $this->assertSame(['/usr/bin/env'], [$spec['command'][0]]);
        $this->assertStringStartsWith('PGPASSFILE=', $spec['command'][1]);
        $this->assertContains('/usr/bin/pg_dump', $spec['command']);
        $this->assertContains('-Fc', $spec['command'], 'custom format enables selective restore and --list');
        $this->assertContains('shop', $spec['command']);
        $this->assertSame('shop', $spec['name']);
        $this->assertNoSecretsIn($spec['command']);
    }

    /**
     * pg_dump has no --all option and pg_dumpall has no -F, so the cluster case
     * must switch tools. The previous code passed `pg_dump --all`, which always
     * failed.
     */
    public function test_postgres_cluster_dump_uses_pg_dumpall_not_pg_dump_all(): void
    {
        $engine = new PostgreSqlBackupEngine($this->cfg, $this->rt);
        $spec = $engine->dumpCommand('all');

        $this->assertContains('/usr/bin/pg_dumpall', $spec['command']);
        $this->assertNotContains('/usr/bin/pg_dump', $spec['command']);
        $this->assertNotContains('--all', $spec['command']);
        $this->assertNotContains('-Fc', $spec['command'], 'pg_dumpall cannot emit the custom format');
        $this->assertSame('all', $spec['name']);
    }

    public function test_postgres_password_is_only_in_the_pgpass_file(): void
    {
        $engine = new PostgreSqlBackupEngine($this->cfg, $this->rt);
        $spec = $engine->dumpCommand('shop');

        $path = str_replace('PGPASSFILE=', '', $spec['command'][1]);
        $this->assertStringContainsString(self::PG_PW, $this->rt->files[$path]);
        $this->assertNoSecretsIn($spec['command']);

        ($spec['cleanup'])();
        $this->assertArrayNotHasKey($path, $this->rt->files);
    }

    public function test_postgres_restore_picks_pg_restore_for_custom_format(): void
    {
        $this->rt->execFn = static fn (array $c, ?string $s): ?ExecResult =>
            in_array('-tAc', $c, true) && str_contains(implode(' ', $c), 'pg_get_userbyid')
                ? new ExecResult($c, 0, "tenant_role\n", '')
                : null;
        $engine = new PostgreSqlBackupEngine($this->cfg, $this->rt);
        $spec = $engine->restoreCommandFor('shop', 'PGDMP' . "\x01\x0e");

        $this->assertSame('pg_restore', $spec['tool']);
        $this->assertContains('/usr/bin/pg_restore', $spec['command']);
        $this->assertContains('--no-owner', $spec['command']);
        $this->assertNoSecretsIn($spec['command']);
    }

    public function test_postgres_restore_fails_closed_when_owner_unknown(): void
    {
        // A71: an unresolved owner must abort, never fall back to a superuser
        // restore (the escalation this guard prevents).
        $this->rt->execFn = static fn (array $c, ?string $s): ?ExecResult =>
            in_array('-tAc', $c, true) && str_contains(implode(' ', $c), 'pg_get_userbyid')
                ? new ExecResult($c, 0, "\n", '')
                : null;
        $engine = new PostgreSqlBackupEngine($this->cfg, $this->rt);
        $this->expectException(BrokerException::class);
        $engine->restoreCommandFor('shop', 'PGDMP' . "\x01\x0e");
    }

    public function test_postgres_restore_runs_under_the_db_owner_role(): void
    {
        // A71: a tenant dump can carry a SECURITY DEFINER function; a plain
        // --no-owner restore as the superuser would own it as the superuser, so
        // the tenant could call it and run as superuser. pg_restore must SET ROLE
        // to the target's owning role (--role) so objects are owned by the tenant.
        $this->rt->execFn = static fn (array $c, ?string $s): ?ExecResult =>
            in_array('-tAc', $c, true) && str_contains(implode(' ', $c), 'pg_get_userbyid')
                ? new ExecResult($c, 0, "tenant_role\n", '')
                : null;
        $engine = new PostgreSqlBackupEngine($this->cfg, $this->rt);
        $spec = $engine->restoreCommandFor('shop', 'PGDMP' . "\x01\x0e");

        $this->assertContains('--role=tenant_role', $spec['command']);
        $this->assertContains('--no-owner', $spec['command']);
        $this->assertNoSecretsIn($spec['command']);
    }

    public function test_postgres_restore_picks_psql_for_plain_sql(): void
    {
        $engine = new PostgreSqlBackupEngine($this->cfg, $this->rt);
        $spec = $engine->restoreCommandFor('shop', '--\n-- PostgreSQL database');

        $this->assertSame('psql', $spec['tool']);
        $this->assertContains('/usr/bin/psql', $spec['command']);
        $this->assertContains('ON_ERROR_STOP=1', $spec['command'], 'a failing statement must abort the restore');
    }

    // ----------------------------------------------------------------- MongoDB

    public function test_mongo_dump_streams_an_archive_to_stdout(): void
    {
        $engine = new MongoDbBackupEngine($this->cfg, $this->rt);
        $spec = $engine->dumpCommand('shop');

        $this->assertSame('/usr/bin/mongodump', $spec['command'][0]);
        $this->assertContains('--archive', $spec['command'], 'bare --archive writes to stdout');
        $this->assertContains('--db=shop', $spec['command']);
        $this->assertNoSecretsIn($spec['command']);
    }

    public function test_mongo_cluster_dump_omits_the_db_filter(): void
    {
        $engine = new MongoDbBackupEngine($this->cfg, $this->rt);
        $spec = $engine->dumpCommand('all');

        $this->assertSame('all', $spec['name']);
        foreach ($spec['command'] as $arg) {
            $this->assertStringStartsNotWith('--db=', $arg);
        }
    }

    public function test_mongo_password_is_quoted_in_the_config_file(): void
    {
        $this->cfg->mongodbPassword = 'has"quote\\and-backslash';
        $engine = new MongoDbBackupEngine($this->cfg, $this->rt);
        $spec = $engine->dumpCommand('shop');

        $cfgPath = '';
        foreach ($spec['command'] as $arg) {
            if (str_starts_with($arg, '--config=')) {
                $cfgPath = substr($arg, 9);
            }
        }
        $this->assertNotSame('', $cfgPath);
        $this->assertSame(
            'password: "has\\"quote\\\\and-backslash"' . "\n",
            $this->rt->files[$cfgPath],
            'quotes and backslashes must be escaped so YAML cannot reinterpret them'
        );
    }

    /** We cannot cheaply prove absence, and mongorestore merges, so refuse. */
    public function test_mongo_target_always_reports_existing_so_overwrite_is_confirmed(): void
    {
        $this->assertTrue((new MongoDbBackupEngine($this->cfg, $this->rt))->targetExists('shop'));
    }

    public function test_mongo_restore_drops_and_scopes_to_the_target(): void
    {
        $engine = new MongoDbBackupEngine($this->cfg, $this->rt);
        $spec = $engine->restoreCommand('shop');

        $this->assertSame('/usr/bin/mongorestore', $spec['command'][0]);
        $this->assertContains('--drop', $spec['command']);
        $this->assertContains('--nsInclude=shop.*', $spec['command']);
        $this->assertNoSecretsIn($spec['command']);
    }

    // ---------------------------------------------------------------- resolver

    public function test_resolver_honours_an_explicit_engine(): void
    {
        $engines = new BackupEngines($this->cfg, $this->rt);

        $this->assertSame('postgresql', $engines->for('postgresql')->engine());
        $this->assertSame('mongodb', $engines->for('mongodb')->engine());
        $this->assertSame('mariadb', $engines->for('mariadb')->engine());
    }

    public function test_resolver_detects_the_configured_engine(): void
    {
        $cfg = new Config();
        $cfg->databaseEngine = 'postgresql';
        $cfg->postgresqlPassword = self::PG_PW;

        $this->assertSame('postgresql', (new BackupEngines($cfg, $this->rt))->for()->engine());
    }

    /** backup.db has always meant mysqldump; an unset engine must not break it. */
    public function test_resolver_falls_back_to_mariadb(): void
    {
        $cfg = new Config();

        $this->assertSame('mariadb', (new BackupEngines($cfg, $this->rt))->for()->engine());
    }

    public function test_resolver_rejects_an_unknown_engine(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/Unknown database engine/');
        (new BackupEngines($this->cfg, $this->rt))->for('sqlserver');
    }

    public function test_resolver_refuses_an_unconfigured_engine(): void
    {
        $cfg = new Config();
        $cfg->postgresqlPassword = '';

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/not configured/');
        (new BackupEngines($cfg, $this->rt))->for('postgresql');
    }
}
