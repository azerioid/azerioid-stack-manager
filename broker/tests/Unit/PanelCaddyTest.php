<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Web\PanelCaddy;
use PHPUnit\Framework\TestCase;

final class PanelCaddyTest extends TestCase
{
    public function test_render_includes_catch_all_when_public_ip_present(): void
    {
        $body = (new PanelCaddy())->render(new Config(), $this->spec('201.79.10.81'));
        $this->assertStringContainsString('http://127.0.0.1:3169 {', $body);
        $this->assertStringContainsString('https://201.79.10.81:3169 {', $body);
        $this->assertStringContainsString('https://:3169 {', $body);
        $this->assertStringContainsString('respond "Misdirected request." 421', $body);
        $this->assertStringNotContainsString("panel.example.com {\n", $body);
    }

    public function test_render_omits_catch_all_without_public_ip(): void
    {
        $body = (new PanelCaddy())->render(new Config(), $this->spec(null));
        $this->assertStringContainsString('http://127.0.0.1:3169 {', $body);
        $this->assertStringContainsString('bind 127.0.0.1', $body);
        $this->assertStringNotContainsString('https://:3169', $body);
        $this->assertStringNotContainsString('201.79.10.81', $body);
    }

    public function test_render_white_label_is_host_specific_on_443(): void
    {
        $body = (new PanelCaddy())->render(new Config(), $this->spec('201.79.10.81', 'panel.azerioid.io', 'auto'));
        $this->assertStringContainsString('# azerioid-managed panel type=php domain=panel.azerioid.io tls=auto', $body);
        $this->assertStringContainsString("panel.azerioid.io {\n", $body);
        $this->assertStringNotContainsString('panel.azerioid.io:3169', $body);
        $after = explode("panel.azerioid.io {", $body, 2)[1] ?? '';
        $this->assertStringNotContainsString('tls internal', $after);
        $this->assertStringContainsString('https://201.79.10.81:3169 {', $body);
        $this->assertStringContainsString('https://:3169 {', $body);
    }

    public function test_parser_skips_bare_port_catch_all(): void
    {
        $contents = <<<'CADDY'
http://127.0.0.1:3169 {
    bind 127.0.0.1
    root * /usr/local/lib/azerioid-panel/web/public
}
https://201.79.10.81:3169 {
    tls internal
    root * /usr/local/lib/azerioid-panel/web/public
}
https://:3169 {
    tls internal
    respond "Misdirected request." 421
}
CADDY;
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/azerioid-panel.conf', $contents, []);
        $this->assertSame(['201.79.10.81:3169', '127.0.0.1:3169'], $parsed['domains']);
        $this->assertNotContains(':3169', $parsed['domains']);
    }

    public function test_apply_rewrites_catch_all_and_app_url(): void
    {
        $rt = $this->runtime();
        [$code, $decoded] = $this->dispatch($rt, ['broker', 'panel.domain.set'], []);
        $this->assertSame(0, $code, json_encode($decoded));
        $this->assertTrue($decoded['ok']);
        $snippet = $rt->files['/etc/caddy/conf.d/azerioid-panel.conf'];
        $this->assertStringContainsString('https://:3169 {', $snippet);
        $this->assertStringContainsString('https://203.0.113.10:3169 {', $snippet);
        $this->assertTrue((bool) $decoded['data']['catch_all']);
        $this->assertSame('https://203.0.113.10:3169', $decoded['data']['app_url']);
        $this->assertStringContainsString('APP_URL=https://203.0.113.10:3169', $rt->files['/usr/local/lib/azerioid-panel/web/.env']);
        $this->assertStringContainsString('panel.domain.set', $rt->files['/var/log/azerioid-panel/broker-audit.log']);
    }

    public function test_apply_sets_white_label_and_keeps_fallback(): void
    {
        $rt = $this->runtime();
        [$code, $decoded] = $this->dispatch($rt, ['broker', 'panel.domain.set'], [
            'domain' => 'panel.example.com',
            'tls_mode' => 'internal',
        ]);
        $this->assertSame(0, $code, json_encode($decoded));
        $snippet = $rt->files['/etc/caddy/conf.d/azerioid-panel.conf'];
        $this->assertStringContainsString("panel.example.com {\n    tls internal\n", $snippet);
        $this->assertStringContainsString('https://203.0.113.10:3169 {', $snippet);
        $this->assertStringContainsString('https://:3169 {', $snippet);
        $this->assertSame('panel.example.com', $decoded['data']['domain']);
        $this->assertSame('https://panel.example.com', $decoded['data']['app_url']);
        $this->assertContains('http://127.0.0.1:3169', $decoded['data']['fallback_urls']);
        $this->assertContains('https://203.0.113.10:3169', $decoded['data']['fallback_urls']);
        $broker = json_decode($rt->files['/etc/azerioid-panel/broker.json'], true);
        $this->assertSame('panel.example.com', $broker['panel']['domain']);
        $this->assertStringContainsString('APP_URL=https://panel.example.com', $rt->files['/usr/local/lib/azerioid-panel/web/.env']);
    }

    public function test_refuses_site_vhost_hostname(): void
    {
        $rt = $this->runtime();
        $rt->files['/etc/caddy/conf.d/let.az.conf'] = <<<'CADDY'
# azerioid-managed engine=caddy type=php php=8.4 root=/data/www/let.az
let.az {
    root * /data/www/let.az
    file_server
}
CADDY;
        [$code, $decoded] = $this->dispatch($rt, ['broker', 'panel.domain.set'], ['domain' => 'let.az', 'tls_mode' => 'internal']);
        $this->assertSame(3, $code);
        $this->assertFalse($decoded['ok']);
        $this->assertStringContainsString('already a site vhost', (string) $decoded['error']);
    }

    public function test_switch_drops_old_hostname(): void
    {
        $rt = $this->runtime();
        $this->dispatch($rt, ['broker', 'panel.domain.set'], ['domain' => 'panel.example.com', 'tls_mode' => 'internal']);
        [$code, $decoded] = $this->dispatch($rt, ['broker', 'panel.domain.set'], [
            'domain' => 'ops.example.com',
            'tls_mode' => 'internal',
        ]);
        $this->assertSame(0, $code, json_encode($decoded));
        $snippet = $rt->files['/etc/caddy/conf.d/azerioid-panel.conf'];
        $this->assertStringContainsString("ops.example.com {\n", $snippet);
        $this->assertStringNotContainsString('panel.example.com', $snippet);
        $this->assertSame('https://ops.example.com', $decoded['data']['app_url']);
    }

    public function test_rollback_restores_snippet_and_env_on_validate_failure(): void
    {
        $rt = $this->runtime();
        $this->dispatch($rt, ['broker', 'panel.domain.set'], []);
        $beforeSnippet = $rt->files['/etc/caddy/conf.d/azerioid-panel.conf'];
        $beforeEnv = $rt->files['/usr/local/lib/azerioid-panel/web/.env'];
        $beforeBroker = $rt->files['/etc/azerioid-panel/broker.json'];
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 1, '', 'bad config');

        [$code, $decoded] = $this->dispatch($rt, ['broker', 'panel.domain.set'], [
            'domain' => 'panel.example.com',
            'tls_mode' => 'internal',
        ]);
        $this->assertNotSame(0, $code);
        $this->assertFalse($decoded['ok']);
        $this->assertSame($beforeSnippet, $rt->files['/etc/caddy/conf.d/azerioid-panel.conf']);
        $this->assertSame($beforeEnv, $rt->files['/usr/local/lib/azerioid-panel/web/.env']);
        $this->assertSame($beforeBroker, $rt->files['/etc/azerioid-panel/broker.json']);
    }

    /**
     * @param  array<int, string>  $argv
     * @param  array<string, mixed>  $input
     * @return array{0:int,1:array<string,mixed>}
     */
    private function dispatch(FakeRuntime $rt, array $argv, array $input): array
    {
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run($argv, $input);
        $out = ob_get_clean();
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, $out);

        return [$code, $decoded];
    }

    private function runtime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->files['/usr/local/lib/azerioid-panel/web/.env'] = "APP_URL=https://old.example:3169\n";
        $rt->files['/etc/azerioid-panel/broker.json'] = "{}\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'caddy'], 0, "active\n");
        $rt->script(['/usr/sbin/ip', '-4', 'route', 'get', '1.1.1.1'], 0, "1.1.1.1 via 203.0.113.1 dev eth0 src 203.0.113.10 uid 0\n");

        return $rt;
    }

    /**
     * @return array{domain:?string,tls_mode:string,tls_cert:?string,tls_key:?string,public_ip:?string}
     */
    private function spec(?string $ip, ?string $domain = null, string $tls = 'auto'): array
    {
        return [
            'domain' => $domain,
            'tls_mode' => $tls,
            'tls_cert' => null,
            'tls_key' => null,
            'public_ip' => $ip,
        ];
    }
}
