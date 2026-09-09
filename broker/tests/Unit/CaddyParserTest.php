<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\CaddyParser;
use PHPUnit\Framework\TestCase;

final class CaddyParserTest extends TestCase
{
    public function test_parses_lacmp_php_vhost(): void
    {
        $contents = file_get_contents(__DIR__ . '/../fixtures/vhost-php.conf');
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/example.com.conf', $contents, ['projob.az']);

        $this->assertSame(['example.com'], $parsed['domains']);
        $this->assertSame('/data/www/example.com', $parsed['root']);
        $this->assertSame('php', $parsed['type']);
        $this->assertSame('8.4', $parsed['php_version']);
        $this->assertSame('caddy', $parsed['engine'] ?? 'caddy');
        $this->assertFalse($parsed['readonly']);
        $this->assertTrue($parsed['tls']);
    }

    public function test_marks_reverse_proxy_readonly(): void
    {
        $contents = file_get_contents(__DIR__ . '/../fixtures/vhost-projob.conf');
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/projob.az.conf', $contents, ['projob.az']);

        $this->assertSame('projob.az', $parsed['domain']);
        $this->assertSame('proxy', $parsed['type']);
        $this->assertTrue($parsed['readonly']);
        $this->assertSame('127.0.0.1:8000', $parsed['reverse_proxy']);
    }

    public function test_marks_default_site_readonly(): void
    {
        $contents = file_get_contents(__DIR__ . '/../fixtures/vhost-default.conf');
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/default.conf', $contents, ['projob.az']);
        $this->assertTrue($parsed['readonly']);
        $this->assertFalse($parsed['tls']);
    }

    public function test_marks_panel_localhost_vhost_readonly(): void
    {
        $contents = <<<'CADDY'
http://127.0.0.1:3169 {
    bind 127.0.0.1
    root * /usr/local/lib/azerioid-panel/web/public
    php_fastcgi unix//run/php/azerioid-panel.sock
}
CADDY;
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/azerioid-panel.conf', $contents, ['projob.az']);
        $this->assertTrue($parsed['readonly']);
        $this->assertSame('php', $parsed['type']);
    }

    public function test_extracts_all_site_blocks_preferring_public_listen(): void
    {
        $contents = <<<'CADDY'
http://127.0.0.1:3169 {
    bind 127.0.0.1
    root * /usr/local/lib/azerioid-panel/web/public
    php_fastcgi unix//run/php/azerioid-panel.sock
    file_server {
        index index.html
    }
}
https://201.79.10.81:3169 {
    tls internal
    root * /usr/local/lib/azerioid-panel/web/public
    php_fastcgi unix//run/php/azerioid-panel.sock
}
CADDY;
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/azerioid-panel.conf', $contents, []);
        $this->assertSame(['201.79.10.81:3169', '127.0.0.1:3169'], $parsed['domains']);
        $this->assertSame('201.79.10.81:3169', $parsed['domain']);
        $this->assertSame('internal', $parsed['tls_mode']);
        $this->assertTrue($parsed['readonly']);
    }

    public function test_internal_engine_reverse_proxy_is_not_user_proxy_type(): void
    {
        $contents = <<<'CADDY'
# azerioid-managed engine=apache type=php php=8.4 root=/data/www/abc.az
abc.az {
    reverse_proxy 127.0.0.1:8081 {
        header_up Host {host}
    }
}
CADDY;
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/abc.az.conf', $contents, []);
        $this->assertSame('apache', $parsed['engine']);
        $this->assertSame('php', $parsed['type']);
        $this->assertSame('8.4', $parsed['php_version']);
        $this->assertSame('/data/www/abc.az', $parsed['root']);
        $this->assertSame('127.0.0.1:8081', $parsed['reverse_proxy']);
    }
}
