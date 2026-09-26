<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Panel\PanelHardener;
use PHPUnit\Framework\TestCase;

/**
 * R1: the panel must never share its broker sudo grant with a site PHP-FPM
 * pool user. These tests pin the detection logic that decides that.
 */
final class PanelHardenerTest extends TestCase
{
    private FakeRuntime $rt;

    private Config $config;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->config = new Config();
        $this->config->panelRoot = '/usr/local/lib/azerioid-panel';
        $this->config->panelPhpVersion = '8.4';
        $this->config->panelFpmUnit = 'php8.4-fpm';
        $this->config->panelFpmSocket = '/run/php/azerioid-panel.sock';
    }

    /** Reproduces the live 64.226.78.176 state: panel and sites both www-data. */
    private function seedVulnerableAptHost(): void
    {
        $this->rt->files['/etc/php/8.4/fpm/pool.d/azerioid-panel.conf'] =
            "[azerioid-panel]\nuser = www-data\ngroup = www-data\nlisten = /run/php/azerioid-panel.sock\nlisten.owner = www-data\nlisten.group = www-data\n";
        $this->rt->files['/etc/php/8.4/fpm/pool.d/www.conf'] =
            "[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/php8.4-fpm.sock\n";
        $this->rt->files['/etc/sudoers.d/azerioid-panel'] =
            "# AZERIOID\nDefaults:www-data !requiretty\nDefaults:www-data umask=0022\nwww-data ALL=(root) NOPASSWD: /usr/local/lib/azerioid-panel/broker\n";
        $this->config->webUser = 'www-data';
        // caddy and www-data both exist; apache does not.
        $this->rt->script(['/usr/bin/id', '-u', 'caddy'], 0, "999\n");
        $this->rt->script(['/usr/bin/id', '-u', 'www-data'], 0, "33\n");
        $this->rt->script(['/usr/bin/id', '-u', 'apache'], 1, '', 'no such user');
    }

    public function test_detects_vulnerable_apt_host(): void
    {
        $this->seedVulnerableAptHost();
        $status = (new PanelHardener($this->rt, $this->config))->status();

        $this->assertTrue($status['vulnerable']);
        $this->assertSame('www-data', $status['panel_pool_user']);
        $this->assertSame(['www-data'], $status['sudoers_users']);
        $this->assertContains('www-data', $status['site_pool_users']);
        $this->assertSame(['www-data'], $status['colliding_users']);
        $this->assertStringContainsString('VULNERABLE', $status['verdict']);
    }

    public function test_target_is_caddy_not_a_site_pool_user(): void
    {
        $this->seedVulnerableAptHost();
        $status = (new PanelHardener($this->rt, $this->config))->status();

        $this->assertSame('caddy', $status['target_user']);
        $this->assertNotContains($status['target_user'], $status['site_pool_users']);
    }

    public function test_panel_own_pool_is_not_counted_as_a_site_pool(): void
    {
        $this->seedVulnerableAptHost();
        $status = (new PanelHardener($this->rt, $this->config))->status();

        $this->assertArrayNotHasKey(
            '/etc/php/8.4/fpm/pool.d/azerioid-panel.conf',
            $status['site_pools'],
            'the panel pool must never be treated as a site pool'
        );
    }

    public function test_hardened_host_reports_not_vulnerable(): void
    {
        $this->rt->files['/etc/php/8.4/fpm/pool.d/azerioid-panel.conf'] =
            "[azerioid-panel]\nuser = caddy\ngroup = caddy\nlisten.owner = caddy\nlisten.group = caddy\n";
        $this->rt->files['/etc/php/8.4/fpm/pool.d/www.conf'] =
            "[www]\nuser = www-data\ngroup = www-data\n";
        $this->rt->files['/etc/sudoers.d/azerioid-panel'] =
            "caddy ALL=(root) NOPASSWD: /usr/local/lib/azerioid-panel/broker\n";
        $this->rt->script(['/usr/bin/id', '-u', 'caddy'], 0, "999\n");

        $status = (new PanelHardener($this->rt, $this->config))->status();

        $this->assertFalse($status['vulnerable']);
        $this->assertSame([], $status['colliding_users']);
        $this->assertStringStartsWith('OK', $status['verdict']);
    }

    public function test_el_layout_with_dedicated_master_is_detected(): void
    {
        $this->rt->files['/etc/azerioid-panel/php-fpm.d/azerioid-panel.conf'] =
            "[azerioid-panel]\nuser = caddy\ngroup = caddy\n";
        $this->rt->files['/etc/php-fpm.d/www.conf'] = "[www]\nuser = apache\ngroup = apache\n";
        $this->rt->files['/etc/sudoers.d/azerioid-panel'] =
            "caddy ALL=(root) NOPASSWD: /usr/local/lib/azerioid-panel/broker\n";
        $this->rt->script(['/usr/bin/id', '-u', 'caddy'], 0, "999\n");

        $status = (new PanelHardener($this->rt, $this->config))->status();

        $this->assertSame('/etc/azerioid-panel/php-fpm.d/azerioid-panel.conf', $status['panel_pool_path']);
        $this->assertSame('caddy', $status['panel_pool_user']);
        $this->assertFalse($status['vulnerable']);
    }

    public function test_apply_requires_typed_confirm(): void
    {
        $this->seedVulnerableAptHost();
        $this->expectException(BrokerException::class);
        (new PanelHardener($this->rt, $this->config))->apply('', false, false);
    }

    public function test_apply_rejects_wrong_confirm(): void
    {
        $this->seedVulnerableAptHost();
        $this->expectException(BrokerException::class);
        (new PanelHardener($this->rt, $this->config))->apply('HARDEN', false, false);
    }

    public function test_dry_run_changes_nothing_and_reports_plan(): void
    {
        $this->seedVulnerableAptHost();
        $before = $this->rt->files;

        $out = (new PanelHardener($this->rt, $this->config))->apply('HARDEN-PANEL', false, true);

        $this->assertTrue($out['dry_run']);
        $this->assertFalse($out['changed']);
        $this->assertSame('www-data', $out['would_migrate_from']);
        $this->assertSame('caddy', $out['would_migrate_to']);
        $this->assertNotEmpty($out['plan']);
        $this->assertSame($before, $this->rt->files, 'dry run must not mutate any file');
    }

    public function test_refuses_when_every_candidate_is_a_site_pool_user(): void
    {
        // Only www-data exists, and it is the site pool user → nowhere safe to go.
        $this->rt->files['/etc/php/8.4/fpm/pool.d/azerioid-panel.conf'] = "[azerioid-panel]\nuser = www-data\n";
        $this->rt->files['/etc/php/8.4/fpm/pool.d/www.conf'] = "[www]\nuser = www-data\n";
        $this->rt->files['/etc/sudoers.d/azerioid-panel'] =
            "www-data ALL=(root) NOPASSWD: /usr/local/lib/azerioid-panel/broker\n";
        $this->rt->script(['/usr/bin/id', '-u', 'caddy'], 1, '', 'no such user');
        $this->rt->script(['/usr/bin/id', '-u', 'www-data'], 0, "33\n");
        $this->rt->script(['/usr/bin/id', '-u', 'apache'], 1, '', 'no such user');

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/No suitable panel identity/');
        (new PanelHardener($this->rt, $this->config))->apply('HARDEN-PANEL', false, false);
    }

    public function test_sudoers_parser_ignores_comments_and_defaults(): void
    {
        $this->rt->files['/etc/php/8.4/fpm/pool.d/azerioid-panel.conf'] = "[azerioid-panel]\nuser = caddy\n";
        $this->rt->files['/etc/sudoers.d/azerioid-panel'] = <<<'SUDO'
# comment line
Defaults:caddy !requiretty
Defaults:caddy umask=0022
caddy ALL=(root) NOPASSWD: /usr/local/lib/azerioid-panel/broker
SUDO;
        $this->rt->script(['/usr/bin/id', '-u', 'caddy'], 0, "999\n");

        $status = (new PanelHardener($this->rt, $this->config))->status();
        $this->assertSame(['caddy'], $status['sudoers_users']);
    }

    public function test_status_always_states_residual_risk(): void
    {
        $this->seedVulnerableAptHost();
        $status = (new PanelHardener($this->rt, $this->config))->status();

        $this->assertStringContainsString('A39', $status['residual_risk']);
        $this->assertStringContainsString('proc_open', $status['residual_risk']);
    }
}
