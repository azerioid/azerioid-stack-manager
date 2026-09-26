<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Web\DefaultSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * B1 / request #14 — a catch-all for hostnames no vhost claims.
 *
 * A22 closed this hole on the panel port with a 421 catch-all but it was never
 * closed on :80/:443. Measured on a fleet host beforehand: an unknown Host got a
 * 308 to HTTPS and the HTTPS connection then failed the handshake outright, so a
 * visitor saw nothing.
 *
 * The critical property is **specificity**: these blocks are hostless, so every
 * named vhost must still win. Getting that wrong would route every site here,
 * which is why it is pinned first.
 */
final class DefaultSiteTest extends TestCase
{
    private FakeRuntime $rt;

    private Config $cfg;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->rt->dirs[$this->cfg->caddyConfD] = true;
        // apply() runs a real Caddy validate+reload, so the fake needs a main
        // config and scripted caddy responses (same seed the other Caddy tests use).
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);
        $this->rt->script(['/usr/bin/systemctl', 'is-active', 'caddy'], 0, "active\n");
    }

    /** The rollback path: a snippet Caddy rejects must not be left behind. */
    public function test_a_rejected_snippet_is_rolled_back(): void
    {
        $this->rt->script(
            ['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'],
            1,
            '',
            'adapting config: parse error'
        );

        try {
            $this->site()->apply(DefaultSite::MODE_PAGE);
            $this->fail('expected the apply to be refused');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('rejected by Caddy', $e->getMessage());
        }

        $this->assertArrayNotHasKey(
            $this->cfg->caddyConfD . '/' . DefaultSite::SNIPPET,
            $this->rt->files,
            'leaving it would make Caddy refuse every later reload for the whole host'
        );
    }

    private function site(): DefaultSite
    {
        return new DefaultSite($this->rt, $this->cfg);
    }

    private function snippet(): string
    {
        return $this->rt->files[$this->cfg->caddyConfD . '/' . DefaultSite::SNIPPET] ?? '';
    }

    // ------------------------------------------------------------- specificity

    public function test_blocks_are_hostless_so_named_vhosts_still_win(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE);
        $body = $this->snippet();

        // Hostless site addresses only. A hostname here would make this block
        // compete with a real vhost instead of losing to it.
        $this->assertStringContainsString('http://:80 {', $body);
        $this->assertStringContainsString('https://:443 {', $body);
        $this->assertDoesNotMatchRegularExpression(
            '/^(https?:\/\/)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(:\d+)?\s*\{/mi',
            $body,
            'the catch-all must never name a hostname'
        );
    }

    /** Without a cert for an arbitrary SNI the handshake fails — the bug being fixed. */
    public function test_https_block_uses_the_internal_ca(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE);

        $this->assertStringContainsString('tls internal', $this->snippet());
    }

    public function test_snippet_is_marked_broker_managed(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE);

        $this->assertStringContainsString('broker-managed', $this->snippet());
        $this->assertStringContainsString('Do not edit', $this->snippet());
    }

    // -------------------------------------------------------------------- modes

    #[DataProvider('modes')]
    public function test_each_mode_renders_its_own_response(string $mode, string $expect): void
    {
        $result = $this->site()->apply($mode);

        $this->assertSame($mode, $result['mode']);
        $this->assertStringContainsString($expect, $this->snippet());
    }

    public static function modes(): array
    {
        return [
            'branded page' => [DefaultSite::MODE_PAGE, 'file_server'],
            'plain 404' => [DefaultSite::MODE_404, 'respond 404'],
            'misdirected 421' => [DefaultSite::MODE_421, 'respond "Misdirected request." 421'],
        ];
    }

    public function test_rejects_an_unknown_mode(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/mode must be one of/');
        $this->site()->apply('teapot');
    }

    public function test_status_round_trips_each_mode(): void
    {
        foreach (DefaultSite::MODES as $mode) {
            $this->site()->apply($mode);
            $this->assertSame($mode, $this->site()->status()['mode'], $mode);
        }
    }

    // ----------------------------------------------------------------- document

    /**
     * Served from a panel-owned directory, never /data/www, so no vhost identity
     * (A25) can edit what unmatched visitors are shown.
     */
    public function test_document_lives_outside_the_web_root(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE);

        $this->assertStringStartsWith('/var/lib/azerioid-panel/', DefaultSite::ROOT);
        $this->assertStringNotContainsString($this->cfg->wwwRoot, DefaultSite::ROOT);
        $this->assertArrayHasKey(DefaultSite::ROOT . '/index.html', $this->rt->files);
    }

    /** Neutral wording: it must not advertise the software or a hostname. */
    public function test_default_page_names_no_software_or_host(): void
    {
        $html = DefaultSite::defaultHtml();

        $this->assertStringContainsString('not configured on this server', $html);
        foreach (['AZERIOID', 'Caddy', 'nginx', 'Apache', 'PHP', '1.7.0'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "must not disclose {$leak}");
        }
    }

    public function test_custom_html_is_used_when_supplied(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE, '<h1>Nothing here</h1>');

        $this->assertSame('<h1>Nothing here</h1>', $this->rt->files[DefaultSite::ROOT . '/index.html']);
    }

    public function test_blank_custom_html_falls_back_to_the_default(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE, "   \n ");

        $this->assertStringContainsString('not configured', $this->rt->files[DefaultSite::ROOT . '/index.html']);
    }

    /** The other modes never need a document, so none should be written. */
    public function test_non_page_modes_do_not_write_a_document(): void
    {
        $this->site()->apply(DefaultSite::MODE_421);

        $this->assertArrayNotHasKey(DefaultSite::ROOT . '/index.html', $this->rt->files);
    }

    // ------------------------------------------------------------------ lifecycle

    public function test_disabled_by_default(): void
    {
        $status = $this->site()->status();

        $this->assertFalse($status['enabled']);
        $this->assertSame(DefaultSite::DEFAULT_MODE, $status['mode']);
    }

    public function test_clear_removes_the_snippet(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE);
        $this->assertNotSame('', $this->snippet());

        $result = $this->site()->disable();

        $this->assertFalse($result['enabled']);
        $this->assertArrayNotHasKey($this->cfg->caddyConfD . '/' . DefaultSite::SNIPPET, $this->rt->files);
    }

    public function test_clear_is_a_noop_when_not_enabled(): void
    {
        $result = $this->site()->disable();

        $this->assertFalse($result['enabled']);
        $this->assertNull($result['applied']);
    }

    public function test_switching_mode_replaces_rather_than_appends(): void
    {
        $this->site()->apply(DefaultSite::MODE_PAGE);
        $this->site()->apply(DefaultSite::MODE_404);
        $body = $this->snippet();

        $this->assertSame(1, substr_count($body, 'http://:80 {'));
        $this->assertStringNotContainsString('file_server', $body);
    }
}
