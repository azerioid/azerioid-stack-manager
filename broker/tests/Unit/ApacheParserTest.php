<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Web\ApacheParser;
use PHPUnit\Framework\TestCase;

final class ApacheParserTest extends TestCase
{
    public function test_parses_php_vhost(): void
    {
        $contents = <<<'APACHE'
<VirtualHost *:80>
    ServerName shop.example.com
    DocumentRoot /data/www/shop.example.com
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
APACHE;
        $parsed = ApacheParser::parseFile('/etc/apache2/sites-available/shop.example.com.conf', $contents, []);
        $this->assertSame('shop.example.com', $parsed['domain']);
        $this->assertSame('php', $parsed['type']);
        $this->assertSame('8.4', $parsed['php_version']);
        $this->assertFalse($parsed['readonly']);
    }

    public function test_marks_proxy_readonly(): void
    {
        $contents = <<<'APACHE'
<VirtualHost *:80>
    ServerName app.example
    ProxyPass / http://127.0.0.1:9001/
    ProxyPassReverse / http://127.0.0.1:9001/
</VirtualHost>
APACHE;
        $parsed = ApacheParser::parseFile('/etc/apache2/sites-available/app.example.conf', $contents, []);
        $this->assertSame('proxy', $parsed['type']);
        $this->assertTrue($parsed['readonly']);
        $this->assertSame('127.0.0.1:9001', $parsed['reverse_proxy']);
    }

    public function test_marks_distro_default_files_readonly(): void
    {
        $ssl = <<<'APACHE'
<VirtualHost *:443>
    ServerName localhost
    SSLEngine on
    DocumentRoot /var/www/html
</VirtualHost>
APACHE;
        $parsed = ApacheParser::parseFile('/etc/apache2/sites-available/default-ssl.conf', $ssl, []);
        $this->assertTrue($parsed['readonly']);

        $plain = <<<'APACHE'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/html
</VirtualHost>
APACHE;
        $this->assertTrue(ApacheParser::parseFile('/etc/apache2/sites-available/000-default.conf', $plain, [])['readonly']);
        $this->assertTrue(ApacheParser::parseFile('/etc/apache2/sites-available/localhost.conf', $plain, [])['readonly']);
    }

    public function test_marks_panel_document_root_readonly(): void
    {
        $contents = <<<'APACHE'
<VirtualHost *:443>
    ServerName 203.0.113.20
    SSLEngine on
    DocumentRoot /usr/local/lib/azerioid-panel/web/public
</VirtualHost>
APACHE;
        $parsed = ApacheParser::parseFile('/etc/apache2/sites-available/azerioid-panel.conf', $contents, []);
        $this->assertTrue($parsed['readonly']);
        $this->assertSame('203.0.113.20', $parsed['domain']);
    }
}
