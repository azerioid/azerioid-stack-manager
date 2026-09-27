<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Panel\PanelHardener;
use AzerioidPanel\Broker\Panel\PanelIdentityMigrator as M;
use AzerioidPanel\Broker\Panel\PanelSudoers;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use PHPUnit\Framework\TestCase;

/**
 * ADR A39 Part A. The migration moves the panel off the web server's account and
 * off the distro php-fpm master. What these tests pin is the part that must never
 * go wrong on a real host: the end state is exactly the dedicated one, and a
 * failure anywhere puts every file back and never leaves the panel without a
 * broker grant.
 */
final class PanelIdentityMigratorTest extends TestCase
{
    private const DISTRO_POOL = '/etc/php/8.4/fpm/pool.d/azerioid-panel.conf';

    private const DISTRO_INI = '/etc/php/8.4/fpm/php.ini';

    private const QUEUE = '/etc/systemd/system/azerioid-panel-queue.service';

    private const BROKER = '/usr/local/lib/azerioid-panel/broker';

    private FakeRuntime $rt;

    private Config $config;

    private bool $accountExists = false;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->config = new Config();
        $this->config->panelRoot = '/usr/local/lib/azerioid-panel';
        $this->config->panelPhpVersion = '8.4';
        $this->config->panelFpmUnit = 'php8.4-fpm';
        $this->config->panelFpmSocket = '/run/php/azerioid-panel.sock';
        $this->config->panelPort = 3169;
        $this->config->webUser = 'caddy';
        $this->config->panelUser = 'caddy';
        $this->config->brokerConfigPath = '/etc/azerioid-panel/broker.json';
        $this->config->cronDPath = '/etc/cron.d/azerioid-panel';
    }

    /** A Part-B apt host: panel on caddy, pool on the distro master, proc_open loosened. */
    private function seedAptHost(): void
    {
        $this->rt->files[self::DISTRO_POOL] = "[azerioid-panel]\nuser = caddy\ngroup = caddy\nlisten = /run/php/azerioid-panel.sock\n"
            . "listen.owner = caddy\nlisten.group = caddy\nlisten.mode = 0660\npm = ondemand\n";
        $this->rt->files['/etc/php/8.4/fpm/pool.d/www.conf'] = "[www]\nuser = www-data\ngroup = www-data\n";
        $this->rt->files[self::DISTRO_INI] = "[PHP]\nmemory_limit = 256M\ndisable_functions = pcntl_fork\n";
        $this->rt->files[self::DISTRO_INI . '.azerioid-panel.bak'] = "[PHP]\nmemory_limit = 256M\ndisable_functions = pcntl_fork,proc_open,proc_get_status\n";
        $this->rt->files['/usr/sbin/php-fpm8.4'] = '';
        $this->rt->files['/etc/sudoers.d/azerioid-panel'] =
            "Defaults:caddy !requiretty\ncaddy ALL=(root) NOPASSWD: " . self::BROKER . "\n";
        $this->rt->files[self::QUEUE] = "[Service]\nUser=caddy\nGroup=caddy\nExecStart=/usr/bin/php artisan queue:work\n";
        $this->rt->files['/etc/cron.d/azerioid-panel'] = "SHELL=/bin/sh\n* * * * * caddy /usr/bin/php /usr/local/lib/azerioid-panel/web/artisan schedule:run\n";
        $this->rt->files['/etc/systemd/system/php8.4-fpm.service.d/azerioid-panel.conf'] = "[Service]\nReadWritePaths=/usr/local/lib/azerioid-panel/web/storage\n";
        $this->rt->files['/etc/tmpfiles.d/azerioid-panel.conf'] = "d /run/php 0755 caddy caddy -\n";
        $this->rt->files['/etc/azerioid-panel/broker.json'] = json_encode([
            'web_user' => 'caddy',
            'panel_runtime' => ['fpm_unit' => 'php8.4-fpm', 'fpm_socket' => '/run/php/azerioid-panel.sock'],
        ]);
        $this->rt->files['/etc/azerioid-panel/runtime.json'] = json_encode(['fpm_unit' => 'php8.4-fpm']);
        $this->rt->files['/run/php/azerioid-panel.sock'] = '';
        $this->rt->dirs['/etc/azerioid-panel'] = true;
        $this->rt->dirs['/usr/local/lib/azerioid-panel/web/public'] = true;

        $this->rt->script(['/usr/bin/systemctl', 'show', 'caddy', '-p', 'User', '--value'], 0, "caddy\n");
        $this->rt->script(['/usr/bin/pgrep', '-f', 'panel\.update\.apply'], 1, '');
        $this->rt->script(['/usr/bin/systemctl', 'is-active', M::CONVERGE_UNIT . '.service'], 3, "inactive\n");
        $this->rt->execHook = function (array $command): void {
            if ($command[0] === '/usr/sbin/useradd') {
                $this->accountExists = true;
            }
            if ($command[0] === '/usr/sbin/userdel') {
                $this->accountExists = false;
            }
            $this->rt->script(['/usr/bin/id', '-u', M::USER], $this->accountExists ? 0 : 1, $this->accountExists ? "998\n" : '');
        };
        $this->rt->script(['/usr/bin/id', '-u', M::USER], 1, '');
    }

    /** Everything verify() asks of a healthy host after the cut-over. */
    private function scriptHealthyCutOver(string $httpCode = '200'): void
    {
        $this->rt->script(['/usr/bin/systemctl', 'is-active', M::UNIT . '.service'], 0, "active\n");
        $this->rt->script(['/usr/bin/stat', '-c', '%U:%G', '/run/php/azerioid-panel.sock'], 0, M::USER . ":caddy\n");
        $this->rt->script(['/usr/sbin/runuser', '-u', M::USER, '--', '/usr/bin/sudo', '-n', self::BROKER, 'version.all'], 0, '{"ok":true,"data":{}}');
        $this->rt->script(
            ['/usr/bin/curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '10', 'http://127.0.0.1:3169/login'],
            0,
            $httpCode
        );
        $this->rt->script(['/usr/bin/systemctl', 'is-active', 'azerioid-panel-queue.service'], 0, "active\n");
    }

    private function migrator(): M
    {
        return new M($this->rt, $this->config, 0);
    }

    private function ran(array $command): bool
    {
        foreach ($this->rt->execLog as $entry) {
            if ($entry['command'] === $command) {
                return true;
            }
        }

        return false;
    }

    public function test_part_b_host_needs_migration_and_is_eligible(): void
    {
        $this->seedAptHost();
        $status = $this->migrator()->status();

        $this->assertTrue($status['needs_migration']);
        $this->assertTrue($status['auto_eligible']);
        $this->assertSame('caddy', $status['panel_pool_user']);
        $this->assertStringStartsWith('PENDING', $status['verdict']);
    }

    public function test_apply_requires_the_typed_confirm(): void
    {
        $this->seedAptHost();
        $this->expectException(BrokerException::class);
        $this->migrator()->apply('yes');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->seedAptHost();
        $before = $this->rt->files;

        $out = $this->migrator()->apply('MIGRATE-PANEL-IDENTITY', true);

        $this->assertTrue($out['dry_run']);
        $this->assertSame('caddy', $out['from_user']);
        $this->assertNotEmpty($out['steps']);
        $this->assertSame($before, $this->rt->files);
        $this->assertFalse($this->ran(['/usr/sbin/useradd', '--system', '--user-group', '--home-dir', '/var/lib/azerioid-panel', '--no-create-home', '--shell', '/usr/sbin/nologin', '--comment', 'AZERIOID panel', M::USER]));
    }

    public function test_apt_host_reaches_the_dedicated_end_state(): void
    {
        $this->seedAptHost();
        $this->scriptHealthyCutOver();

        $out = $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');

        $this->assertTrue($out['changed']);
        $this->assertTrue($this->accountExists, 'the dedicated account is created');

        // The panel pool left the distro master and runs as the new account;
        // Caddy reaches the socket through its group, not by being the panel.
        $this->assertArrayNotHasKey(self::DISTRO_POOL, $this->rt->files);
        $pool = $this->rt->files[M::POOL];
        $this->assertMatchesRegularExpression('/^user = azerioid-panel$/m', $pool);
        $this->assertMatchesRegularExpression('/^group = azerioid-panel$/m', $pool);
        $this->assertMatchesRegularExpression('/^listen\.owner = azerioid-panel$/m', $pool);
        $this->assertMatchesRegularExpression('/^listen\.group = caddy$/m', $pool);

        // Own master, own php.ini, which still lets the panel spawn sudo.
        $unit = $this->rt->files[M::UNIT_FILE];
        $this->assertStringContainsString('ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize -c /etc/azerioid-panel/php.ini --fpm-config /etc/azerioid-panel/php-fpm.conf', $unit);
        $this->assertStringContainsString('disable_functions = pcntl_fork', $this->rt->files[M::INI]);
        $this->assertStringNotContainsString('proc_open', $this->rt->files[M::INI]);
        $this->assertStringContainsString('include=/etc/azerioid-panel/php-fpm.d/*.conf', $this->rt->files[M::MASTER_CONF]);

        // Site pools get back the operator's own disable_functions.
        $this->assertStringContainsString('disable_functions = pcntl_fork,proc_open,proc_get_status', $this->rt->files[self::DISTRO_INI]);

        // Only the new account holds the grant.
        $sudoers = $this->rt->files['/etc/sudoers.d/azerioid-panel'];
        $this->assertStringContainsString('azerioid-panel ALL=(root) NOPASSWD: ' . self::BROKER, $sudoers);
        $this->assertStringNotContainsString('caddy ALL=', $sudoers);

        $this->assertMatchesRegularExpression('/^User=azerioid-panel$/m', $this->rt->files[self::QUEUE]);
        $this->assertStringContainsString('* * * * * azerioid-panel /usr/bin/php', $this->rt->files['/etc/cron.d/azerioid-panel']);
        $this->assertSame("d /run/php 0755 root root -\n", $this->rt->files['/etc/tmpfiles.d/azerioid-panel.conf']);
        $this->assertArrayNotHasKey('/etc/systemd/system/php8.4-fpm.service.d/azerioid-panel.conf', $this->rt->files);

        $broker = json_decode($this->rt->files['/etc/azerioid-panel/broker.json'], true);
        $this->assertSame(M::USER, $broker['panel_user']);
        $this->assertSame('caddy', $broker['web_user'], 'the web server identity is not the panel identity');
        $this->assertSame(M::UNIT, $broker['panel_runtime']['fpm_unit']);

        $this->assertTrue($this->ran(['/bin/chown', '-R', 'azerioid-panel:azerioid-panel', '/usr/local/lib/azerioid-panel/web']));
        $this->assertTrue($this->ran(['/bin/chown', 'azerioid-panel:caddy', '/usr/local/lib/azerioid-panel/web']));
        $this->assertTrue($this->ran(['/bin/chmod', '-R', 'o+rX', '/usr/local/lib/azerioid-panel/web/public']));
        $this->assertTrue($this->ran(['/usr/bin/systemctl', 'enable', M::UNIT . '.service']));

        $state = json_decode($this->rt->files[M::STATE_FILE], true);
        $this->assertSame('succeeded', $state['result']);
    }

    public function test_sudoers_is_additive_until_verified(): void
    {
        $this->seedAptHost();
        $this->scriptHealthyCutOver();
        $seen = [];
        $hook = $this->rt->execHook;
        $this->rt->execHook = function (array $command, ?string $stdin) use (&$seen, $hook): void {
            $hook($command, $stdin);
            // Sample the live grant at the moment the new identity's broker call is proven.
            if (($command[4] ?? null) === '/usr/bin/sudo') {
                $seen[] = $this->rt->files['/etc/sudoers.d/azerioid-panel'];
            }
        };

        $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');

        $this->assertCount(1, $seen);
        $this->assertStringContainsString('caddy ALL=(root)', $seen[0], 'old identity keeps its grant while the new one is verified');
        $this->assertStringContainsString('azerioid-panel ALL=(root)', $seen[0]);
    }

    public function test_failed_http_check_rolls_everything_back(): void
    {
        $this->seedAptHost();
        $this->scriptHealthyCutOver('502');
        $before = $this->rt->files;

        try {
            $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');
            $this->fail('a 502 through Caddy must fail the migration');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
            $this->assertStringContainsString('still running as caddy', $e->getMessage());
        }

        foreach ($before as $path => $body) {
            $this->assertArrayHasKey($path, $this->rt->files, $path . ' was removed and not restored');
            $this->assertSame($body, $this->rt->files[$path], $path . ' was not restored');
        }
        foreach ([M::UNIT_FILE, M::POOL, M::INI, M::MASTER_CONF] as $created) {
            $this->assertArrayNotHasKey($created, $this->rt->files, $created . ' should be gone after rollback');
        }
        $this->assertFalse($this->accountExists, 'the account created for the attempt is removed');
        $this->assertTrue($this->ran(['/usr/bin/systemctl', 'disable', '--now', M::UNIT . '.service']));
        $this->assertTrue($this->ran(['/usr/bin/systemctl', 'restart', 'php8.4-fpm']));

        $state = json_decode($this->rt->files[M::STATE_FILE], true);
        $this->assertSame('failed', $state['result']);
        $this->assertStringContainsString('502', $state['error']);
    }

    public function test_a_failure_blocks_automatic_retries(): void
    {
        $this->seedAptHost();
        $this->rt->files[M::STATE_FILE] = json_encode(['result' => 'failed', 'error' => 'boom']);

        $status = $this->migrator()->status();
        $this->assertFalse($status['auto_eligible']);
        $this->assertStringStartsWith('FAILED', $status['verdict']);

        $out = $this->migrator()->converge(false);
        $this->assertFalse($out['started']);
        $this->assertFalse($this->ran(['/usr/bin/systemd-run', '--unit=' . M::CONVERGE_UNIT, '--collect', '--description=AZERIOID panel identity migration (ADR A39)', self::BROKER, 'panel.identity.converge', 'now']));
    }

    public function test_an_interrupted_attempt_waits_for_an_operator(): void
    {
        $this->seedAptHost();
        $this->rt->files[M::STATE_FILE] = json_encode(['result' => 'running']);

        $status = $this->migrator()->status();
        $this->assertTrue($status['interrupted']);
        $this->assertFalse($status['auto_eligible']);
        $this->assertFalse($this->migrator()->converge(false)['started']);
    }

    public function test_converge_hands_off_to_its_own_unit(): void
    {
        $this->seedAptHost();
        $before = $this->rt->files;

        $out = $this->migrator()->converge(false);

        $this->assertTrue($out['started']);
        $this->assertTrue($this->ran(['/usr/bin/systemd-run', '--unit=' . M::CONVERGE_UNIT, '--collect', '--description=AZERIOID panel identity migration (ADR A39)', self::BROKER, 'panel.identity.converge', 'now']));
        $this->assertSame($before, $this->rt->files, 'the caller changes nothing itself');
    }

    public function test_operator_apply_refuses_while_the_automatic_run_is_active(): void
    {
        $this->seedAptHost();
        $this->rt->script(['/usr/bin/systemctl', 'is-active', M::CONVERGE_UNIT . '.service'], 0, "active\n");

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/automatic identity migration is running/');
        $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');
    }

    public function test_converge_waits_for_a_running_self_update(): void
    {
        $this->seedAptHost();
        $this->rt->script(['/usr/bin/pgrep', '-f', 'panel\.update\.apply'], 0, "4242\n");

        $out = $this->migrator()->converge(false);

        $this->assertFalse($out['started']);
        $this->assertStringContainsString('self-update', $out['reason']);
    }

    public function test_migrated_host_is_left_alone(): void
    {
        $this->seedAptHost();
        $this->scriptHealthyCutOver();
        $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');
        $this->config->panelUser = M::USER;
        $this->config->panelFpmUnit = M::UNIT;
        $this->rt->execLog = [];

        $status = $this->migrator()->status();
        $this->assertTrue($status['migrated'], json_encode($status['checks']));
        $this->assertFalse($this->migrator()->converge(false)['started']);
        $this->assertTrue($this->migrator()->apply('MIGRATE-PANEL-IDENTITY')['already_migrated']);
    }

    public function test_el_host_keeps_its_selinux_safe_binary(): void
    {
        $this->seedAptHost();
        unset($this->rt->files[self::DISTRO_POOL], $this->rt->files[self::DISTRO_INI], $this->rt->files[self::DISTRO_INI . '.azerioid-panel.bak']);
        $this->rt->files[M::POOL] = "[azerioid-panel]\nuser = caddy\ngroup = caddy\nlisten.owner = caddy\nlisten.group = caddy\n";
        $this->rt->files[M::UNIT_FILE] = "[Service]\nExecStart=/usr/local/lib/azerioid-panel/sbin/php-fpm --nodaemonize --fpm-config /etc/azerioid-panel/php-fpm.conf\n";
        $this->rt->files['/usr/local/lib/azerioid-panel/sbin/php-fpm'] = '';
        $this->rt->files['/etc/php.ini'] = "disable_functions =\n";
        $this->config->panelFpmUnit = M::UNIT;
        $this->rt->script(['/usr/bin/systemctl', 'cat', 'php8.4-fpm.service'], 1, '');
        $this->rt->script(['/usr/bin/systemctl', 'cat', 'php84-php-fpm.service'], 1, '');
        $this->rt->script(['/usr/bin/systemctl', 'cat', 'php-fpm.service'], 0, '');
        $this->scriptHealthyCutOver();

        $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');

        $this->assertStringContainsString(
            'ExecStart=/usr/local/lib/azerioid-panel/sbin/php-fpm --nodaemonize -c /etc/azerioid-panel/php.ini',
            $this->rt->files[M::UNIT_FILE]
        );
        $this->assertMatchesRegularExpression('/^user = azerioid-panel$/m', $this->rt->files[M::POOL]);
        $this->assertFalse($this->ran(['/usr/bin/systemctl', 'enable', M::UNIT . '.service']), 'EL already had the unit');
    }

    public function test_panel_user_falls_back_to_web_user_before_part_a(): void
    {
        $this->rt->files['/etc/azerioid-panel/broker.json'] = json_encode(['web_user' => 'caddy']);
        $this->assertSame('caddy', Config::load('/etc/azerioid-panel/broker.json', $this->rt)->panelUser);

        $this->rt->files['/etc/azerioid-panel/broker.json'] = json_encode(['web_user' => 'caddy', 'panel_user' => M::USER]);
        $loaded = Config::load('/etc/azerioid-panel/broker.json', $this->rt);
        $this->assertSame(M::USER, $loaded->panelUser);
        $this->assertSame('caddy', $loaded->webUser);
        $this->assertSame('caddy', $loaded->phpUser, 'site PHP identity is unaffected by the panel identity');
    }

    /** Turns the seeded apt host into an EL one: dedicated master already, one shared /etc/php.ini. */
    private function seedElHost(string $ini, ?string $bak): void
    {
        $this->seedAptHost();
        unset($this->rt->files[self::DISTRO_POOL], $this->rt->files[self::DISTRO_INI], $this->rt->files[self::DISTRO_INI . '.azerioid-panel.bak']);
        $this->rt->files[M::POOL] = "[azerioid-panel]\nuser = caddy\ngroup = caddy\nlisten.owner = caddy\nlisten.group = caddy\n";
        $this->rt->files[M::UNIT_FILE] = "[Service]\nExecStart=/usr/local/lib/azerioid-panel/sbin/php-fpm --nodaemonize --fpm-config /etc/azerioid-panel/php-fpm.conf\n";
        $this->rt->files['/usr/local/lib/azerioid-panel/sbin/php-fpm'] = '';
        $this->rt->files['/etc/php.ini'] = $ini;
        if ($bak !== null) {
            $this->rt->files['/etc/php.ini.azerioid-panel.bak'] = $bak;
        }
        $this->config->panelFpmUnit = M::UNIT;
        $this->rt->script(['/usr/bin/systemctl', 'cat', 'php8.4-fpm.service'], 1, '');
        $this->rt->script(['/usr/bin/systemctl', 'cat', 'php84-php-fpm.service'], 1, '');
        $this->rt->script(['/usr/bin/systemctl', 'cat', 'php-fpm.service'], 0, '');
    }

    /**
     * Review finding: on EL /etc/php.ini also serves the PHP CLI, so putting the
     * operator's proc_open ban back would cut the queue worker and scheduler off
     * from the broker. The migrator must leave that file alone.
     */
    public function test_el_shared_php_ini_is_not_restored(): void
    {
        $this->seedElHost("disable_functions = pcntl_fork\n", "disable_functions = pcntl_fork,proc_open,proc_get_status\n");
        $this->scriptHealthyCutOver();

        $out = $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');

        $this->assertSame("disable_functions = pcntl_fork\n", $this->rt->files['/etc/php.ini']);
        $this->assertNotEmpty(array_filter($out['log'], static fn (string $l): bool => str_contains($l, 'shared with the PHP CLI')));
        $this->assertFalse(M::isFpmOnlyIni('/etc/php.ini'));
        $this->assertFalse(M::isFpmOnlyIni('/etc/opt/remi/php84/php.ini'));
        $this->assertTrue(M::isFpmOnlyIni('/etc/php/8.4/fpm/php.ini'));
    }

    /** Review finding: an active queue unit does not prove the CLI can still reach the broker. */
    public function test_cli_without_proc_open_fails_the_migration_and_rolls_back(): void
    {
        $this->seedAptHost();
        $this->scriptHealthyCutOver();
        $this->rt->script([
            '/usr/sbin/runuser', '-u', M::USER, '--', '/usr/bin/php', '-r',
            'exit(function_exists("proc_open") && function_exists("proc_get_status") ? 0 : 3);',
        ], 3, '');
        $sudoersBefore = $this->rt->files['/etc/sudoers.d/azerioid-panel'];

        try {
            $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');
            $this->fail('a CLI that cannot spawn the broker must fail the migration');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('cannot use proc_open', $e->getMessage());
        }
        $this->assertSame($sudoersBefore, $this->rt->files['/etc/sudoers.d/azerioid-panel'], 'the old grant is back');
        $this->assertSame('failed', json_decode($this->rt->files[M::STATE_FILE], true)['result']);
    }

    /** Review finding: the scheduler must not start a migration while install.sh is running. */
    public function test_converge_stays_out_while_install_sh_runs(): void
    {
        $this->seedAptHost();
        $this->rt->files[M::INSTALLING_MARKER] = '';

        $out = $this->migrator()->converge(false);
        $this->assertFalse($out['started']);
        $this->assertStringContainsString('install.sh', $out['reason']);
        $this->assertFalse($this->migrator()->converge(true)['started'], 'nor the unit it would have started');
    }

    public function test_installer_own_migration_is_not_blocked_by_its_marker(): void
    {
        $this->seedAptHost();
        $this->scriptHealthyCutOver();
        $this->rt->files[M::INSTALLING_MARKER] = '';

        $this->assertTrue($this->migrator()->apply('MIGRATE-PANEL-IDENTITY')['changed']);
    }

    /**
     * Review finding: the migrator must pick the php.ini the installer edited
     * (fpm_ini() order: /etc/php.ini before the Remi ini), not a sibling.
     */
    public function test_panel_ini_is_seeded_from_the_ini_the_installer_edited(): void
    {
        $this->seedElHost("memory_limit = 128M\n", null);
        $this->rt->files['/etc/opt/remi/php84/php.ini'] = "memory_limit = 999M\n";
        $this->scriptHealthyCutOver();

        $this->migrator()->apply('MIGRATE-PANEL-IDENTITY');

        $this->assertStringContainsString('Seeded from /etc/php.ini', $this->rt->files[M::INI]);
        $this->assertStringContainsString('memory_limit = 128M', $this->rt->files[M::INI]);
    }

    /**
     * Regression (found on Rocky 9): stock php.ini has an empty `disable_functions =`
     * followed by a blank line and a comment. `\s*` after the `=` crossed the newline
     * and merged the next line into the value; had it been a directive, it would have
     * been lost.
     */
    public function test_allow_proc_open_never_reaches_into_the_next_line(): void
    {
        $this->assertSame(
            "disable_functions = \n\ndisable_classes = Foo\n",
            M::allowProcOpen("disable_functions =\n\ndisable_classes = Foo\n")
        );
        $this->assertSame(
            "disable_functions = exec\n; comment\n",
            M::allowProcOpen("disable_functions = exec,proc_open\n; comment\n")
        );
    }

    public function test_allow_proc_open_only_touches_the_two_functions(): void
    {
        $this->assertSame(
            "x = 1\ndisable_functions = exec,pcntl_fork\ny = 2\n",
            M::allowProcOpen("x = 1\ndisable_functions = exec,proc_open,pcntl_fork,proc_get_status\ny = 2\n")
        );
    }

    /**
     * Regression: the Part B hardener used a pattern whose `\s` crossed newlines,
     * so on the installer's cron file it rewrote a word in the header comment
     * and left the job running as the old user.
     */
    public function test_cron_user_rewrite_touches_only_the_job_line(): void
    {
        $installed = "# AZERIOID Stack Manager — Laravel scheduler\nSHELL=/bin/sh\nPATH=/usr/sbin:/usr/bin:/sbin:/bin\n"
            . "* * * * * www-data /usr/bin/php8.4 /usr/local/lib/azerioid-panel/web/artisan schedule:run >/dev/null 2>&1\n";

        $this->assertSame(
            "# AZERIOID Stack Manager — Laravel scheduler\nSHELL=/bin/sh\nPATH=/usr/sbin:/usr/bin:/sbin:/bin\n"
            . "* * * * * azerioid-panel /usr/bin/php8.4 /usr/local/lib/azerioid-panel/web/artisan schedule:run >/dev/null 2>&1\n",
            PanelSudoers::cronUser($installed, 'azerioid-panel')
        );
        $this->assertNull(PanelSudoers::cronUser("SHELL=/bin/sh\n", 'azerioid-panel'));
    }

    public function test_hardening_never_moves_a_migrated_panel_back_to_caddy(): void
    {
        $this->rt->files[M::POOL] = "[azerioid-panel]\nuser = azerioid-panel\n";
        $this->rt->files['/etc/php/8.4/fpm/pool.d/www.conf'] = "[www]\nuser = www-data\n";
        $this->rt->files['/etc/sudoers.d/azerioid-panel'] = 'azerioid-panel ALL=(root) NOPASSWD: ' . self::BROKER . "\n";
        $this->config->panelUser = M::USER;
        $this->rt->script(['/usr/bin/id', '-u', M::USER], 0, "998\n");
        $this->rt->script(['/usr/bin/id', '-u', 'caddy'], 0, "999\n");

        $out = (new PanelHardener($this->rt, $this->config))->apply('HARDEN-PANEL');

        $this->assertTrue($out['already_hardened']);
        $this->assertSame(M::USER, $out['panel_user']);
    }

    public function test_updater_refuses_to_move_a_migrated_host_below_part_a(): void
    {
        $this->config->panelUser = M::USER;
        $this->config->panelSourcePath = '/var/lib/azerioid-panel/src';
        $src = $this->config->panelSourcePath;
        $this->rt->dirs[$src] = true;
        $this->rt->dirs[$src . '/.git'] = true;
        $this->rt->files[$this->config->panelRoot . '/COMMIT'] = str_repeat('b', 40) . "\n";
        $this->rt->files[$this->config->panelRoot . '/TAG'] = "v2.0.0\n";
        $this->rt->script(['/usr/bin/git', '-C', $src, 'status', '--porcelain'], 0, '');
        $this->rt->script(['/usr/bin/git', '-C', $src, 'tag', '-l', 'v*'], 0, "v1.10.2\nv2.0.0\n");
        $this->rt->script(['/usr/bin/git', '-C', $src, 'rev-parse', 'v1.10.2^{commit}'], 0, str_repeat('a', 40) . "\n");

        try {
            (new PanelUpdater($this->config, $this->rt))->apply('op-down1', PanelUpdater::CONFIRM, 'v1.10.2');
            $this->fail('a migrated host must not be moved onto a release that predates Part A');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('Refusing to move to v1.10.2', $e->getMessage());
        }
        $this->assertFalse($this->ran(['/usr/bin/git', '-C', $src, 'checkout', '-f', '--detach', str_repeat('a', 40)]));
    }
}
