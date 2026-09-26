<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Network\FirewallRevertWindow;
use PHPUnit\Framework\TestCase;

/**
 * B2 / request #2 — firewall management.
 *
 * A1/G2 fixed reporting; this adds writing. The tests that matter most are the
 * refusals: a firewall manager that will happily deny SSH is a way to lose a server,
 * and the refusal has to come from the broker, because the CLI and any other caller
 * reach the same action the UI does.
 */
final class FirewallManagerTest extends TestCase
{
    private const SSH_CONFIG = '/etc/ssh/sshd_config';

    private function runtime(string $backend = 'ufw'): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files[self::SSH_CONFIG] = "# managed by the distro\nPort 22\nPermitRootLogin yes\n";
        $rt->files['/usr/bin/systemd-run'] = '';
        $rt->files['/usr/bin/systemctl'] = '';

        if ($backend === 'ufw') {
            $rt->files['/usr/sbin/ufw'] = '';
            $rt->script(['/usr/sbin/ufw', 'status'], 0, "Status: active\n");
            $rt->script(['/usr/sbin/ufw', 'status', 'numbered'], 0, self::UFW_LISTING);
            $rt->files['/etc/ufw/user.rules'] = "*filter\n:ufw-user-input - [0:0]\n";
            $rt->files['/etc/ufw/user6.rules'] = "*filter6\n";
        } else {
            $rt->files['/usr/bin/firewall-cmd'] = '';
            $rt->script(['/usr/bin/firewall-cmd', '--state'], 0, "running\n");
            $rt->script(['/usr/bin/firewall-cmd', '--get-default-zone'], 0, "public\n");
            $rt->script(['/usr/bin/firewall-cmd', '--permanent', '--list-ports'], 0, "80/tcp 443/tcp 22/tcp\n");
            $rt->script(['/usr/bin/firewall-cmd', '--permanent', '--list-rich-rules'], 0,
                "rule family=\"ipv4\" source address=\"10.0.0.5\" port port=\"3306\" protocol=\"tcp\" accept\n");
            $rt->files['/etc/firewalld/zones/public.xml'] = "<?xml version=\"1.0\"?>\n<zone/>\n";
        }

        return $rt;
    }

    private const UFW_LISTING = <<<'TXT'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 22/tcp                     ALLOW IN    Anywhere
[ 2] 80/tcp                     ALLOW IN    Anywhere                   # azerioid-http
[ 3] 443/tcp                    ALLOW IN    Anywhere                   # azerioid-https
[ 4] 3306/tcp                   ALLOW IN    10.0.0.5                   # azerioid-db-mariadb
[ 5] 8080/tcp                   ALLOW IN    Anywhere                   # azerioid-staging
[ 6] 9000/tcp                   DENY IN     Anywhere
[ 7] 22/tcp (v6)                ALLOW IN    Anywhere (v6)
TXT;

    /** @return array{0:int,1:array} */
    private function call(FakeRuntime $rt, array $argv, array $stdin = [], ?Config $cfg = null): array
    {
        ob_start();
        $code = (new Kernel($cfg ?? new Config(), $rt))->run(array_merge(['broker'], $argv), $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    // ------------------------------------------------------------------ listing

    public function test_listing_reports_both_panel_written_and_operator_rules(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rules']);

        $this->assertSame(0, $code);
        $this->assertSame('ufw', $json['data']['backend']);
        $keys = array_map(
            static fn (array $r): string => $r['describe'] . ' [' . ($r['managed'] ? 'managed' : 'operator') . ']',
            $json['data']['rules']
        );
        $this->assertContains('allow 22/tcp from any [operator]', $keys);
        $this->assertContains('allow 80/tcp from any [managed]', $keys);
        $this->assertContains('allow 3306/tcp from 10.0.0.5 [managed]', $keys);
        $this->assertContains('deny 9000/tcp from any [operator]', $keys);
    }

    /**
     * ufw writes a v6 twin for every `ufw allow`. Reporting both would show two rules
     * where the operator made one, and make a successful deletion look half-done.
     */
    public function test_the_ipv6_twin_of_an_identical_rule_is_not_listed_twice(): void
    {
        [, $json] = $this->call($this->runtime(), ['firewall.rules']);

        $ssh = array_filter($json['data']['rules'], static fn (array $r): bool => $r['port'] === 22);
        $this->assertCount(1, $ssh);
    }

    public function test_listing_on_firewalld_reports_ports_and_rich_rules(): void
    {
        [$code, $json] = $this->call($this->runtime('firewalld'), ['firewall.rules']);

        $this->assertSame(0, $code);
        $this->assertSame('firewalld', $json['data']['backend']);
        $described = array_map(static fn (array $r): string => $r['describe'], $json['data']['rules']);
        $this->assertContains('allow 80/tcp from any', $described);
        $this->assertContains('allow 3306/tcp from 10.0.0.5', $described);
    }

    public function test_an_inactive_firewall_is_reported_rather_than_silently_accepting_rules(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/ssh/sshd_config'] = "Port 22\n";

        [$code, $json] = $this->call($rt, ['firewall.rules']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('No active firewall', (string) $json['error']);
    }

    // ------------------------------------------------------------- the refusals

    public function test_denying_ssh_is_refused(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rule.add'], [
            'action' => 'deny', 'port' => 22, 'protocol' => 'tcp',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('locks you out', (string) $json['error']);
    }

    /** An operator who moved SSH is the one a hardcoded 22 would lock out. */
    public function test_a_moved_ssh_port_is_protected_and_the_default_no_longer_is(): void
    {
        $rt = $this->runtime();
        $rt->files['/etc/ssh/sshd_config'] = "Port 2222\n";

        [$moved] = $this->call($rt, ['firewall.rule.add'], ['action' => 'deny', 'port' => 2222]);
        $this->assertNotSame(0, $moved, 'the port sshd actually listens on must be protected');

        [$old] = $this->call($rt, ['firewall.rule.add'], ['action' => 'deny', 'port' => 22]);
        $this->assertSame(0, $old, 'port 22 is not special once sshd has moved off it');
    }

    public function test_a_drop_in_sshd_config_is_read_too(): void
    {
        $rt = $this->runtime();
        $rt->files['/etc/ssh/sshd_config.d/99-hardening.conf'] = "Port 2022\n";

        [$code] = $this->call($rt, ['firewall.rule.add'], ['action' => 'deny', 'port' => 2022]);

        $this->assertNotSame(0, $code);
    }

    public function test_denying_the_panel_port_is_refused(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rule.add'], ['action' => 'deny', 'port' => 3169]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('panel', (string) $json['error']);
    }

    public function test_denying_the_serving_ports_is_refused(): void
    {
        foreach ([80, 443] as $port) {
            [$code, $json] = $this->call($this->runtime(), ['firewall.rule.add'], ['action' => 'deny', 'port' => $port]);
            $this->assertNotSame(0, $code, 'port ' . $port);
            $this->assertStringContainsString('offline', (string) $json['error']);
        }
    }

    /**
     * "Deny SSH from that one address" reads as narrow, but the panel cannot know the
     * operator is not behind it — and that is the case where the mistake cannot be
     * undone.
     */
    public function test_denying_a_protected_port_from_a_single_source_is_refused_too(): void
    {
        [$code] = $this->call($this->runtime(), ['firewall.rule.add'], [
            'action' => 'deny', 'port' => 22, 'source' => '203.0.113.9',
        ]);

        $this->assertNotSame(0, $code);
    }

    /** With default-deny, removing the allow closes the port just as effectively. */
    public function test_removing_the_rule_that_allows_ssh_is_refused(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rule.delete'], [
            'action' => 'allow', 'port' => 22,
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('closes the port', (string) $json['error']);
    }

    public function test_a_rule_owned_by_another_panel_feature_is_not_editable_here(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rule.delete'], [
            'action' => 'allow', 'port' => 3306, 'source' => '10.0.0.5',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('database remote access', (string) $json['error']);
    }

    public function test_a_hostname_source_is_refused(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rule.add'], [
            'action' => 'allow', 'port' => 8443, 'source' => 'office.example.com',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not a hostname', (string) $json['error']);
    }

    public function test_a_duplicate_rule_is_reported_rather_than_added_twice(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rule.add'], [
            'action' => 'allow', 'port' => 8080,
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('already exists', (string) $json['error']);
    }

    public function test_deleting_a_rule_that_is_not_there_is_reported(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['firewall.rule.delete'], [
            'action' => 'allow', 'port' => 5555,
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('No such rule', (string) $json['error']);
    }

    // ----------------------------------------------------------------- writing

    public function test_adding_a_rule_uses_a_specification_and_tags_it(): void
    {
        $rt = $this->runtime();

        [$code] = $this->call($rt, ['firewall.rule.add'], [
            'action' => 'allow', 'port' => 8443, 'protocol' => 'tcp', 'source' => '10.1.0.0/16', 'note' => 'office',
        ]);

        $this->assertSame(0, $code);
        $this->assertContains(
            ['/usr/sbin/ufw', 'allow', 'from', '10.1.0.0/16', 'to', 'any', 'port', '8443', 'proto', 'tcp',
                'comment', 'azerioid-office'],
            array_column($rt->execLog, 'command')
        );
    }

    /**
     * ufw's printed index shifts the moment anything else changes, so deleting by it
     * deletes whatever happens to be there when the command runs.
     */
    public function test_deletion_never_uses_the_printed_rule_number(): void
    {
        $rt = $this->runtime();

        $this->call($rt, ['firewall.rule.delete'], ['action' => 'allow', 'port' => 8080]);

        foreach (array_column($rt->execLog, 'command') as $command) {
            if (($command[1] ?? '') === 'delete') {
                $this->assertSame(
                    ['/usr/sbin/ufw', 'delete', 'allow', '8080/tcp'],
                    $command,
                    'deletion must name the rule, not its position'
                );
            }
        }
    }

    public function test_firewalld_writes_permanently_and_reloads(): void
    {
        $rt = $this->runtime('firewalld');

        [$code] = $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);

        $this->assertSame(0, $code);
        $commands = array_column($rt->execLog, 'command');
        $this->assertContains(['/usr/bin/firewall-cmd', '--permanent', '--add-port=8443/tcp'], $commands);
        $this->assertContains(['/usr/bin/firewall-cmd', '--reload'], $commands, 'a runtime-only rule vanishes on reload');
    }

    /** firewalld rich rules carry no comment, so ownership needs a sidecar. */
    public function test_firewalld_records_ownership_in_a_sidecar(): void
    {
        $rt = $this->runtime('firewalld');

        $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443, 'note' => 'office']);

        $sidecar = json_decode($rt->files['/var/lib/azerioid-panel/firewall-managed.json'] ?? '', true);
        $this->assertSame('azerioid-office', $sidecar['allow:8443:tcp:any'] ?? null);
    }

    public function test_a_denied_rule_on_firewalld_rejects_rather_than_drops(): void
    {
        $rt = $this->runtime('firewalld');

        $this->call($rt, ['firewall.rule.add'], ['action' => 'deny', 'port' => 9001]);

        $rich = array_values(array_filter(
            array_column($rt->execLog, 'command'),
            static fn (array $c): bool => str_contains((string) ($c[2] ?? ''), '--add-rich-rule')
        ));
        $this->assertStringContainsString(' reject', (string) $rich[0][2]);
    }

    // ------------------------------------------------------------ revert window

    public function test_a_change_arms_a_one_shot_timer_that_will_put_it_back(): void
    {
        $rt = $this->runtime();

        [$code, $json] = $this->call($rt, ['firewall.rule.add'], [
            'action' => 'allow', 'port' => 8443, 'revert_after' => 60,
        ]);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['revert']['armed']);
        $timer = array_values(array_filter(
            array_column($rt->execLog, 'command'),
            static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/systemd-run'
        ));
        $this->assertNotSame([], $timer, 'the window has to be enforced by something that outlives PHP');
        $this->assertContains('--on-active=60', $timer[0]);
        $this->assertContains('firewall.revert', $timer[0]);
    }

    public function test_the_snapshot_is_taken_before_the_change_is_applied(): void
    {
        $rt = $this->runtime();

        $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);

        $state = json_decode($rt->files['/var/lib/azerioid-panel/firewall-revert.json'] ?? '', true);
        $snapshot = json_decode((string) ($state['snapshot'] ?? ''), true);
        $this->assertSame("*filter\n:ufw-user-input - [0:0]\n", $snapshot['/etc/ufw/user.rules'] ?? null);
    }

    public function test_confirming_clears_the_window_and_stops_the_timer(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);

        [$code, $json] = $this->call($rt, ['firewall.confirm']);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['confirmed']);
        $this->assertArrayNotHasKey('/var/lib/azerioid-panel/firewall-revert.json', $rt->files);
        $this->assertContains(
            ['/usr/bin/systemctl', 'stop', FirewallRevertWindow::UNIT . '.timer'],
            array_column($rt->execLog, 'command')
        );
    }

    public function test_reverting_restores_the_snapshot_and_reloads(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);
        // Something else rewrote the rules in the meantime; the snapshot must win.
        $rt->files['/etc/ufw/user.rules'] = "*filter\n### tampered\n";

        [$code, $json] = $this->call($rt, ['firewall.revert']);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['reverted']);
        $this->assertSame("*filter\n:ufw-user-input - [0:0]\n", $rt->files['/etc/ufw/user.rules']);
        $this->assertContains(['/usr/sbin/ufw', 'reload'], array_column($rt->execLog, 'command'));
    }

    /** The timer runs as root with no stdin at all, so the action must need none. */
    public function test_revert_needs_no_input(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);

        [$code] = $this->call($rt, ['firewall.revert'], []);

        $this->assertSame(0, $code);
    }

    public function test_two_unconfirmed_changes_at_once_are_refused(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);

        [$code, $json] = $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8444]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('already waiting', (string) $json['error']);
    }

    public function test_nothing_to_confirm_or_revert_is_an_error_not_a_silent_success(): void
    {
        foreach (['firewall.confirm', 'firewall.revert'] as $action) {
            [$code] = $this->call($this->runtime(), [$action]);
            $this->assertNotSame(0, $code, $action);
        }
    }

    public function test_the_window_is_bounded(): void
    {
        foreach ([5, 4000] as $seconds) {
            [$code, $json] = $this->call($this->runtime(), ['firewall.rule.add'], [
                'action' => 'allow', 'port' => 8443, 'revert_after' => $seconds,
            ]);
            $this->assertNotSame(0, $code, (string) $seconds);
            $this->assertStringContainsString('between', (string) $json['error']);
        }
    }

    /**
     * Without systemd-run nothing can close the window, so the operator has to say
     * out loud that they can reach the machine another way.
     */
    public function test_without_systemd_run_the_change_needs_a_typed_confirmation(): void
    {
        $rt = $this->runtime();
        unset($rt->files['/usr/bin/systemd-run']);

        [$refused, $json] = $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);
        $this->assertNotSame(0, $refused);
        $this->assertStringContainsString('I-HAVE-CONSOLE-ACCESS', (string) $json['error']);

        [$allowed] = $this->call($rt, ['firewall.rule.add'], [
            'action' => 'allow', 'port' => 8443, 'confirm' => 'I-HAVE-CONSOLE-ACCESS',
        ]);
        $this->assertSame(0, $allowed);
    }

    public function test_opting_out_of_the_window_also_needs_the_typed_confirmation(): void
    {
        $rt = $this->runtime();

        [$refused] = $this->call($rt, ['firewall.rule.add'], [
            'action' => 'allow', 'port' => 8443, 'revert' => false,
        ]);
        $this->assertNotSame(0, $refused);

        [$allowed, $json] = $this->call($rt, ['firewall.rule.add'], [
            'action' => 'allow', 'port' => 8443, 'revert' => false, 'confirm' => 'I-HAVE-CONSOLE-ACCESS',
        ]);
        $this->assertSame(0, $allowed);
        $this->assertFalse($json['data']['revert']['armed']);
    }

    /**
     * A window nothing will close would block every later change with "already
     * waiting", and clearing it would mean editing a file by hand on a host the
     * operator may have just lost access to.
     */
    public function test_a_timer_that_cannot_be_armed_leaves_no_stale_window(): void
    {
        $rt = $this->runtime();
        $rt->execHook = static function (array $command): void {
            if (($command[0] ?? '') === '/usr/bin/systemd-run') {
                throw new \AzerioidPanel\Broker\BrokerException('systemd-run failed', 1);
            }
        };

        [$code] = $this->call($rt, ['firewall.rule.add'], ['action' => 'allow', 'port' => 8443]);

        $this->assertNotSame(0, $code);
        $this->assertArrayNotHasKey('/var/lib/azerioid-panel/firewall-revert.json', $rt->files);
    }

    public function test_a_cli_style_invocation_works(): void
    {
        $rt = $this->runtime();

        [$code, $json] = $this->call($rt, ['firewall.rule.add', 'allow', '8443/tcp', '10.0.0.7'], [
            'confirm' => 'I-HAVE-CONSOLE-ACCESS', 'revert' => false,
        ]);

        $this->assertSame(0, $code);
        $this->assertSame('allow 8443/tcp from 10.0.0.7', $json['data']['added']['describe']);
    }
}
