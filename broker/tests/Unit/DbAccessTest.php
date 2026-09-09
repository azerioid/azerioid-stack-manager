<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DbAccessPolicy;
use AzerioidPanel\Broker\Database\MariaDBAccess;
use AzerioidPanel\Broker\Database\PostgreSQLAccess;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbAccessTest extends TestCase
{
    public function test_ip_or_cidr_accepts_ipv4_and_prefix(): void
    {
        $this->assertSame('203.0.113.5', Validator::ipOrCidr('203.0.113.5'));
        $this->assertSame('198.51.100.0/24', Validator::ipOrCidr('198.51.100.0/24'));
        $this->assertSame('10.0.0.1', Validator::ipOrCidr('10.0.0.1/32'));
    }

    #[DataProvider('injectionIps')]
    public function test_ip_or_cidr_rejects_injection(string $value): void
    {
        $this->expectException(BrokerException::class);
        Validator::ipOrCidr($value);
    }

    /** @return list<array{0:string}> */
    public static function injectionIps(): array
    {
        return [
            ['1.2.3.4; DROP TABLE x'],
            ["1.2.3.4' OR 1=1"],
            ['1.2.3.4; rm -rf /'],
            ['1.2.3.4$(whoami)'],
            ['1.2.3.4`id`'],
            ['1.2.3.4\n0.0.0.0/0'],
            ['not-an-ip'],
            ['203.0.113.5/33'],
            ['::1'],
        ];
    }

    public function test_global_mode_without_ips(): void
    {
        $this->assertSame([], Validator::accessIps('global', []));
        $this->expectException(BrokerException::class);
        Validator::accessIps('specific', []);
    }

    public function test_mariadb_creates_new_host_before_dropping_old(): void
    {
        $rt = new FakeRuntime();
        $rt->dbQueryFn = static function (string $sql, array $params): array {
            if (str_contains($sql, 'authentication_string')) {
                return [['plugin' => 'mysql_native_password', 'authentication_string' => '*HASHHASHHASHHASH']];
            }
            if (str_contains($sql, 'FROM mysql.user')) {
                return [
                    ['Host' => 'localhost'],
                    ['Host' => '127.0.0.1'],
                    ['Host' => '198.51.100.9'],
                ];
            }

            return [];
        };

        $result = (new MariaDBAccess($rt))->apply('appdb', 'appdb', 'specific', ['203.0.113.5']);
        $sql = implode("\n", $rt->dbExecLog);
        $createPos = strpos($sql, '`appdb`@`203.0.113.5`');
        $dropPos = strpos($sql, 'DROP USER IF EXISTS `appdb`@`198.51.100.9`');
        $this->assertNotFalse($createPos);
        $this->assertNotFalse($dropPos);
        $this->assertLessThan($dropPos, $createPos);
        $this->assertStringNotContainsString('DROP USER IF EXISTS `appdb`@`localhost`', $sql);
        $this->assertSame(['localhost', '127.0.0.1', '203.0.113.5'], $result['hosts_after']);
    }

    public function test_postgres_writes_per_database_hba_and_reloads(): void
    {
        $rt = new FakeRuntime();
        $path = '/etc/postgresql/16/main/pg_hba.conf';
        $rt->files[$path] = "local all all peer\nhost all all 127.0.0.1/32 scram-sha-256\n";
        $rt->dirs['/etc/postgresql/16/main'] = true;

        $result = (new PostgreSQLAccess($rt))->apply('appdb', 'appdb', 'specific', ['203.0.113.5']);
        $hba = $rt->files[$path];
        $this->assertStringContainsString('host "appdb" "appdb" 203.0.113.5/32 scram-sha-256', $hba);
        $this->assertStringContainsString('AZERIOID-DB-ACCESS-BEGIN appdb', $hba);
        $this->assertSame(['/usr/bin/systemctl', 'reload', 'postgresql'], $rt->execLog[0]['command']);
        $this->assertSame($path, $result['hba_path']);

        (new PostgreSQLAccess($rt))->apply('otherdb', 'otherdb', 'specific', ['198.51.100.10']);
        $hba = $rt->files[$path];
        $this->assertStringContainsString('host "appdb" "appdb" 203.0.113.5/32 scram-sha-256', $hba);
        $this->assertStringContainsString('host "otherdb" "otherdb" 198.51.100.10/32 scram-sha-256', $hba);

        (new PostgreSQLAccess($rt))->apply('appdb', 'appdb', 'localhost', []);
        $hba = $rt->files[$path];
        $this->assertStringNotContainsString('host "appdb" "appdb"', $hba);
        $this->assertStringContainsString('host "otherdb" "otherdb"', $hba);
    }

    public function test_mongo_describe_is_instance_wide(): void
    {
        $access = DbAccessPolicy::describe(
            'mongodb',
            ['mode' => 'specific', 'ips' => ['203.0.113.5']],
            ['network' => 'specific', 'ips' => ['203.0.113.5', '198.51.100.10'], 'bind_public' => true]
        );
        $this->assertSame('instance_firewall', $access['enforcement']);
        $this->assertTrue($access['conflict']);
        $this->assertStringContainsString('no per-database', strtolower((string) $access['caveat']));
        $this->assertStringContainsString('27017', (string) $access['caveat']);
    }

    public function test_kernel_rejects_global_without_confirm_and_injection(): void
    {
        $rt = $this->mariadbRuntime();
        $cfg = $this->mariadbConfig();
        [$code, $json] = $this->capture(new Kernel($cfg, $rt), ['broker', 'db.access.set', 'appdb'], [
            'engine' => 'mariadb',
            'mode' => 'global',
        ]);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString('Confirmation', (string) $json['error']);

        $rt2 = $this->mariadbRuntime();
        [$code2, $json2] = $this->capture(new Kernel($cfg, $rt2), ['broker', 'db.access.set', 'appdb'], [
            'engine' => 'mariadb',
            'mode' => 'specific',
            'ips' => ['1.2.3.4; DROP TABLE x'],
        ]);
        $this->assertNotSame(0, $code2);
        $this->assertFalse($json2['ok']);
        $this->assertStringContainsString('Invalid IP', (string) $json2['error']);
        $joined = implode("\n", $rt2->dbExecLog);
        $this->assertStringNotContainsString('DROP TABLE', $joined);
        $this->assertStringNotContainsString('1.2.3.4;', $joined);
    }

    public function test_kernel_sets_specific_ip_and_audits(): void
    {
        $rt = $this->mariadbRuntime();
        $cfg = $this->mariadbConfig();
        [$code, $json] = $this->capture(new Kernel($cfg, $rt), ['broker', 'db.access.set', 'appdb'], [
            'engine' => 'mariadb',
            'mode' => 'specific',
            'ips' => ['203.0.113.5'],
        ]);
        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertSame('specific', $json['data']['after']['mode']);
        $this->assertSame(['203.0.113.5'], $json['data']['after']['ips']);
        $this->assertSame('engine_host', $json['data']['after']['enforcement']);
        $sql = implode("\n", $rt->dbExecLog);
        $this->assertStringContainsString('`appdb`@`203.0.113.5`', $sql);
        $flat = '';
        foreach ($rt->execLog as $row) {
            $flat .= implode(' ', $row['command']) . "\n";
        }
        $this->assertStringContainsString('/usr/sbin/ufw allow from 203.0.113.5', $flat);
        $audit = $rt->files[$cfg->auditLog] ?? '';
        $this->assertStringContainsString('db.access.set', $audit);
        $this->assertStringContainsString('203.0.113.5', $audit);
        $this->assertStringContainsString('"ok":true', $audit);
    }

    public function test_kernel_global_with_confirm_opens_firewall_any(): void
    {
        $rt = $this->mariadbRuntime();
        $cfg = $this->mariadbConfig();
        [$code, $json] = $this->capture(new Kernel($cfg, $rt), ['broker', 'db.access.set', 'appdb'], [
            'engine' => 'mariadb',
            'mode' => 'global',
            'confirm' => Validator::GLOBAL_ACCESS_CONFIRM,
        ]);
        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertSame('global', $json['data']['after']['mode']);
        $commands = array_map(static fn (array $row) => $row['command'], $rt->execLog);
        $this->assertContains(['/usr/sbin/ufw', 'allow', '3306/tcp', 'comment', 'azerioid-db-mariadb'], $commands);
        $sql = implode("\n", $rt->dbExecLog);
        $this->assertStringContainsString('`appdb`@`%`', $sql);
    }

    public function test_kernel_mongo_access_is_instance_firewall(): void
    {
        $rt = new FakeRuntime();
        $cfg = new Config();
        $cfg->mongodbUser = 'azerioid_panel_admin';
        $cfg->mongodbPassword = 'abcdefghijklmnopqrst';
        $rt->files['/etc/mongod.conf'] = "net:\n  bindIp: 127.0.0.1\n";
        $rt->files['/usr/sbin/ufw'] = '';
        $rt->script(['/usr/sbin/ufw', 'status'], 0, "Status: active\n");
        $rt->script(['/usr/bin/systemctl', 'restart', 'mongod'], 0, '');
        $eval = 'JSON.stringify(db.adminCommand({listDatabases:1}))';
        $rt->script([
            '/usr/bin/mongosh',
            '--quiet',
            '-u',
            'azerioid_panel_admin',
            '-p',
            'abcdefghijklmnopqrst',
            '--authenticationDatabase',
            'admin',
            '--eval',
            $eval,
        ], 0, json_encode([
            'databases' => [
                ['name' => 'shop', 'sizeOnDisk' => 4096],
                ['name' => 'admin', 'sizeOnDisk' => 1024],
            ],
        ]));

        [$code, $json] = $this->capture(new Kernel($cfg, $rt), ['broker', 'db.access.set', 'shop'], [
            'engine' => 'mongodb',
            'mode' => 'specific',
            'ips' => ['203.0.113.5'],
        ]);
        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertSame('instance_firewall', $json['data']['after']['enforcement']);
        $this->assertStringContainsString('27017', (string) $json['data']['after']['caveat']);
        $flat = '';
        foreach ($rt->execLog as $row) {
            $flat .= implode(' ', $row['command']) . "\n";
        }
        $this->assertStringContainsString('/usr/sbin/ufw allow from 203.0.113.5 to any port 27017', $flat);
        $this->assertStringContainsString('bindIp: 0.0.0.0', $rt->files['/etc/mongod.conf']);
    }

    private function mariadbConfig(): Config
    {
        $cfg = new Config();
        $cfg->mysqlPassword = 'abcdefghijklmnopqrst';
        $cfg->mysqlUser = 'azerioid_panel_admin';
        $cfg->databaseEngine = 'mariadb';

        return $cfg;
    }

    private function mariadbRuntime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/mysql/mariadb.conf.d/50-server.cnf'] = "[mysqld]\nbind-address = 127.0.0.1\n";
        $rt->files['/usr/sbin/ufw'] = '';
        $rt->script(['/usr/sbin/ufw', 'status'], 0, "Status: active\n");
        $rt->script(['/usr/bin/systemctl', 'restart', 'mariadb'], 0, '');
        $rt->dbQueryFn = static function (string $sql): array {
            if (str_contains($sql, 'SCHEMATA') && str_contains($sql, 'SCHEMA_NAME AS name')) {
                return [['name' => 'appdb', 'size_bytes' => 1, 'table_count' => 0]];
            }
            if (str_contains($sql, 'information_schema.SCHEMATA') && str_contains($sql, 'SCHEMA_NAME =')) {
                return [['SCHEMA_NAME' => 'appdb']];
            }
            if (str_contains($sql, 'FROM mysql.db')) {
                return [['Db' => 'appdb', 'User' => 'appdb', 'Host' => 'localhost']];
            }
            if (str_contains($sql, 'authentication_string')) {
                return [['plugin' => 'mysql_native_password', 'authentication_string' => '*HASHHASHHASHHASH']];
            }
            if (str_contains($sql, 'FROM mysql.user')) {
                return [['Host' => 'localhost'], ['Host' => '127.0.0.1']];
            }

            return [];
        };

        return $rt;
    }

    /** @return array{0:int,1:array} */
    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();
        $json = json_decode(trim((string) $out), true);

        return [$code, is_array($json) ? $json : []];
    }
}
