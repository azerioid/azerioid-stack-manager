<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Sftp\SftpManager;
use PHPUnit\Framework\TestCase;

/**
 * Per-vhost SFTP (A48).
 *
 * This is the most lockout-prone change in the roadmap, so the tests are mostly about what must
 * *not* happen: no edit to sshd_config, no reload of a configuration sshd rejected, no restart, and
 * no possibility of the administrator's own access being caught by the Match block.
 */
final class SftpManagerTest extends TestCase
{
    private function runtime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/ssh/sshd_config'] = "Port 22\nPermitRootLogin prohibit-password\nInclude /etc/ssh/sshd_config.d/*.conf\n";
        $rt->files['/usr/sbin/sshd'] = '';
        $rt->files['/lib/systemd/system/ssh.service'] = '';
        $rt->script(['/usr/bin/getent', 'group', SftpManager::GROUP], 0, SftpManager::GROUP . ":x:990:\n");
        $rt->script(['/usr/bin/getent', 'passwd', 'az-vh-shop-example-com'], 0, "az-vh-shop-example-com:x:1001:990::/data/www/shop.example.com:/bin/bash\n");
        $rt->script(['/usr/sbin/sshd', '-t'], 0, '');
        // FakeRuntime succeeds for any command it was not told about, so an absent account has to
        // be scripted explicitly — otherwise `getent passwd` "finds" every user and the refusal
        // below cannot be tested at all.
        $rt->script(['/usr/bin/getent', 'passwd', 'az-vh-missing-example-com'], 2, '');
        // fail2ban present, with the shipped templates where the installer puts them.
        $rt->dirs['/etc/fail2ban/jail.d'] = true;
        $rt->files['/usr/bin/fail2ban-client'] = '';
        $rt->files['/usr/local/lib/azerioid-panel/deploy/fail2ban/filter.d/azerioid-sftp.conf'] =
            "[Definition]\nfailregex = ^.*Connection closed by authenticating user az-vh-\\S+ <HOST>.*$\n";
        $rt->files['/usr/local/lib/azerioid-panel/deploy/fail2ban/jail.d/azerioid-sftp.conf'] =
            "[azerioid-sftp]\nenabled = true\nfilter = azerioid-sftp\nmaxretry = 3\n";

        return $rt;
    }

    /** @return array{0:int,1:array} */
    private function call(FakeRuntime $rt, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = (new Kernel(new Config(), $rt))->run(array_merge(['broker'], $argv), $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    /** @return list<array<int,string>> */
    private function commands(FakeRuntime $rt): array
    {
        return array_column($rt->execLog, 'command');
    }

    // ------------------------------------------------------------ what it writes

    public function test_configuration_goes_into_a_drop_in_and_never_into_sshd_config(): void
    {
        $rt = $this->runtime();
        $before = $rt->files['/etc/ssh/sshd_config'];

        [$code] = $this->call($rt, ['sftp.configure']);

        $this->assertSame(0, $code);
        $this->assertArrayHasKey(SftpManager::DROP_IN, $rt->files);
        $this->assertSame($before, $rt->files['/etc/ssh/sshd_config'], 'sshd_config must not be touched');
    }

    public function test_the_drop_in_sorts_after_the_distro_files(): void
    {
        // Match blocks are evaluated in file order; the panel's must win.
        $this->assertMatchesRegularExpression('#/70-#', SftpManager::DROP_IN);
    }

    public function test_the_block_matches_its_own_group_not_the_vhost_identity_group(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);
        $body = $rt->files[SftpManager::DROP_IN];

        $this->assertStringContainsString('Match Group azerioid-sftp', $body);
        // The A48 amendment: azerioid-vhosts has www-data and caddy as supplementary members, so
        // matching it would restrict the panel's own accounts.
        $this->assertStringNotContainsString('azerioid-vhosts', $body);
    }

    public function test_the_block_is_file_transfer_only_and_key_only(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);
        $body = $rt->files[SftpManager::DROP_IN];

        foreach ([
            'ForceCommand internal-sftp',
            'PermitTTY no',
            'PasswordAuthentication no',
            'PubkeyAuthentication yes',
            'AllowTcpForwarding no',
            'AllowAgentForwarding no',
            'PermitTunnel no',
        ] as $directive) {
            $this->assertStringContainsString($directive, $body, $directive);
        }
    }

    /** ChrootDirectory fights A25's ownership model; confinement is internal-sftp plus the home. */
    public function test_no_chroot_is_configured(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);

        $this->assertStringNotContainsString('ChrootDirectory', $rt->files[SftpManager::DROP_IN]);
    }

    public function test_the_file_is_not_group_writable(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);

        // sshd refuses a group-writable configuration file outright.
        $this->assertSame(0644, $rt->modes[SftpManager::DROP_IN] ?? 0644);
    }

    public function test_applying_twice_does_not_accumulate_match_blocks(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);
        $this->call($rt, ['sftp.configure']);

        $this->assertSame(1, substr_count($rt->files[SftpManager::DROP_IN], 'Match Group'));
    }

    // -------------------------------------------------------- validate then reload

    public function test_sshd_validates_before_the_reload(): void
    {
        $rt = $this->runtime();

        $this->call($rt, ['sftp.configure']);

        $commands = $this->commands($rt);
        $validateAt = null;
        $reloadAt = null;
        foreach ($commands as $i => $command) {
            if ($command === ['/usr/sbin/sshd', '-t']) {
                $validateAt = $i;
            }
            if (($command[1] ?? '') === 'reload') {
                $reloadAt = $i;
            }
        }
        $this->assertNotNull($validateAt, 'sshd -t must run');
        $this->assertNotNull($reloadAt, 'the service must be reloaded');
        $this->assertLessThan($reloadAt, $validateAt, 'validation must come first');
    }

    /**
     * The one that matters most: a restart with a broken configuration drops existing sessions and
     * then fails to come back, which is an outage with no way in.
     */
    public function test_sshd_is_never_restarted(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);
        $this->call($rt, ['sftp.enable', 'shop.example.com']);
        $this->call($rt, ['sftp.unconfigure']);

        foreach ($this->commands($rt) as $command) {
            $this->assertNotSame('restart', $command[1] ?? null, 'sshd must be reloaded, never restarted');
        }
    }

    public function test_a_rejected_configuration_is_removed_and_never_reloaded(): void
    {
        $rt = $this->runtime();
        $rt->script(['/usr/sbin/sshd', '-t'], 1, '', '/etc/ssh/sshd_config.d/70-azerioid-sftp.conf: line 9: Bad option');

        [$code, $json] = $this->call($rt, ['sftp.configure']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('nothing was applied', (string) $json['error']);
        $this->assertArrayNotHasKey(SftpManager::DROP_IN, $rt->files, 'a rejected file must not be left behind');
        foreach ($this->commands($rt) as $command) {
            $this->assertNotSame('reload', $command[1] ?? null, 'nothing may be reloaded after a rejection');
        }
    }

    public function test_a_rejected_change_restores_the_previous_drop_in(): void
    {
        $rt = $this->runtime();
        $rt->files[SftpManager::DROP_IN] = "# previously working\nMatch Group azerioid-sftp\n\tForceCommand internal-sftp\n";
        $rt->script(['/usr/sbin/sshd', '-t'], 1, '', 'Bad option');

        $this->call($rt, ['sftp.configure']);

        $this->assertStringContainsString('previously working', $rt->files[SftpManager::DROP_IN]);
    }

    public function test_a_host_without_the_include_line_is_refused_rather_than_edited(): void
    {
        $rt = $this->runtime();
        $rt->files['/etc/ssh/sshd_config'] = "Port 22\n";

        [$code, $json] = $this->call($rt, ['sftp.configure']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Refusing to edit sshd_config', (string) $json['error']);
        $this->assertArrayNotHasKey(SftpManager::DROP_IN, $rt->files);
    }

    public function test_validation_is_refused_when_sshd_is_missing(): void
    {
        $rt = $this->runtime();
        unset($rt->files['/usr/sbin/sshd']);

        [$code, $json] = $this->call($rt, ['sftp.configure']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('has not been checked', (string) $json['error']);
    }

    // ------------------------------------------------------------ who gets access

    public function test_enabling_a_site_adds_only_its_own_identity_to_the_group(): void
    {
        $rt = $this->runtime();

        [$code, $json] = $this->call($rt, ['sftp.enable', 'shop.example.com']);

        $this->assertSame(0, $code);
        $this->assertSame('az-vh-shop-example-com', $json['data']['user']);
        $this->assertContains(
            ['/usr/bin/gpasswd', '-a', 'az-vh-shop-example-com', SftpManager::GROUP],
            $this->commands($rt)
        );
    }

    public function test_disabling_removes_it_again(): void
    {
        $rt = $this->runtime();
        $rt->script(['/usr/bin/getent', 'group', SftpManager::GROUP], 0,
            SftpManager::GROUP . ":x:990:az-vh-shop-example-com\n");

        $this->call($rt, ['sftp.disable', 'shop.example.com']);

        $this->assertContains(
            ['/usr/bin/gpasswd', '-d', 'az-vh-shop-example-com', SftpManager::GROUP],
            $this->commands($rt)
        );
    }

    /** The administrator's own access must be untouchable through this path. */
    public function test_a_vhost_with_no_identity_is_refused(): void
    {
        $rt = $this->runtime();

        [$code, $json] = $this->call($rt, ['sftp.enable', 'missing.example.com']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('no system identity', (string) $json['error']);
    }

    public function test_the_group_is_created_if_absent(): void
    {
        $rt = $this->runtime();
        $rt->script(['/usr/bin/getent', 'group', SftpManager::GROUP], 2, '');

        $this->call($rt, ['sftp.configure']);

        $this->assertContains(
            ['/usr/sbin/groupadd', '--system', SftpManager::GROUP],
            $this->commands($rt)
        );
    }

    // ------------------------------------------------------------------- removal

    public function test_unconfiguring_removes_the_drop_in_and_reloads(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);

        [$code, $json] = $this->call($rt, ['sftp.unconfigure']);

        $this->assertSame(0, $code);
        $this->assertFalse($json['data']['configured']);
        $this->assertArrayNotHasKey(SftpManager::DROP_IN, $rt->files);
    }

    // ------------------------------------------------------------------ fail2ban

    /**
     * The stock sshd jail does not see these failures: the accounts are key-only, so a refused
     * attempt never logs "Failed password". Without a jail of its own, the account type most likely
     * to be probed is the one nothing watches.
     */
    public function test_configuring_installs_a_jail_and_filter(): void
    {
        $rt = $this->runtime();

        [$code, $json] = $this->call($rt, ['sftp.configure']);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['jail']['installed']);
        $this->assertArrayHasKey(SftpManager::JAIL, $rt->files);
        $this->assertArrayHasKey(SftpManager::FILTER, $rt->files);
        $this->assertContains(['/usr/bin/fail2ban-client', 'reload'], $this->commands($rt));
    }

    /** Scoped to site identities, so a mistake in the filter cannot ban an operator. */
    public function test_the_filter_only_matches_site_identities(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);

        $this->assertStringContainsString('az-vh-', $rt->files[SftpManager::FILTER]);
    }

    public function test_the_jail_is_installed_after_sshd_not_before(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);

        $reloadAt = null;
        $jailAt = null;
        foreach ($this->commands($rt) as $i => $command) {
            if (($command[0] ?? '') === '/usr/bin/systemctl' && ($command[1] ?? '') === 'reload') {
                $reloadAt = $i;
            }
            if (($command[0] ?? '') === '/usr/bin/fail2ban-client') {
                $jailAt = $i;
            }
        }
        // A jail watching a service that was not reconfigured is noise; if the sshd reload had
        // failed there would be nothing to watch.
        $this->assertNotNull($reloadAt);
        $this->assertNotNull($jailAt);
        $this->assertLessThan($jailAt, $reloadAt);
    }

    /** SFTP works without fail2ban; refusing to configure sshd over a monitoring package would not. */
    public function test_a_host_without_fail2ban_still_gets_sftp(): void
    {
        $rt = $this->runtime();
        unset($rt->dirs['/etc/fail2ban/jail.d']);

        [$code, $json] = $this->call($rt, ['sftp.configure']);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['configured']);
        $this->assertFalse($json['data']['jail']['installed']);
        $this->assertStringContainsString('not installed', (string) $json['data']['jail']['reason']);
    }

    /**
     * sshd is already configured by the time the jail is touched, so a jail that fails to load is
     * reported rather than thrown — undoing working SFTP to report a fail2ban problem is the wrong
     * trade.
     */
    public function test_a_jail_that_fails_to_load_is_reported_not_fatal(): void
    {
        $rt = $this->runtime();
        $rt->script(['/usr/bin/fail2ban-client', 'reload'], 1, '', 'Failed during configuration');

        [$code, $json] = $this->call($rt, ['sftp.configure']);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['configured']);
        $this->assertFalse($json['data']['jail']['reloaded']);
        $this->assertStringContainsString('Failed during configuration', (string) $json['data']['jail']['detail']);
    }

    public function test_removing_sftp_removes_the_jail_too(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);

        [$code, $json] = $this->call($rt, ['sftp.unconfigure']);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['jail']['removed']);
        $this->assertArrayNotHasKey(SftpManager::JAIL, $rt->files);
        $this->assertArrayNotHasKey(SftpManager::FILTER, $rt->files);
    }

    // --------------------------------------------------------------------- keys

    /** A real ed25519 public key, generated for this suite. */
    private function keyLine(string $comment = 'operator@workstation'): string
    {
        // 32-byte key, wrapped in the SSH wire format the parser checks against the type prefix.
        $type = 'ssh-ed25519';
        $blob = base64_encode(
            pack('N', strlen($type)) . $type . pack('N', 32) . str_repeat("\x2a", 32)
        );

        return $type . ' ' . $blob . ' ' . $comment;
    }

    /**
     * The whole reason keys do not live in the account's home: the account owns its home, so it
     * could install its own key and keep access after the hole that let it in was closed.
     */
    public function test_keys_live_outside_the_account_home_and_are_owned_by_root(): void
    {
        $rt = $this->runtime();

        $this->call($rt, ['sftp.key.add', 'shop.example.com'], ['key' => $this->keyLine()]);

        $path = SftpManager::KEY_DIR . '/az-vh-shop-example-com';
        $this->assertArrayHasKey($path, $rt->files);
        $this->assertStringStartsWith('/etc/ssh/', $path);
        $this->assertStringNotContainsString('/data/www', $path);
        $this->assertSame(['root', 'root'], $rt->owners[$path] ?? null);
        $this->assertSame(0644, $rt->modes[$path] ?? null, 'sshd refuses a group-writable key file');
    }

    public function test_the_drop_in_points_sshd_at_that_directory(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.configure']);

        $this->assertStringContainsString(
            'AuthorizedKeysFile ' . SftpManager::KEY_DIR . '/%u',
            $rt->files[SftpManager::DROP_IN]
        );
    }

    public function test_a_key_is_listed_by_fingerprint_and_never_echoed_back(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.key.add', 'shop.example.com'], ['key' => $this->keyLine()]);

        [$code, $json] = $this->call($rt, ['sftp.key.list', 'shop.example.com']);

        $this->assertSame(0, $code);
        $row = $json['data']['keys'][0];
        $this->assertStringStartsWith('SHA256:', $row['fingerprint']);
        $this->assertSame('ssh-ed25519', $row['type']);
        $this->assertSame('operator@workstation', $row['comment']);
        $this->assertArrayNotHasKey('blob', $row, 'key material must not be echoed into a response');
    }

    public function test_a_second_copy_of_the_same_key_is_refused(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['sftp.key.add', 'shop.example.com'], ['key' => $this->keyLine()]);

        [$code, $json] = $this->call($rt, ['sftp.key.add', 'shop.example.com'], ['key' => $this->keyLine('other')]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('already installed', (string) $json['error']);
    }

    public function test_a_key_can_be_removed_by_fingerprint(): void
    {
        $rt = $this->runtime();
        [, $added] = $this->call($rt, ['sftp.key.add', 'shop.example.com'], ['key' => $this->keyLine()]);
        $fingerprint = $added['data']['fingerprint'];

        [$code, $json] = $this->call($rt, ['sftp.key.del', 'shop.example.com', $fingerprint]);

        $this->assertSame(0, $code);
        $this->assertSame(0, $json['data']['keys']);
        $this->assertSame('', $rt->files[SftpManager::KEY_DIR . '/az-vh-shop-example-com']);
    }

    public function test_removing_a_key_that_is_not_there_is_reported(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['sftp.key.del', 'shop.example.com', 'SHA256:nope']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('No key with that fingerprint', (string) $json['error']);
    }

    /**
     * Options in an authorized_keys line change what the key can do — `command=` runs something,
     * `permitopen=` re-enables forwarding — which would undo the restrictions the Match block
     * exists to impose.
     */
    public function test_an_option_bearing_key_line_is_refused(): void
    {
        foreach ([
            'command="/bin/sh" ' . $this->keyLine(),
            'permitopen="127.0.0.1:5432" ' . $this->keyLine(),
            'no-pty,environment="X=1" ' . $this->keyLine(),
        ] as $line) {
            [$code] = $this->call($this->runtime(), ['sftp.key.add', 'shop.example.com'], ['key' => $line]);
            $this->assertNotSame(0, $code, $line);
        }
    }

    /** Pasting the wrong file is a mistake worth naming loudly rather than storing. */
    public function test_a_private_key_is_refused_with_a_warning(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['sftp.key.add', 'shop.example.com'], [
            'key' => '-----BEGIN OPENSSH PRIVATE KEY----- b3BlbnNzaA==',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('private', strtolower((string) $json['error']));
        $this->assertStringContainsString('compromised', (string) $json['error']);
    }

    public function test_a_key_whose_body_does_not_match_its_type_is_refused(): void
    {
        [$code] = $this->call($this->runtime(), ['sftp.key.add', 'shop.example.com'], [
            'key' => 'ssh-rsa ' . explode(' ', $this->keyLine())[1],
        ]);

        $this->assertNotSame(0, $code);
    }

    public function test_rubbish_is_refused(): void
    {
        foreach (['', 'not a key', 'ssh-ed25519 !!!not-base64!!!', 'ssh-ed25519'] as $line) {
            [$code] = $this->call($this->runtime(), ['sftp.key.add', 'shop.example.com'], ['key' => $line]);
            $this->assertNotSame(0, $code, json_encode($line));
        }
    }

    /**
     * A line the panel cannot parse is kept rather than dropped: it may be an operator's own, and
     * deleting someone's access to tidy a file is worse than showing a row we do not understand.
     */
    public function test_an_unparsable_existing_line_is_preserved(): void
    {
        $rt = $this->runtime();
        $path = SftpManager::KEY_DIR . '/az-vh-shop-example-com';
        $rt->files[$path] = "ssh-dss AAAAsomethingOld operator\n";

        $this->call($rt, ['sftp.key.add', 'shop.example.com'], ['key' => $this->keyLine()]);

        $this->assertStringContainsString('ssh-dss AAAAsomethingOld', $rt->files[$path]);
    }

    public function test_status_reports_without_changing_anything(): void
    {
        $rt = $this->runtime();

        [$code, $json] = $this->call($rt, ['sftp.status']);

        $this->assertSame(0, $code);
        $this->assertFalse($json['data']['configured']);
        $this->assertTrue($json['data']['include_present']);
        $this->assertSame('ssh', $json['data']['service'], 'Debian names the unit ssh');
        $this->assertArrayNotHasKey(SftpManager::DROP_IN, $rt->files);
    }
}
