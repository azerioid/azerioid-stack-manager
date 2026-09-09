<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\ArchiveCrypto;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\SpacesClient;
use AzerioidPanel\Broker\Validator;
use PHPUnit\Framework\TestCase;

final class KernelPhase2Test extends TestCase
{
    private MemorySpacesTransport $spaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spaces = new MemorySpacesTransport();
        SpacesClient::$http = $this->spaces->handler();
    }

    protected function tearDown(): void
    {
        SpacesClient::$http = null;
        parent::tearDown();
    }

    private function kernel(FakeRuntime $rt, ?Config $cfg = null): Kernel
    {
        return new Kernel($cfg ?? new Config(), $rt);
    }

    /** @return array{0:int,1:array} */
    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();
        $json = json_decode(trim((string) $out), true);
        return [$code, $json];
    }

    /** @return array<string,mixed> */
    private function stdin(): array
    {
        return [
            'spaces' => [
                'endpoint' => 'https://fra1.digitaloceanspaces.com',
                'region' => 'fra1',
                'bucket' => 'azerioid-backups',
                'access_key' => 'DO00TESTKEY',
                'secret' => 'supersecretkeyvalue',
            ],
            'passphrase' => 'abcdefghijklmnopqrst',
        ];
    }

    public function test_hyphenated_action_names_are_valid(): void
    {
        $this->assertSame('system.reboot-required', Validator::action('system.reboot-required'));
    }

    public function test_reboot_requires_typed_confirm(): void
    {
        $rt = new FakeRuntime();
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'system.reboot']);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
        $this->assertSame([], $rt->execLog);

        [$code2] = $this->capture($this->kernel($rt), ['broker', 'system.reboot'], ['confirm' => 'REBOOT']);
        $this->assertSame(0, $code2);
        $this->assertSame(['/usr/sbin/reboot'], $rt->execLog[0]['command']);
    }

    public function test_scheduler_install_writes_cron_d(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/local/lib/azerioid-panel/web/artisan'] = "#!/usr/bin/env php\n";
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'scheduler.install']);
        $this->assertSame(0, $code);
        $body = $rt->files['/etc/cron.d/azerioid-panel'] ?? '';
        $this->assertStringContainsString('caddy /usr/bin/php /usr/local/lib/azerioid-panel/web/artisan schedule:run', $body);
        $this->assertSame('/etc/cron.d/azerioid-panel', $json['data']['path']);
    }

    public function test_updates_list_splits_security(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/lib/update-notifier/apt-check'] = '';
        $rt->script(['/usr/lib/update-notifier/apt-check'], 0, '', '12;3');
        $rt->script(
            ['/usr/bin/apt-get', '-s', '-o', 'Debug::NoLocking=true', 'upgrade'],
            0,
            "Inst openssl [3.0] (3.0.1 Ubuntu:24.04/noble-security)\nInst curl [8.5] (8.5.1 Ubuntu:24.04/noble-updates)\n"
        );
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'updates.list']);
        $this->assertSame(0, $code);
        $this->assertSame(12, $json['data']['total']);
        $this->assertSame(3, $json['data']['security']);
        $this->assertTrue($json['data']['packages'][0]['security']);
        $this->assertFalse($json['data']['packages'][1]['security']);
    }

    public function test_reboot_required_flag(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/var/run/reboot-required'] = '';
        $rt->files['/var/run/reboot-required.pkgs'] = "linux-image-6.8\n";
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'system.reboot-required']);
        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['required']);
        $this->assertSame(['linux-image-6.8'], $json['data']['packages']);
    }

    public function test_mariadb_bind_fix_backups_cnf(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/etc/mysql'] = true;
        $rt->dirs['/etc/mysql/mariadb.conf.d'] = true;
        $rt->files['/etc/mysql/mariadb.conf.d/50-server.cnf'] = "[mysqld]\nbind-address = 0.0.0.0\n";
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'mariadb.bind.fix']);
        $this->assertSame(0, $code);
        $backup = $json['data']['backup_path'];
        $this->assertStringContainsString('.lacmp-bak-', $backup);
        $this->assertSame("[mysqld]\nbind-address = 0.0.0.0\n", $rt->files[$backup]);
        $this->assertStringContainsString('bind-address = 127.0.0.1', $rt->files['/etc/mysql/mariadb.conf.d/50-server.cnf']);
    }

    public function test_mariadb_bind_fix_uses_el_cnf_path(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/etc/my.cnf.d'] = true;
        $rt->files['/etc/my.cnf.d/mariadb-server.cnf'] = "[mysqld]\nbind-address = 0.0.0.0\n";
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'mariadb.bind.fix']);
        $this->assertSame(0, $code);
        $this->assertSame('/etc/my.cnf.d/mariadb-server.cnf', $json['data']['config_path']);
        $this->assertStringContainsString('bind-address = 127.0.0.1', $rt->files['/etc/my.cnf.d/mariadb-server.cnf']);
    }

    public function test_mariadb_bind_fix_refuses_while_database_is_global(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/etc/my.cnf.d'] = true;
        $rt->files['/etc/my.cnf.d/mariadb-server.cnf'] = "[mysqld]\nbind-address = 0.0.0.0\n";
        $rt->files['/var/lib/azerioid-panel/db-access.json'] = json_encode([
            'mariadb' => [
                'ahh' => ['mode' => 'global', 'ips' => []],
                'vahh' => ['mode' => 'localhost', 'ips' => []],
            ],
        ], JSON_THROW_ON_ERROR);
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'mariadb.bind.fix']);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString("'ahh' is set to Global (any IP)", $json['error']);
        $this->assertStringContainsString("Change ahh's access mode to Localhost only first.", $json['error']);
        $this->assertStringContainsString('bind-address = 0.0.0.0', $rt->files['/etc/my.cnf.d/mariadb-server.cnf']);
    }

    public function test_archive_crypto_roundtrip(): void
    {
        $blob = ArchiveCrypto::encrypt('hello-backup', 'abcdefghijklmnopqrst');
        $this->assertStringStartsWith('LACMP1', $blob);
        $this->assertSame('hello-backup', ArchiveCrypto::decrypt($blob, 'abcdefghijklmnopqrst'));
    }

    public function test_backup_db_redacts_secrets_and_keeps_password_off_argv(): void
    {
        $rt = new FakeRuntime();
        $cfg = new Config();
        $cfg->mysqlPassword = 'db-secret-password-xx';
        $rt->script([
            '/usr/bin/mysqldump',
            '--defaults-extra-file=/var/lib/azerioid-panel/staging/mysqldump.cnf',
            '--protocol=socket',
            '--socket=/run/mysqld/mysqld.sock',
            '--single-transaction',
            '--quick',
            '--routines',
            '--skip-comments',
            '--all-databases',
        ], 0, '-- dump --');

        [$code, $json] = $this->capture($this->kernel($rt, $cfg), ['broker', 'backup.db', 'all'], $this->stdin());
        $this->assertSame(0, $code);
        $this->assertTrue($json['ok']);
        $this->assertStringStartsWith('azerioid/db/all/', $json['data']['key']);

        $argv = json_encode($rt->execLog, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('db-secret-password-xx', $argv);
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', $argv);
        $this->assertStringNotContainsString('supersecretkeyvalue', $argv);

        $audit = $rt->files['/var/log/azerioid-panel/broker-audit.log'] ?? '';
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', $audit);
        $this->assertStringNotContainsString('supersecretkeyvalue', $audit);
        $this->assertStringContainsString('[redacted]', $audit);
    }

    public function test_restore_db_into_new_name(): void
    {
        $plain = "CREATE TABLE t (id int);\n";
        $this->spaces->put('/azerioid-backups/azerioid/db/all/fixture.bin', ArchiveCrypto::encrypt($plain, 'abcdefghijklmnopqrst'));
        $rt = new FakeRuntime();
        $rt->dbRows = [];
        [$code, $json] = $this->capture(
            $this->kernel($rt),
            ['broker', 'backup.restore.db', 'azerioid/db/all/fixture.bin'],
            $this->stdin() + ['target' => 'projob_restore_1']
        );
        $this->assertSame(0, $code);
        $this->assertSame('projob_restore_1', $json['data']['target']);
        $this->assertFalse($json['data']['overwrite']);
        $sql = implode("\n", $rt->dbExecLog);
        $this->assertStringContainsString('CREATE DATABASE `projob_restore_1`', $sql);
    }

    public function test_restore_db_refuses_existing_without_overwrite(): void
    {
        $this->spaces->put('/azerioid-backups/azerioid/db/all/fixture.bin', ArchiveCrypto::encrypt('-- dump --', 'abcdefghijklmnopqrst'));
        $rt = new FakeRuntime();
        $rt->dbRows = [['Database' => 'projob']];
        [$code, $json] = $this->capture(
            $this->kernel($rt),
            ['broker', 'backup.restore.db', 'azerioid/db/all/fixture.bin'],
            $this->stdin() + ['target' => 'projob']
        );
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('OVERWRITE', $json['error']);
    }

    public function test_restore_files_refuses_projob_without_force(): void
    {
        $this->spaces->put('/azerioid-backups/azerioid/files/projob.az/fixture.bin', ArchiveCrypto::encrypt('tgz', 'abcdefghijklmnopqrst'));
        $rt = new FakeRuntime();
        $cfg = new Config();
        $cfg->readonlyVhosts = ['projob.az', 'www.projob.az'];
        [$code, $json] = $this->capture(
            $this->kernel($rt, $cfg),
            ['broker', 'backup.restore.files', 'azerioid/files/projob.az/fixture.bin'],
            $this->stdin() + ['site' => 'projob.az', 'apply' => true]
        );
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('read-only vhost', $json['error']);
        $moved = array_filter($rt->execLog, static fn ($row) => ($row['command'][0] ?? '') === '/bin/mv');
        $this->assertSame([], $moved);
    }

    public function test_restore_files_projob_requires_typed_force(): void
    {
        $this->spaces->put('/azerioid-backups/azerioid/files/projob.az/fixture.bin', ArchiveCrypto::encrypt('tgz', 'abcdefghijklmnopqrst'));
        $rt = new FakeRuntime();
        $rt->dirs['/data/www/projob.az'] = true;
        $rt->dirs['/var/lib/azerioid-panel/staging/restore-projob.az/projob.az'] = true;
        $cfg = new Config();
        $cfg->readonlyVhosts = ['projob.az', 'www.projob.az'];
        [$code] = $this->capture(
            $this->kernel($rt, $cfg),
            ['broker', 'backup.restore.files', 'azerioid/files/projob.az/fixture.bin'],
            $this->stdin() + ['site' => 'projob.az', 'apply' => true, 'force' => true, 'confirm' => 'PROJOB.AZ']
        );
        $this->assertSame(0, $code);
    }

    public function test_auth_audit_parses_sshd_lines(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/var/log/auth.log'] = "Aug 28 07:00:01 dream sshd[1]: Accepted publickey for root from 203.0.113.9 port 22 ssh2\n"
            . "Aug 28 07:01:01 dream sshd[2]: Failed password for invalid user admin from 198.51.100.7 port 22 ssh2\n"
            . "Aug 28 07:02:01 dream sshd[3]: Accepted publickey for root from 192.0.2.4 port 22 ssh2\n";
        $rt->script(['/usr/bin/tail', '-n', '400', '/var/log/auth.log'], 0, $rt->files['/var/log/auth.log']);
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'auth.audit']);
        $this->assertSame(0, $code);
        $this->assertSame(1, $json['data']['failed_count']);
        $this->assertNotSame([], $json['data']['new_root_ips']);
        $this->assertSame('file', $json['data']['source']);
    }

    public function test_auth_audit_uses_el_secure_log(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/var/log/secure'] = "Sep 9 12:00:01 host sshd[9]: Failed password for root from 198.51.100.9 port 22 ssh2\n";
        $rt->script(['/usr/bin/tail', '-n', '400', '/var/log/secure'], 0, $rt->files['/var/log/secure']);
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'auth.audit']);
        $this->assertSame(0, $code);
        $this->assertSame('/var/log/secure', $json['data']['path']);
        $this->assertSame(1, $json['data']['failed_count']);
    }

    public function test_auth_audit_falls_back_to_journalctl(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/bin/journalctl'] = "binary\n";
        $journal = "2026-09-09T12:00:00+00:00 host sshd[1]: Accepted publickey for root from 203.0.113.9 port 22 ssh2\n"
            . "2026-09-09T12:01:00+00:00 host sshd[2]: Failed password for invalid user admin from 198.51.100.7 port 22 ssh2\n";
        $rt->script([
            '/usr/bin/journalctl', '-u', 'ssh', '-u', 'sshd', '-n', '400', '--no-pager', '-o', 'short-iso',
        ], 0, $journal);
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'auth.audit']);
        $this->assertSame(0, $code);
        $this->assertFalse($json['data']['missing']);
        $this->assertSame('journal', $json['data']['source']);
        $this->assertSame(1, $json['data']['failed_count']);
    }

    public function test_logs_search_uses_fixed_string_grep(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/var/log/caddy/access.log'] = "GET / 200\n";
        $rt->script(['/usr/bin/grep', '-F', '-n', '-m', '200', '--', 'GET', '/var/log/caddy/access.log'], 0, "1:GET / 200\n");
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'logs.search', 'caddy', 'GET']);
        $this->assertSame(0, $code);
        $this->assertSame(['1:GET / 200'], $json['data']['lines']);
    }

    public function test_cron_rejects_command_substitution(): void
    {
        $rt = new FakeRuntime();
        [$code] = $this->capture($this->kernel($rt), ['broker', 'cron.set'], [
            'lines' => ['0 3 * * * /usr/bin/id $(whoami)'],
        ]);
        $this->assertNotSame(0, $code);
    }
}

final class MemorySpacesTransport
{
    /** @var array<string,string> */
    public array $objects = [];

    public function put(string $path, string $body): void
    {
        $this->objects[$path] = $body;
    }

    public function handler(): \Closure
    {
        return function (string $method, string $url, array $headers, string $body): array {
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            if ($method === 'PUT') {
                $this->objects[$path] = $body;
                return ['status' => 200, 'body' => '', 'headers' => ['etag' => '"x"']];
            }
            if ($method === 'DELETE') {
                unset($this->objects[$path]);
                return ['status' => 204, 'body' => '', 'headers' => []];
            }
            if ($method === 'GET' && isset($this->objects[$path])) {
                return ['status' => 200, 'body' => $this->objects[$path], 'headers' => []];
            }
            $xml = '<ListBucketResult>';
            foreach ($this->objects as $p => $b) {
                $key = preg_replace('#^/[^/]+/#', '', $p) ?? $p;
                $xml .= '<Contents><Key>'.htmlspecialchars((string) $key, ENT_XML1)
                    .'</Key><Size>'.strlen($b).'</Size><LastModified>2026-08-28T00:00:00Z</LastModified></Contents>';
            }
            $xml .= '</ListBucketResult>';
            return ['status' => 200, 'body' => $xml, 'headers' => []];
        };
    }
}
