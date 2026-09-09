<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelDispatchTest extends TestCase
{
    private function kernel(FakeRuntime $rt): Kernel
    {
        $cfg = new Config();
        $cfg->databaseEngine = 'mariadb';
        $cfg->mysqlPassword = 'abcdefghijklmnopqrst';

        return new Kernel($cfg, $rt);
    }

    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();
        $json = json_decode(trim($out), true);
        return [$code, $json];
    }

    public function test_unknown_action_is_rejected(): void
    {
        [$code, $json] = $this->capture($this->kernel(new FakeRuntime()), ['broker', 'rm.rf']);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
        $this->assertNull($json['data']);
    }

    public function test_shell_metacharacters_in_action_rejected(): void
    {
        [$code, $json] = $this->capture($this->kernel(new FakeRuntime()), ['broker', 'status.all; id']);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
    }

    public function test_version_all_parses_fixtures(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/caddy', 'version'], 0, file_get_contents(__DIR__ . '/../fixtures/caddy-version.txt'));
        $rt->script(['/usr/bin/mariadb', '--version'], 0, file_get_contents(__DIR__ . '/../fixtures/mariadb-version.txt'));
        $rt->script(['/usr/bin/php', '-v'], 0, file_get_contents(__DIR__ . '/../fixtures/php-version.txt'));
        $rt->files['/usr/bin/caddy'] = '';
        $rt->files['/usr/bin/mariadb'] = '';

        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'version.all']);
        $this->assertSame(0, $code);
        $this->assertTrue($json['ok']);
        $this->assertSame('2.10.0', $json['data']['caddy']['version']);
        $this->assertSame('11.4.5', $json['data']['mariadb']['version']);
        $this->assertSame('8.4.5', $json['data']['php']['version']);
    }

    public function test_metrics_from_proc(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/proc/loadavg'] = "0.15 0.10 0.05 1/120 999\n";
        $rt->files['/proc/uptime'] = "12345.0 8888.0\n";
        $rt->files['/proc/meminfo'] = "MemTotal:        2048000 kB\nMemFree:          512000 kB\nMemAvailable:    1024000 kB\n";
        $rt->script(['/bin/df', '-B1', '-P'], 0, "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 20000000000 5000000000 15000000000 25% /\n");

        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'metrics.system']);
        $this->assertSame(0, $code);
        $this->assertEqualsWithDelta(0.15, $json['data']['loadavg']['1'], 0.001);
        $this->assertSame(2048000 * 1024, $json['data']['memory']['total']);
        $this->assertEquals(12345.0, $json['data']['uptime_seconds']);
        $this->assertSame('/', $json['data']['disks'][0]['mount']);
    }

    public function test_db_add_never_puts_password_on_argv(): void
    {
        $rt = new FakeRuntime();
        $rt->dbRows = [];
        [$code, $json] = $this->capture(
            $this->kernel($rt),
            ['broker', 'db.add', 'shopdb', 'shopuser'],
            ['password' => 'abcdefghijklmnopqrst']
        );
        $this->assertSame(0, $code);
        $this->assertTrue($json['ok']);
        $this->assertArrayNotHasKey('password', $json['data']);
        $sql = implode("\n", $rt->dbExecLog);
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', $sql);
    }

    public function test_sql_injection_in_db_name_rejected(): void
    {
        [$code, $json] = $this->capture(
            $this->kernel(new FakeRuntime()),
            ['broker', 'db.add', "shop'; DROP TABLE mysql.user;--", 'shop'],
            ['password' => 'abcdefghijklmnopqrst']
        );
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
    }

    public function test_service_control_allowlist(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/systemctl', 'restart', 'ssh'], 0);
        [$code] = $this->capture($this->kernel($rt), ['broker', 'service.restart', 'ssh']);
        $this->assertNotSame(0, $code);
        foreach ($rt->execLog as $entry) {
            $this->assertNotSame(['/usr/bin/systemctl', 'restart', 'ssh'], $entry['command']);
        }
    }

    public function test_service_restart_failure_includes_journal(): void
    {
        $rt = new FakeRuntime();
        $rt->installedPhp = ['8.2'];
        $rt->script(
            ['/usr/bin/systemctl', 'restart', 'php8.2-fpm'],
            1,
            '',
            "Job for php8.2-fpm.service failed because the control process exited with error code.\n"
        );
        $rt->script(
            ['/usr/bin/journalctl', '-xeu', 'php8.2-fpm', '-n', '80', '--no-pager'],
            0,
            "Aug 28 09:00:00 dream php-fpm8.2[1]: ERROR: unable to bind listening socket\n"
        );
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'service.restart', 'php8.2-fpm']);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString('journalctl -xeu php8.2-fpm', $json['error']);
        $this->assertStringContainsString('unable to bind listening socket', $json['error']);
    }

    public function test_status_all_attaches_journal_for_failed_units(): void
    {
        $rt = new FakeRuntime();
        $rt->installedPhp = ['8.2'];
        $show = [
            '/usr/bin/systemctl',
            'show',
            'php8.2-fpm',
            '--property=Id,ActiveState,SubState,MainPID,NRestarts,ActiveEnterTimestamp,UnitFileState,Description',
            '--no-pager',
        ];
        $rt->script($show, 0, "Id=php8.2-fpm.service\nActiveState=failed\nSubState=failed\nMainPID=0\nNRestarts=5\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription=The PHP 8.2 FastCGI Process Manager\n");
        $rt->script(
            ['/usr/bin/journalctl', '-xeu', 'php8.2-fpm', '-n', '40', '--no-pager'],
            0,
            "Aug 28 09:00:00 dream php-fpm8.2[1]: ERROR: unable to bind listening socket\n"
        );
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $failed = null;
        foreach ($json['data']['controlled'] as $row) {
            if ($row['unit'] === 'php8.2-fpm') {
                $failed = $row;
                break;
            }
        }
        $this->assertNotNull($failed);
        $this->assertSame('failed', $failed['active_state']);
        $this->assertStringContainsString('unable to bind listening socket', $failed['journal']);
        $this->assertSame([], $json['data']['observed']);
    }

    public function test_audit_log_redacts_password(): void
    {
        $rt = new FakeRuntime();
        $this->capture(
            $this->kernel($rt),
            ['broker', 'db.add', 'shopdb', 'shopuser'],
            ['password' => 'abcdefghijklmnopqrst']
        );
        $audit = $rt->files['/var/log/azerioid-panel/broker-audit.log'] ?? '';
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', $audit);
        $this->assertStringContainsString('[redacted]', $audit);
    }

    public function test_decode_stdin_ignores_accidental_non_json_pipes(): void
    {
        $this->assertSame([], Kernel::decodeStdinString("set -euo pipefail\necho hi\n"));
        $this->assertSame([], Kernel::decodeStdinString(''));
        $this->assertSame(['origin' => 'ui'], Kernel::decodeStdinString('{"origin":"ui"}'));
    }

    public function test_decode_stdin_rejects_broken_json_object(): void
    {
        $this->expectException(\AzerioidPanel\Broker\BrokerException::class);
        Kernel::decodeStdinString('{not json');
    }
}
