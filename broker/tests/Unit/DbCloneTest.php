<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A79 (increment 2): clone a database to a new name. The source is dumped and
 * loaded into a freshly provisioned target+user through the audited backup-engine
 * path. MariaDB/PostgreSQL only; a failed clone rolls its target back.
 */
final class DbCloneTest extends TestCase
{
    private function mariadbKernel(): array
    {
        $rt = new FakeRuntime();
        $cfg = new Config();
        $cfg->databaseEngine = 'mariadb';
        $cfg->mysqlUser = 'root';
        $cfg->mysqlPassword = 'abcdefghijklmnopqrst';
        $cfg->mysqlSocket = '/run/mysqld/mysqld.sock';

        // list(): 'shop' exists, the clone target does not. add()'s precondition
        // (schema by name) must be empty so provisioning proceeds; delete()'s later
        // identical lookup must find the just-created target so rollback can DROP it.
        $schemaByNameCalls = 0;
        $rt->dbQueryFn = function (string $sql) use (&$schemaByNameCalls): array {
            if (str_contains($sql, 'information_schema.SCHEMATA s')) {
                return [['name' => 'shop', 'size_bytes' => 10, 'table_count' => 2]];
            }
            if (str_contains($sql, 'SCHEMATA WHERE SCHEMA_NAME = ?')) {
                $schemaByNameCalls++;

                return $schemaByNameCalls === 1 ? [] : [['SCHEMA_NAME' => 'shopstg']];
            }

            return []; // mysql.db grants and mysql.user precondition: none
        };

        return [new Kernel($cfg, $rt), $rt];
    }

    /** @return array{0:int,1:array} */
    private function kernelRun(Kernel $kernel, array $argv, array $input = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $input);
        $out = (string) ob_get_clean();

        return [$code, json_decode(trim($out), true) ?: []];
    }

    public function test_clone_dumps_the_source_and_loads_a_fresh_target(): void
    {
        [$kernel, $rt] = $this->mariadbKernel();
        $restoreCnf = null;
        $rt->execFn = function (array $cmd, ?string $stdin) use ($rt, &$restoreCnf): ?ExecResult {
            if (($cmd[0] ?? '') === '/usr/bin/mysqldump') {
                // A view carrying the SOURCE user's DEFINER, as mysqldump emits.
                return new ExecResult($cmd, 0,
                    "-- SQL dump\nCREATE TABLE t (id int);\n"
                    . "/*!50013 DEFINER=`shop`@`localhost` SQL SECURITY DEFINER */\n"
                    . "/*!50001 VIEW `v` AS SELECT 1 */;\n",
                    '');
            }
            if (($cmd[0] ?? '') === '/usr/bin/mysql') {
                // Capture the credentials file content while it still exists.
                foreach ($cmd as $arg) {
                    if (str_starts_with($arg, '--defaults-extra-file=')) {
                        $restoreCnf = $rt->files[substr($arg, 22)] ?? null;
                    }
                }

                return new ExecResult($cmd, 0, '', '');
            }

            return null;
        };

        [$code, $json] = $this->kernelRun($kernel, ['broker', 'db.clone', 'shop', 'shopstg']);
        $this->assertSame(0, $code, json_encode($json));
        $data = $json['data'] ?? [];
        $this->assertSame('shopstg', $data['target'] ?? null);
        $this->assertSame('shopstg', $data['user'] ?? null);
        $this->assertSame(48, strlen((string) ($data['password'] ?? '')));

        $dumped = false;
        $restoreStdin = null;
        foreach ($rt->execLog as $e) {
            $dumped = $dumped || ($e['command'][0] ?? '') === '/usr/bin/mysqldump';
            if (($e['command'][0] ?? '') === '/usr/bin/mysql') {
                $restoreStdin = (string) $e['stdin'];
            }
        }
        $this->assertTrue($dumped, 'clone must dump the source');
        $this->assertNotNull($restoreStdin, 'clone must load the target');
        // The DEFINER must be rebound to the clone's own user, not the source's,
        // so a SQL SECURITY DEFINER object cannot reach back into the source DB.
        $this->assertStringContainsString('DEFINER=`shopstg`@`localhost`', $restoreStdin);
        $this->assertStringNotContainsString('DEFINER=`shop`@`localhost`', $restoreStdin);
        // The load must run as the clone's own unprivileged user, never root, so
        // crafted dump content can only reach the clone database.
        $this->assertNotNull($restoreCnf, 'the restore must use a credentials file');
        $this->assertStringContainsString('user=shopstg', (string) $restoreCnf);
        $this->assertStringNotContainsString('user=root', (string) $restoreCnf);
        // The generated password must never reach the SQL log (bound as a param).
        $this->assertStringNotContainsString((string) $data['password'], implode("\n", $rt->dbExecLog));
    }

    public function test_clone_refuses_mongodb(): void
    {
        $rt = new FakeRuntime();
        $cfg = new Config();
        $cfg->databaseEngine = 'mongodb';
        $cfg->mongodbUser = 'admin';
        $cfg->mongodbPassword = 'abcdefghijklmnopqrst';
        [$code, $json] = $this->kernelRun(new Kernel($cfg, $rt), ['broker', 'db.clone', 'shop', 'shopstg']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not supported yet', strtolower((string) ($json['error'] ?? '')));
    }

    public function test_clone_refuses_same_name_and_missing_source(): void
    {
        [$kernel] = $this->mariadbKernel();
        [$same] = $this->kernelRun($kernel, ['broker', 'db.clone', 'shop', 'shop']);
        $this->assertNotSame(0, $same);

        [$kernel2] = $this->mariadbKernel();
        [$missing, $mj] = $this->kernelRun($kernel2, ['broker', 'db.clone', 'nope', 'nopestg']);
        $this->assertNotSame(0, $missing);
        $this->assertStringContainsString('not found', strtolower((string) ($mj['error'] ?? '')));
    }

    public function test_failed_restore_rolls_the_target_back(): void
    {
        [$kernel, $rt] = $this->mariadbKernel();
        $rt->execFn = function (array $cmd): ?ExecResult {
            if (($cmd[0] ?? '') === '/usr/bin/mysqldump') {
                return new ExecResult($cmd, 0, "-- SQL dump\n", '');
            }
            if (($cmd[0] ?? '') === '/usr/bin/mysql') {
                return new ExecResult($cmd, 1, '', 'restore blew up');
            }

            return null;
        };

        [$code, $json] = $this->kernelRun($kernel, ['broker', 'db.clone', 'shop', 'shopstg']);
        $this->assertNotSame(0, $code);
        // The partially-provisioned clone must be dropped.
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `shopstg`', implode("\n", $rt->dbExecLog));
    }
}
