<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Tls\TlsMode;
use PHPUnit\Framework\TestCase;

final class TlsModeTest extends TestCase
{
    public function test_public_hostname_detection(): void
    {
        $this->assertTrue(TlsMode::isPublicHostname('app.example.com'));
        $this->assertTrue(TlsMode::isPublicHostname('dacisi.com'));
        $this->assertFalse(TlsMode::isPublicHostname('127.0.0.1'));
        $this->assertFalse(TlsMode::isPublicHostname('app.test'));
        $this->assertFalse(TlsMode::isPublicHostname('localhost'));
        $this->assertFalse(TlsMode::isPublicHostname('box.local'));
    }

    public function test_auto_degrades_to_internal_for_non_public(): void
    {
        $this->assertSame(TlsMode::INTERNAL, TlsMode::effective(TlsMode::AUTO, 'app.test'));
        $this->assertSame(TlsMode::AUTO, TlsMode::effective(TlsMode::AUTO, 'app.example.com'));
        $this->assertSame(TlsMode::DNS01, TlsMode::effective(TlsMode::DNS01, 'app.test'));
    }

    public function test_normalize_aliases(): void
    {
        $this->assertSame(TlsMode::AUTO, TlsMode::normalize(true));
        $this->assertSame(TlsMode::OFF, TlsMode::normalize(false));
        $this->assertSame(TlsMode::INTERNAL, TlsMode::normalize('self-signed'));
        $this->assertSame(TlsMode::DNS01, TlsMode::normalize('dns'));
    }

    public function test_caddy_parser_detects_tls_modes(): void
    {
        $auto = <<<'CADDY'
example.com {
    root * /data/www/example.com
}
CADDY;
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/example.com.conf', $auto, []);
        $this->assertTrue($parsed['tls']);
        $this->assertSame('auto', $parsed['tls_mode']);

        $internal = <<<'CADDY'
dev.example.com {
    tls internal
    root * /data/www/dev.example.com
}
CADDY;
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/dev.example.com.conf', $internal, []);
        $this->assertSame('internal', $parsed['tls_mode']);

        $files = <<<'CADDY'
dns.example.com {
    tls /etc/letsencrypt/live/dns.example.com/fullchain.pem /etc/letsencrypt/live/dns.example.com/privkey.pem
    root * /data/www/dns.example.com
}
CADDY;
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/dns.example.com.conf', $files, []);
        $this->assertSame('dns01', $parsed['tls_mode']);
        $this->assertStringContainsString('fullchain.pem', (string) $parsed['tls_cert']);
    }

    public function test_classify_issuer(): void
    {
        $this->assertSame('lets_encrypt', TlsMode::classifyIssuer("CN=R3, O=Let's Encrypt, C=US", TlsMode::AUTO));
        $this->assertSame('dns01', TlsMode::classifyIssuer("CN=R3, O=Let's Encrypt, C=US", TlsMode::DNS01));
        $this->assertSame('self_signed', TlsMode::classifyIssuer('CN=Caddy Local Authority', TlsMode::INTERNAL));
    }
}
