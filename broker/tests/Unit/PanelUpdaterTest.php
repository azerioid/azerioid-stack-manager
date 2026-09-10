<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use PHPUnit\Framework\TestCase;

final class PanelUpdaterTest extends TestCase
{
    public function test_apply_requires_confirm_phrase(): void
    {
        $runtime = new FakeRuntime();
        $config = new Config();
        $updater = new PanelUpdater($config, $runtime);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage('Confirmation phrase did not match');
        $updater->apply('op-test1', 'NOPE');
    }

    public function test_apply_refuses_dirty_working_tree(): void
    {
        $runtime = new FakeRuntime();
        $config = new Config();
        $config->panelSourcePath = '/var/lib/azerioid-panel/src';
        $config->panelRoot = '/usr/local/lib/azerioid-panel';
        $runtime->dirs[$config->panelSourcePath] = true;
        $runtime->dirs[$config->panelSourcePath . '/.git'] = true;
        $runtime->files[$config->panelRoot . '/COMMIT'] = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n";
        $runtime->files[$config->panelRoot . '/TAG'] = "v0.2.1\n";
        $runtime->files['/usr/sbin/runuser'] = '';
        $runtime->files['/usr/bin/php8.4'] = '';
        $runtime->files['/usr/local/bin/composer'] = '';

        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'status', '--porcelain'],
            0,
            " M broker/src/Config.php\n"
        );

        $updater = new PanelUpdater($config, $runtime);
        try {
            $updater->apply('op-dirty1', PanelUpdater::CONFIRM);
            $this->fail('Expected dirty-tree refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('dirty', strtolower($e->getMessage()));
            $this->assertStringContainsString('Config.php', $e->getMessage());
        }
    }

    public function test_check_reports_up_to_date_on_latest_tag(): void
    {
        $runtime = new FakeRuntime();
        $config = new Config();
        $config->panelSourcePath = '/var/lib/azerioid-panel/src';
        $config->panelRoot = '/usr/local/lib/azerioid-panel';
        $hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $runtime->dirs[$config->panelSourcePath] = true;
        $runtime->dirs[$config->panelSourcePath . '/.git'] = true;
        $runtime->files[$config->panelRoot . '/COMMIT'] = $hash . "\n";
        $runtime->files[$config->panelRoot . '/TAG'] = "v0.2.2\n";
        $runtime->files[$config->panelRoot . '/VERSION'] = "0.2.2\n";

        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'fetch', '--prune', '--tags', 'origin'],
            0,
            ''
        );
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'tag', '-l', 'v*'],
            0,
            "v0.2.1\nv0.2.10\nv0.2.2\nv0.2.9\n"
        );
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'rev-parse', 'v0.2.10'],
            0,
            $hash . "\n"
        );
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'status', '--porcelain'],
            0,
            ''
        );

        $result = (new PanelUpdater($config, $runtime))->check();
        $this->assertSame('v0.2.10', $result['latest_tag']);
        $this->assertSame(
            ['v0.2.10', 'v0.2.9', 'v0.2.2', 'v0.2.1'],
            $result['tags']
        );
        $this->assertTrue($result['update_available']);
        $this->assertFalse($result['up_to_date']);
        $this->assertSame('panel-self-update', $result['scope']);
    }

    public function test_apply_rejects_unknown_tag(): void
    {
        $runtime = new FakeRuntime();
        $config = new Config();
        $config->panelSourcePath = '/var/lib/azerioid-panel/src';
        $config->panelRoot = '/usr/local/lib/azerioid-panel';
        $runtime->dirs[$config->panelSourcePath] = true;
        $runtime->dirs[$config->panelSourcePath . '/.git'] = true;
        $runtime->files[$config->panelRoot . '/COMMIT'] = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n";
        $runtime->files[$config->panelRoot . '/TAG'] = "v0.2.1\n";

        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'status', '--porcelain'],
            0,
            ''
        );
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'fetch', '--prune', '--tags', 'origin'],
            0,
            ''
        );
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'tag', '-l', 'v*'],
            0,
            "v0.2.1\nv0.2.2\n"
        );

        try {
            (new PanelUpdater($config, $runtime))->apply('op-badtag', PanelUpdater::CONFIRM, 'v9.9.9');
            $this->fail('Expected unknown tag refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('Unknown release tag', $e->getMessage());
            $this->assertStringContainsString('v9.9.9', $e->getMessage());
        }
    }
}
