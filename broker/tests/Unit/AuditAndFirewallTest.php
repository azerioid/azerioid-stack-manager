<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\AuditLog;
use AzerioidPanel\Broker\Actions\FirewallStatus;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\PosixRuntime;
use AzerioidPanel\Broker\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * A1 — R6 (audit records were silently lost) and G2 (firewalld hosts showed no
 * firewall at all).
 */
final class AuditAndFirewallTest extends TestCase
{
    // ------------------------------------------------------------------ R6

    /**
     * The old implementation read the whole log and rewrote it. Because the read
     * was unlocked, two concurrent callers could each write $existing . $line and
     * drop the other's record. This pins the mechanism, not just the outcome: the
     * runtime refuses both readFile and writeFile on the audit path.
     */
    public function test_audit_appends_and_never_rewrites_the_log(): void
    {
        $cfg = new Config();
        $written = [];

        // Mechanism assertion, not just outcome: readFile and writeFile must never
        // be touched for the audit path. The old implementation read the whole log
        // and rewrote it, and because the read was unlocked two concurrent callers
        // could each write $existing . $line and drop the other's record.
        $rt = $this->createMock(Runtime::class);
        $rt->method('now')->willReturn('2026-09-26T00:00:00+00:00');
        $rt->method('getuid')->willReturn(0);
        $rt->method('isDir')->willReturn(true);
        $rt->expects($this->never())->method('readFile');
        $rt->expects($this->never())->method('writeFile');
        $rt->expects($this->exactly(2))
            ->method('appendFile')
            ->willReturnCallback(function (string $path, string $body) use (&$written, $cfg): void {
                $this->assertSame($cfg->auditLog, $path);
                $written[] = $body;
            });

        $log = new AuditLog($cfg, $rt);
        $log->write('vhost.add', ['domain' => 'a.test'], true, 0, null);
        $log->write('vhost.del', ['domain' => 'b.test'], false, 3, 'refused');

        $this->assertCount(2, $written, 'both records must be appended');
        $this->assertStringContainsString('vhost.add', $written[0]);
        $this->assertStringContainsString('vhost.del', $written[1]);
        $this->assertStringEndsWith("\n", $written[0], 'each record is one newline-terminated line');
    }

    public function test_audit_still_redacts_secrets(): void
    {
        $rt = new FakeRuntime();
        $cfg = new Config();
        $rt->dirs[dirname($cfg->auditLog)] = true;

        (new AuditLog($cfg, $rt))->write('db.add', [
            'password' => 'super-secret-value',
            'passphrase' => 'another-secret-value',
            'name' => 'shop',
        ], true, 0, null);

        $body = $rt->files[$cfg->auditLog];
        $this->assertStringNotContainsString('super-secret-value', $body);
        $this->assertStringNotContainsString('another-secret-value', $body);
        $this->assertStringContainsString('[redacted]', $body);
        $this->assertStringContainsString('shop', $body);
    }

    /** Real filesystem semantics: appending must not truncate what is already there. */
    public function test_posix_append_preserves_existing_content(): void
    {
        $rt = new PosixRuntime();
        $path = sys_get_temp_dir() . '/az-audit-' . bin2hex(random_bytes(4)) . '.log';
        try {
            $rt->appendFile($path, "first\n", 0640);
            $rt->appendFile($path, "second\n", 0640);
            $rt->appendFile($path, "third\n", 0640);

            $this->assertSame("first\nsecond\nthird\n", file_get_contents($path));
            $this->assertSame(0640, fileperms($path) & 0777, 'mode is set on creation');
        } finally {
            @unlink($path);
        }
    }

    /**
     * Interleaved appends from separate processes must all land. This is the
     * property the lock exists for, so it is worth proving rather than assuming.
     */
    public function test_concurrent_appends_do_not_lose_records(): void
    {
        $php = PHP_BINARY;
        $path = sys_get_temp_dir() . '/az-audit-concurrent-' . bin2hex(random_bytes(4)) . '.log';
        $src = dirname(__DIR__, 2) . '/broker/src';
        if (!is_dir($src)) {
            $src = dirname(__DIR__, 2) . '/src';
        }
        $script = <<<PHP
<?php
spl_autoload_register(function (\$c) {
    \$n = substr(\$c, strrpos(\$c, '\\\\') + 1);
    foreach (['{$src}/' . \$n . '.php'] as \$p) {
        if (file_exists(\$p)) { require \$p; return; }
    }
});
\$rt = new AzerioidPanel\\Broker\\PosixRuntime();
\$tag = \$argv[1];
for (\$i = 0; \$i < 50; \$i++) {
    \$rt->appendFile('{$path}', \$tag . '-' . \$i . "\\n", 0640);
}
PHP;
        $scriptPath = sys_get_temp_dir() . '/az-audit-writer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($scriptPath, $script);

        try {
            $procs = [];
            foreach (['a', 'b', 'c'] as $tag) {
                $procs[] = proc_open(
                    [$php, $scriptPath, $tag],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
            }
            foreach ($procs as $p) {
                if (is_resource($p)) {
                    proc_close($p);
                }
            }

            $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($path))));
            $this->assertCount(150, $lines, 'no record from any writer may be lost');
            foreach (['a', 'b', 'c'] as $tag) {
                $this->assertCount(
                    50,
                    array_filter($lines, static fn ($l) => str_starts_with($l, $tag . '-')),
                    "writer {$tag} lost records"
                );
            }
        } finally {
            @unlink($path);
            @unlink($scriptPath);
        }
    }

    public function test_append_refuses_a_missing_directory(): void
    {
        $rt = new PosixRuntime();
        $this->expectException(BrokerException::class);
        $rt->appendFile('/nonexistent-dir-' . bin2hex(random_bytes(4)) . '/x.log', "x\n");
    }

    // ------------------------------------------------------------------ G2

    private function firewall(FakeRuntime $rt): array
    {
        return (new FirewallStatus())->handle('firewall.status', [], [], $rt, new Config());
    }

    public function test_reports_ufw_when_it_is_active(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/sbin/ufw'] = '';
        $rt->script(['/usr/sbin/ufw', 'status', 'verbose'], 0, "Status: active\nTo  Action  From\n80 ALLOW Anywhere\n");

        $out = $this->firewall($rt);

        $this->assertSame('ufw', $out['backend']);
        $this->assertTrue($out['active']);
        $this->assertTrue($out['ufw']['active']);
        $this->assertFalse($out['firewalld']['installed']);
    }

    /** The regression: an EL host reported nothing while firewalld was running. */
    public function test_reports_firewalld_on_a_host_without_ufw(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/bin/firewall-cmd'] = '';
        $rt->script(['/usr/bin/firewall-cmd', '--state'], 0, "running\n");
        $rt->script(['/usr/bin/firewall-cmd', '--get-default-zone'], 0, "public\n");
        $rt->script(['/usr/bin/firewall-cmd', '--list-ports'], 0, "3306/tcp 5432/tcp\n");
        $rt->script(['/usr/bin/firewall-cmd', '--list-services'], 0, "http https ssh\n");
        $rt->script(['/usr/bin/firewall-cmd', '--list-rich-rules'], 0, "rule family=ipv4 source address=203.0.113.9 port port=3306 protocol=tcp accept\n");
        $rt->script(['/usr/bin/firewall-cmd', '--list-all'], 0, "public (active)\n  ports: 3306/tcp\n");

        $out = $this->firewall($rt);

        $this->assertSame('firewalld', $out['backend']);
        $this->assertTrue($out['active']);
        $this->assertSame('public', $out['firewalld']['default_zone']);
        $this->assertSame(['3306/tcp', '5432/tcp'], $out['firewalld']['ports']);
        $this->assertSame(['http', 'https', 'ssh'], $out['firewalld']['services']);
        $this->assertCount(1, $out['firewalld']['rich_rules']);
        $this->assertNotSame('', $out['firewalld']['status'], 'the UI renders this like ufw status');
    }

    public function test_reports_none_when_no_firewall_is_present(): void
    {
        $out = $this->firewall(new FakeRuntime());

        $this->assertSame('none', $out['backend']);
        $this->assertFalse($out['active']);
        $this->assertFalse($out['ufw']['installed']);
        $this->assertFalse($out['firewalld']['installed']);
    }

    public function test_installed_but_stopped_firewalld_is_not_active(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/bin/firewall-cmd'] = '';
        $rt->script(['/usr/bin/firewall-cmd', '--state'], 252, '', "not running\n");

        $out = $this->firewall($rt);

        $this->assertTrue($out['firewalld']['installed']);
        $this->assertFalse($out['firewalld']['active']);
        $this->assertSame('none', $out['backend']);
    }

    public function test_installed_but_inactive_ufw_is_not_active(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/sbin/ufw'] = '';
        $rt->script(['/usr/sbin/ufw', 'status', 'verbose'], 0, "Status: inactive\n");

        $out = $this->firewall($rt);

        $this->assertTrue($out['ufw']['installed']);
        $this->assertFalse($out['ufw']['active']);
        $this->assertSame('none', $out['backend']);
    }

    /** ufw wins when both run, matching what the broker's own writers do. */
    public function test_ufw_takes_precedence_when_both_are_active(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/sbin/ufw'] = '';
        $rt->files['/usr/bin/firewall-cmd'] = '';
        $rt->script(['/usr/sbin/ufw', 'status', 'verbose'], 0, "Status: active\n");
        $rt->script(['/usr/bin/firewall-cmd', '--state'], 0, "running\n");
        $rt->defaultExec = new ExecResult(['firewall-cmd'], 0, '', '');

        $out = $this->firewall($rt);

        $this->assertSame('ufw', $out['backend']);
        $this->assertTrue($out['firewalld']['active'], 'still reported, just not the write target');
    }

    public function test_fail2ban_jails_are_still_parsed(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/bin/fail2ban-client'] = '';
        $rt->script(['/usr/bin/fail2ban-client', 'status'], 0, "Status\n|- Jail list:\tsshd, azerioid-panel\n");
        $rt->script(['/usr/bin/fail2ban-client', 'status', 'sshd'], 0, "Status for the jail: sshd\n   `- Banned IP list:\t198.51.100.7\n");
        $rt->script(['/usr/bin/fail2ban-client', 'status', 'azerioid-panel'], 0, "Status for the jail: azerioid-panel\n   `- Banned IP list:\t\n");

        $out = $this->firewall($rt);

        $this->assertTrue($out['fail2ban']['installed']);
        $this->assertCount(2, $out['fail2ban']['jails']);
        $this->assertSame(['198.51.100.7'], $out['fail2ban']['jails'][0]['banned']);
        $this->assertSame([], $out['fail2ban']['jails'][1]['banned']);
    }
}
