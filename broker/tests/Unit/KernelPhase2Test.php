<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\ArchiveCrypto;
use AzerioidPanel\Broker\Backup\ArchiveCipher;
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
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n";
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
        $this->assertSame('apt', $json['data']['pkg_mgr']);
        $this->assertSame('os-packages', $json['data']['scope']);
        $this->assertTrue($json['data']['packages'][0]['security']);
        $this->assertFalse($json['data']['packages'][1]['security']);
    }

    public function test_updates_list_uses_dnf_on_el(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=almalinux\nVERSION_ID=\"9.8\"\n";
        $rt->files['/usr/bin/dnf'] = "binary\n";
        $rt->script(['/usr/bin/dnf', 'check-update', '--quiet'], 100,
            "openssl.x86_64\t1:3.0.7-1.el9\tbaseos\ncurl.x86_64\t7.76.1-1.el9\tbaseos\n");
        $rt->script(['/usr/bin/dnf', 'updateinfo', 'list', 'security', '--quiet'], 0,
            "RHSA-2024:1000 Important/Sec. openssl-1:3.0.7-1.el9.x86_64\n");
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'updates.list']);
        $this->assertSame(0, $code);
        $this->assertSame('dnf', $json['data']['pkg_mgr']);
        $this->assertSame(2, $json['data']['total']);
        $this->assertGreaterThanOrEqual(1, $json['data']['security']);
        $this->assertSame('os-packages', $json['data']['scope']);
    }

    public function test_local_backup_db_writes_encrypted_file_and_prunes(): void
    {
        $rt = new FakeRuntime();
        $cfg = new Config();
        $cfg->mysqlPassword = 'db-secret-password-xx';
        $cfg->localBackupDir = '/var/lib/azerioid-panel/backups';
        $cfg->stagingDir = '/var/lib/azerioid-panel/staging';
        $rt->dirs[$cfg->localBackupDir] = true;
        $rt->dirs[$cfg->stagingDir] = true;
        // Seed older backups so prune keep=2 deletes extras.
        $oldDir = $cfg->localBackupDir . '/db/all';
        $rt->dirs[$oldDir] = true;
        $rt->files[$oldDir . '/20260101T000000Z.bin'] = 'old1';
        $rt->files[$oldDir . '/20260102T000000Z.bin'] = 'old2';
        $rt->files[$oldDir . '/20260103T000000Z.bin'] = 'old3';
        $rt->defaultExec = new \AzerioidPanel\Broker\ExecResult(
            ['/usr/bin/mysqldump'],
            0,
            '-- dump --',
            ''
        );

        [$code, $json] = $this->capture($this->kernel($rt, $cfg), ['broker', 'backup.db', 'all'], [
            'destination' => 'local',
            'passphrase' => 'abcdefghijklmnopqrst',
            'keep' => 2,
        ]);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertTrue($json['ok']);
        $this->assertSame('local', $json['data']['destination']);
        $this->assertTrue($json['data']['encrypted']);
        $key = $json['data']['key'];
        $this->assertStringStartsWith($cfg->localBackupDir . '/db/all/', $key);
        $this->assertArrayHasKey($key, $rt->files);
        $this->assertNotSame('-- dump --', $rt->files[$key]);
        // keep=2 retains newest + this run; older pruned
        $this->assertContains($oldDir . '/20260101T000000Z.bin', $json['data']['pruned']);
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
        $rt->defaultExec = new \AzerioidPanel\Broker\ExecResult(
            ['/usr/bin/mysqldump'],
            0,
            '-- dump --',
            ''
        );

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

    // ------------------------------- A2.3: new backups are LACMP2, end to end

    public function test_new_backups_are_written_in_the_lacmp2_format(): void
    {
        $rt = $this->scriptedDump("-- dump --\n");
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'backup.db', 'all'], $this->stdin());

        $this->assertSame(0, $code);
        $this->assertSame('lacmp2', $json['data']['format']);
        $this->assertSame('pbkdf2', $json['data']['kdf'], 'portable default (A2.2)');
        $this->assertMysqldumpShape($rt);

        $stored = $this->spaces->objects['/azerioid-backups/' . $json['data']['key']] ?? '';
        $this->assertStringStartsWith('LACMP2', $stored);
        $this->assertStringStartsNotWith('LACMP1', $stored);
    }

    public function test_backup_then_restore_round_trips_through_the_broker(): void
    {
        $dump = "CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n";
        $rt = $this->scriptedDump($dump);
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'backup.db', 'all'], $this->stdin());
        $this->assertSame(0, $code);
        $key = $json['data']['key'];

        // Restore the object the backup just produced.
        $rt2 = new FakeRuntime();
        $rt2->dbRows = [];
        [$code2, $json2] = $this->capture(
            $this->kernel($rt2),
            ['broker', 'backup.restore.db', $key],
            $this->stdin() + ['target' => 'restored_db']
        );

        $this->assertSame(0, $code2, (string) ($json2['error'] ?? ''));
        $this->assertSame('lacmp2', $json2['format']['format'] ?? $json2['data']['format'] ?? null);
        $mysql = array_values(array_filter(
            $rt2->execLog,
            static fn ($row) => ($row['command'][0] ?? '') === '/usr/bin/mysql'
        ));
        $this->assertNotSame([], $mysql, 'the dump must reach mysql');
        $this->assertSame($dump, $mysql[0]['stdin'], 'restored plaintext must match the original dump');
    }

    public function test_a_tampered_lacmp2_backup_is_refused_on_restore(): void
    {
        $rt = $this->scriptedDump("-- dump --\n");
        [, $json] = $this->capture($this->kernel($rt), ['broker', 'backup.db', 'all'], $this->stdin());
        $path = '/azerioid-backups/' . $json['data']['key'];

        // Flip a byte inside the ciphertext.
        $blob = $this->spaces->objects[$path];
        $at = 60;
        $blob[$at] = chr(ord($blob[$at]) ^ 0x01);
        $this->spaces->objects[$path] = $blob;

        $rt2 = new FakeRuntime();
        $rt2->dbRows = [];
        [$code2, $json2] = $this->capture(
            $this->kernel($rt2),
            ['broker', 'backup.restore.db', $json['data']['key']],
            $this->stdin() + ['target' => 'restored_db']
        );

        $this->assertNotSame(0, $code2);
        $this->assertStringContainsString('authentication', (string) $json2['error']);
        $mysql = array_filter($rt2->execLog, static fn ($row) => ($row['command'][0] ?? '') === '/usr/bin/mysql');
        $this->assertSame([], $mysql, 'nothing may reach mysql when authentication fails');
    }

    public function test_a_failing_dump_aborts_the_upload_instead_of_storing_a_partial(): void
    {
        $rt = new FakeRuntime();
        $rt->defaultExec = new \AzerioidPanel\Broker\ExecResult(['mysqldump'], 2, '', 'mysqldump: connection failed');

        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'backup.db', 'all'], $this->stdin());

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('connection failed', (string) $json['error']);
        $this->assertSame(1, $this->spaces->multipartAborted, 'the in-flight upload must be abandoned');
        $this->assertSame(0, $this->spaces->multipartCompleted);
        $this->assertSame([], $this->spaces->objects, 'no partial object may be left behind');
    }

    /**
     * The engine writes a randomly named credentials file per run, so exact-argv
     * scripting is no longer possible. Supply the dump as the default response and
     * assert the command *shape* separately via assertMysqldumpShape().
     */
    private function scriptedDump(string $dump): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->defaultExec = new \AzerioidPanel\Broker\ExecResult(['/usr/bin/mysqldump'], 0, $dump, '');

        return $rt;
    }

    private function assertMysqldumpShape(FakeRuntime $rt): void
    {
        $dumps = array_values(array_filter(
            $rt->execLog,
            static fn ($row) => ($row['command'][0] ?? '') === '/usr/bin/mysqldump'
        ));
        $this->assertNotSame([], $dumps, 'mysqldump must be invoked');
        $argv = $dumps[0]['command'];
        $this->assertSame('--protocol=socket', $argv[2] ?? null);
        $this->assertContains('--single-transaction', $argv);
        $this->assertContains('--all-databases', $argv);
        $this->assertMatchesRegularExpression(
            '#^--defaults-extra-file=/var/lib/azerioid-panel/staging/mysql-[0-9a-f]{12}\.cnf$#',
            $argv[1] ?? '',
            'credentials must come from a per-run file, never argv'
        );
    }

    public function test_restore_db_into_new_name(): void
    {
        $plain = "CREATE TABLE t (id int);\n";
        $this->spaces->put('/azerioid-backups/azerioid/db/all/fixture.bin', ArchiveCipher::encryptBlob($plain, 'abcdefghijklmnopqrst'));
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
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `projob_restore_1`', $sql);
    }

    public function test_restore_db_refuses_unauthenticated_legacy_archive(): void
    {
        // A47: a legacy LACMP1 (unauthenticated AES-CBC) DB archive could be
        // IV-flipped by a bucket writer into a `\!` shell escape for the root mysql
        // client. Live restore must refuse it (deep verify already does).
        $this->spaces->put('/azerioid-backups/azerioid/db/all/legacy.bin', ArchiveCrypto::encrypt("CREATE TABLE t (id int);\n", 'abcdefghijklmnopqrst'));
        $rt = new FakeRuntime();
        [$code, $json] = $this->capture(
            $this->kernel($rt),
            ['broker', 'backup.restore.db', 'azerioid/db/all/legacy.bin'],
            $this->stdin() + ['target' => 'legacy_restore']
        );
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('legacy', strtolower((string) ($json['error'] ?? '')));

        // ...unless an operator explicitly opts in for a trusted old archive.
        [$code2] = $this->capture(
            $this->kernel($rt),
            ['broker', 'backup.restore.db', 'azerioid/db/all/legacy.bin'],
            $this->stdin() + ['target' => 'legacy_restore', 'allow_legacy_unauthenticated' => true]
        );
        $this->assertSame(0, $code2);
    }

    public function test_restore_db_refuses_existing_without_overwrite(): void
    {
        $this->spaces->put('/azerioid-backups/azerioid/db/all/fixture.bin', ArchiveCipher::encryptBlob('-- dump --', 'abcdefghijklmnopqrst'));
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
        $this->spaces->put('/azerioid-backups/azerioid/files/projob.az/fixture.bin', ArchiveCipher::encryptBlob(self::tgzFixture(), 'abcdefghijklmnopqrst'));
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
        $this->spaces->put('/azerioid-backups/azerioid/files/projob.az/fixture.bin', ArchiveCipher::encryptBlob(self::tgzFixture(), 'abcdefghijklmnopqrst'));
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

    /** A real gzipped ustar archive, so restore's archive inspection is exercised. */
    private static function tgzFixture(string $site = 'projob.az'): string
    {
        $put = static fn (string $b, int $o, string $v): string => substr_replace($b, $v, $o, strlen($v));
        $entry = static function (string $name, string $body, string $type = '0', int $mode = 0644) use ($put): string {
            $h = str_repeat("\0", 512);
            $h = $put($h, 0, substr($name, 0, 100));
            $h = $put($h, 100, sprintf('%07o', $mode) . "\0");
            $h = $put($h, 108, sprintf('%07o', 0) . "\0");
            $h = $put($h, 116, sprintf('%07o', 0) . "\0");
            $h = $put($h, 124, sprintf('%011o', strlen($body)) . "\0");
            $h = $put($h, 136, sprintf('%011o', 1790000000) . "\0");
            $h = $put($h, 156, $type);
            $h = $put($h, 257, "ustar\0" . '00');
            $h = $put($h, 148, str_repeat(' ', 8));
            $sum = 0;
            for ($i = 0; $i < 512; $i++) {
                $sum += ord($h[$i]);
            }
            $h = $put($h, 148, sprintf('%06o', $sum) . "\0 ");
            if ($body !== '') {
                $h .= str_pad($body, (int) (ceil(strlen($body) / 512) * 512), "\0");
            }

            return $h;
        };
        $tar = $entry($site, '', '5', 0755)
            . $entry($site . '/index.php', "<?php\n")
            . str_repeat("\0", 1024);

        return (string) gzencode($tar);
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
        // Config default (and panel Caddyfile) use access_azerioid-panel.log.
        $path = '/var/log/caddy/access_azerioid-panel.log';
        $rt->files[$path] = "GET / 200\n";
        $rt->script(['/usr/bin/grep', '-F', '-n', '-m', '200', '--', 'GET', $path], 0, "1:GET / 200\n");
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'logs.search', 'caddy', 'GET']);
        $this->assertSame(0, $code);
        $this->assertSame($path, $json['data']['path']);
        $this->assertSame(['1:GET / 200'], $json['data']['lines']);
    }

    public function test_cron_rejects_command_substitution(): void
    {
        $rt = new FakeRuntime();
        [$code] = $this->capture($this->kernel($rt), ['broker', 'cron.set'], [
            'lines' => ['0 3 * * * /usr/bin/id $(whoami)'],
            'confirm' => 'UPDATE-ROOT-CRON',
        ]);
        $this->assertNotSame(0, $code);
    }

    public function test_cron_set_requires_confirm(): void
    {
        $rt = new FakeRuntime();
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'cron.set'], [
            'lines' => ['0 3 * * * /usr/bin/true'],
        ]);
        $this->assertNotSame(0, $code);
        $this->assertIsArray($json);
        $this->assertStringContainsString('Confirmation phrase did not match', (string) ($json['error'] ?? ''));
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

    /** @var array<string, list<string>> uploadId => ordered parts */
    public array $multipart = [];

    public int $multipartCompleted = 0;

    public int $multipartAborted = 0;

    /** @var list<array{method:string,url:string}> every call that reached the wire */
    public array $requests = [];

    public function handler(): \Closure
    {
        return function (string $method, string $url, array $headers, string $body): array {
            $this->requests[] = ['method' => $method, 'url' => $url];
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $query = [];
            parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);

            // --- S3 multipart upload, as used by SpacesArchiveSink ---
            if ($method === 'POST' && array_key_exists('uploads', $query)) {
                $uploadId = 'upload-' . (count($this->multipart) + 1);
                $this->multipart[$uploadId] = [];
                return [
                    'status' => 200,
                    'body' => '<InitiateMultipartUploadResult><UploadId>' . $uploadId . '</UploadId></InitiateMultipartUploadResult>',
                    'headers' => [],
                ];
            }
            if ($method === 'PUT' && isset($query['uploadId'], $query['partNumber'])) {
                $uploadId = (string) $query['uploadId'];
                if (!isset($this->multipart[$uploadId])) {
                    return ['status' => 404, 'body' => '<Error><Code>NoSuchUpload</Code></Error>', 'headers' => []];
                }
                $this->multipart[$uploadId][(int) $query['partNumber']] = $body;
                return ['status' => 200, 'body' => '', 'headers' => ['etag' => '"part-' . $query['partNumber'] . '"']];
            }
            if ($method === 'POST' && isset($query['uploadId'])) {
                $uploadId = (string) $query['uploadId'];
                if (!isset($this->multipart[$uploadId])) {
                    return ['status' => 404, 'body' => '<Error><Code>NoSuchUpload</Code></Error>', 'headers' => []];
                }
                // Assemble in part order, exactly as S3 does.
                $parts = $this->multipart[$uploadId];
                ksort($parts);
                $this->objects[$path] = implode('', $parts);
                unset($this->multipart[$uploadId]);
                $this->multipartCompleted++;
                return [
                    'status' => 200,
                    'body' => '<CompleteMultipartUploadResult><ETag>"done"</ETag></CompleteMultipartUploadResult>',
                    'headers' => [],
                ];
            }
            if ($method === 'DELETE' && isset($query['uploadId'])) {
                unset($this->multipart[(string) $query['uploadId']]);
                $this->multipartAborted++;
                return ['status' => 204, 'body' => '', 'headers' => []];
            }

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
