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
        $pipeline = null;
        $rt->execFn = function (array $cmd) use ($rt, &$restoreCnf, &$pipeline): ?ExecResult {
            if (($cmd[0] ?? '') === '/bin/bash') {
                $pipeline = (string) $cmd[2];
                // Capture the credentials file content while it still exists.
                if (preg_match("/--defaults-extra-file=([^' ]*mysql-clone-[^' ]*)/", $pipeline, $m) === 1) {
                    $restoreCnf = $rt->files[$m[1]] ?? null;
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

        // One streamed pipeline — dump | DEFINER rewrite | load — so the dump is never
        // buffered in the broker; pipefail makes a failed dump fail the clone.
        $this->assertNotNull($pipeline, 'clone must stream the dump into the target');
        $this->assertStringStartsWith('set -o pipefail; ', $pipeline);
        $stages = explode(' | ', substr($pipeline, strlen('set -o pipefail; ')));
        $this->assertCount(3, $stages);
        $this->assertStringContainsString('/usr/bin/mysqldump', $stages[0]);
        $this->assertStringContainsString("'shop'", $stages[0]);
        $this->assertStringContainsString('/usr/bin/mysql', $stages[2]);
        $this->assertStringContainsString("'shopstg'", $stages[2]);

        // Run the rewrite stage for real on a view carrying the SOURCE user's DEFINER,
        // as mysqldump emits it. The DEFINER must be rebound to the clone's own user,
        // not the source's, so a SQL SECURITY DEFINER object cannot reach back into
        // the source DB.
        $dump = "CREATE TABLE t (id int);\n"
            . "/*!50013 DEFINER=`shop`@`localhost` SQL SECURITY DEFINER */\n"
            . "/*!50003 CREATE DEFINER=`we``ird`@`%` PROCEDURE `p`() SELECT `t`.`id` FROM `t` */;\n"
            . "INSERT INTO `notes` VALUES (1,'how to: DEFINER=`root`@`localhost`');\n";
        $proc = proc_open(['/bin/sh', '-c', $stages[1]], [['pipe', 'r'], ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $dump);
        fclose($pipes[0]);
        $rewritten = (string) stream_get_contents($pipes[1]);
        $this->assertSame(0, proc_close($proc));
        $this->assertSame(2, substr_count($rewritten, 'DEFINER=`shopstg`@`localhost`'));
        $this->assertStringNotContainsString('`shop`@', $rewritten);
        $this->assertStringNotContainsString('ird`@`%`', $rewritten);
        $this->assertStringContainsString('PROCEDURE `p`() SELECT `t`.`id` FROM `t`', $rewritten);
        // Row data that merely looks like a clause is copied untouched.
        $this->assertStringContainsString("(1,'how to: DEFINER=`root`@`localhost`')", $rewritten);
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
            if (($cmd[0] ?? '') === '/bin/bash') {
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
