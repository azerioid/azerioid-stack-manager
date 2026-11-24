<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\PosixRuntime;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DbAddTest extends TestCase
{
    public function test_rolls_back_database_when_create_user_fails(): void
    {
        $rt = new FakeRuntime();
        $rt->dbExecFailAt = 2;
        ob_start();
        $cfg = new Config();
        $cfg->databaseEngine = 'mariadb';
        $cfg->mysqlPassword = 'abcdefghijklmnopqrst';
        $code = (new Kernel($cfg, $rt))->run(
            ['broker', 'db.add', 'shopdb', 'shopuser'],
            ['password' => 'abcdefghijklmnopqrst']
        );
        $out = ob_get_clean();
        $json = json_decode($out, true);

        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString('simulated MariaDB failure', $json['error']);
        $this->assertStringNotContainsString('Internal broker error', $json['error']);
        $sql = implode("\n", $rt->dbExecLog);
        $this->assertStringContainsString('CREATE DATABASE `shopdb`', $sql);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `shopdb`', $sql);
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', $sql);
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', $out);
    }

    /** A108: a database-level grant escapes `_`, which MariaDB treats as a one-character wildcard. */
    public function test_mariadb_grant_matches_only_its_own_database(): void
    {
        $rt = new FakeRuntime();
        $cfg = new Config();
        $cfg->databaseEngine = 'mariadb';
        $cfg->mysqlPassword = 'abcdefghijklmnopqrst';
        ob_start();
        $code = (new Kernel($cfg, $rt))->run(['broker', 'db.add', 'shop_1', 'shop_u'], ['password' => 'abcdefghijklmnopqrst']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $grants = array_values(array_filter($rt->dbExecLog, static fn (string $s): bool => str_starts_with($s, 'GRANT')));
        $this->assertCount(2, $grants);
        foreach ($grants as $g) {
            $this->assertStringContainsString('ON `shop\_1`.*', $g);
            $this->assertStringNotContainsString('`shop_1`', $g);
        }
    }

    /** A108: grants made before are moved to the escaped name, panel-made ones only. */
    public function test_converge_moves_pattern_grants_onto_escaped_names(): void
    {
        $rt = new FakeRuntime();
        $rt->dbQueryFn = static fn (string $sql): array => str_contains($sql, 'FROM mysql.db') ? [
            ['Db' => 'app_1', 'User' => 'app_u', 'Host' => 'localhost', 'Select_priv' => 'Y', 'Drop_priv' => 'Y', 'Grant_priv' => 'N'],
            ['Db' => 'app\\_2', 'User' => 'app_v', 'Host' => 'localhost', 'Select_priv' => 'Y', 'Drop_priv' => 'Y', 'Grant_priv' => 'N'],
            ['Db' => 'ro_db', 'User' => 'reader', 'Host' => 'localhost', 'Select_priv' => 'Y', 'Drop_priv' => 'N', 'Grant_priv' => 'N'],
            ['Db' => 'adm_db', 'User' => 'root', 'Host' => 'localhost', 'Select_priv' => 'Y', 'Drop_priv' => 'Y', 'Grant_priv' => 'N'],
        ] : [];
        $cfg = new Config();
        $cfg->databaseEngine = 'mariadb';
        $cfg->mysqlPassword = 'abcdefghijklmnopqrst';
        ob_start();
        $code = (new Kernel($cfg, $rt))->run(['broker', 'db.grants.converge'], []);
        $json = json_decode((string) ob_get_clean(), true);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame(['app_u@localhost on app_1'], $json['data']['mariadb']);
        $this->assertSame([
            'GRANT ALL PRIVILEGES ON `app\\_1`.* TO `app_u`@`localhost`',
            'REVOKE ALL PRIVILEGES ON `app_1`.* FROM `app_u`@`localhost`',
            'FLUSH PRIVILEGES',
        ], array_values(array_filter($rt->dbExecLog, static fn (string $q): bool => !str_starts_with($q, 'SELECT'))), 'the escaped grant comes first; already-escaped, partial and root grants are left alone');
    }

    /** A108: a new PostgreSQL site database is closed to PUBLIC before add() returns. */
    public function test_postgres_create_statements_close_the_database_to_public(): void
    {
        $sql = \AzerioidPanel\Broker\Database\PostgreSQLDriver::createStatements('shop_1', 'shop_u', 'abcdefghijklmnopqrst');
        $create = array_search('CREATE DATABASE "shop_1" OWNER "shop_u"', $sql, true);
        $revoke = array_search('REVOKE ALL ON DATABASE "shop_1" FROM PUBLIC', $sql, true);
        $this->assertIsInt($create);
        $this->assertSame($create + 1, $revoke, 'revoked right after the create, inside the rolled-back block');
    }

    public function test_pdo_message_redacts_identified_by(): void
    {
        $e = new PDOException("SQLSTATE[42000]: Syntax error: IDENTIFIED BY 'supersecret-password-xx'");
        $msg = PosixRuntime::describePdo($e);
        $this->assertStringNotContainsString('supersecret-password-xx', $msg);
        $this->assertStringContainsString('[redacted]', $msg);
    }

    public function test_unexpected_throwable_is_not_internal_broker_error(): void
    {
        $rt = new FakeRuntime();
        $rt->dbExecFailAt = 1;
        ob_start();
        $cfg = new Config();
        $cfg->databaseEngine = 'mariadb';
        $cfg->mysqlPassword = 'abcdefghijklmnopqrst';
        $code = (new Kernel($cfg, $rt))->run(
            ['broker', 'db.add', 'shopdb', 'shopuser'],
            ['password' => 'abcdefghijklmnopqrst']
        );
        $out = ob_get_clean();
        $json = json_decode($out, true);
        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('Internal broker error', $json['error']);
        $this->assertStringContainsString('simulated MariaDB failure', $json['error']);
    }
}
