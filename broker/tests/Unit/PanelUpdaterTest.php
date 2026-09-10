<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
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
        $runtime->files['/usr/sbin/runuser'] = '';
        $runtime->files['/usr/bin/php8.4'] = '';
        $runtime->files['/usr/local/bin/composer'] = '';

        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'status', '--porcelain'],
            0,
            " M broker/src/Config.php\n"
        );
        // fetch may not be reached, but if ensureSource sees .git it skips clone
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'fetch', '--prune', 'origin'],
            0,
            ''
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

    public function test_check_reports_up_to_date_when_commits_match(): void
    {
        $runtime = new FakeRuntime();
        $config = new Config();
        $config->panelSourcePath = '/var/lib/azerioid-panel/src';
        $config->panelRoot = '/usr/local/lib/azerioid-panel';
        $hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $runtime->dirs[$config->panelSourcePath] = true;
        $runtime->dirs[$config->panelSourcePath . '/.git'] = true;
        $runtime->files[$config->panelRoot . '/COMMIT'] = $hash . "\n";
        $runtime->files[$config->panelRoot . '/VERSION'] = "0.2.0\n";

        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'fetch', '--prune', 'origin'],
            0,
            ''
        );
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'rev-parse', 'origin/main'],
            0,
            $hash . "\n"
        );
        $runtime->script(
            ['/usr/bin/git', '-C', $config->panelSourcePath, 'status', '--porcelain'],
            0,
            ''
        );

        $result = (new PanelUpdater($config, $runtime))->check();
        $this->assertTrue($result['up_to_date']);
        $this->assertFalse($result['update_available']);
        $this->assertSame($hash, $result['deployed_commit']);
        $this->assertSame('panel-self-update', $result['scope']);
    }
}
