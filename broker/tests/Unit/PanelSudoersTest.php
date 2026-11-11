<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Panel\PanelSudoers;
use PHPUnit\Framework\TestCase;

/**
 * A86: the panel's sudoers grant must parse under both sudo and sudo-rs, and
 * the three places that emit it must not drift apart.
 */
final class PanelSudoersTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    public function test_render_emits_only_the_umask_default_and_the_grant(): void
    {
        $body = PanelSudoers::render(new Config(), ['caddy', 'azerioid-panel'], 'test');

        $this->assertSame(
            "# AZERIOID Stack Manager — sudoers (test)\n"
            . "Defaults:caddy umask=0022\n"
            . "Defaults:azerioid-panel umask=0022\n"
            . "caddy ALL=(root) NOPASSWD: /usr/local/lib/azerioid-panel/broker\n"
            . "azerioid-panel ALL=(root) NOPASSWD: /usr/local/lib/azerioid-panel/broker\n",
            $body
        );
    }

    /** sudo-rs (Ubuntu 26.04) rejects the whole file on `requiretty`: "unknown setting". */
    public function test_no_emitter_uses_a_setting_sudo_rs_does_not_know(): void
    {
        $template = (string) file_get_contents(self::REPO . '/deploy/sudoers.d/azerioid-panel');
        $installer = (string) file_get_contents(self::REPO . '/deploy/lib/broker-setup.sh');

        $this->assertSame(
            self::rules(PanelSudoers::render(new Config(), ['caddy'], 'test')),
            self::rules($template),
            'deploy/sudoers.d/azerioid-panel is what CI validates; it must match what the broker writes'
        );
        $this->assertStringContainsString(
            "Defaults:\${PANEL_USER} umask=0022\n\${PANEL_USER} ALL=(root) NOPASSWD: \${PREFIX}/broker\nEOF",
            $installer
        );
        $this->assertStringNotContainsString('requiretty', $installer);
    }

    public function test_install_leaves_the_live_file_alone_when_visudo_rejects_the_new_one(): void
    {
        $rt = new FakeRuntime();
        $config = new Config();
        $rt->files[PanelSudoers::PATH] = "old grant\n";
        $rt->script(
            ['/usr/sbin/visudo', '-c', '-f', $config->stagingDir . '/sudoers.azerioid-panel.check'],
            1,
            '',
            "syntax error: unknown setting: 'requiretty'"
        );

        try {
            PanelSudoers::install($rt, $config, ['caddy'], 'test');
            $this->fail('install must refuse a file visudo rejects');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('unknown setting', $e->getMessage());
        }
        $this->assertSame("old grant\n", $rt->files[PanelSudoers::PATH]);
    }

    /** @return list<string> non-comment lines */
    private static function rules(string $sudoers): array
    {
        return array_values(array_filter(
            explode("\n", $sudoers),
            static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')
        ));
    }
}
