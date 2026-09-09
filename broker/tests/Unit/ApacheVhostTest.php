<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Web\ApacheDriver;
use PHPUnit\Framework\TestCase;

final class ApacheVhostTest extends TestCase
{
    private function lampConfig(): Config
    {
        $cfg = new Config();
        $cfg->stack = 'lamp';
        $cfg->webServer = 'apache';
        $cfg->webService = 'apache2';
        $cfg->vhostFormat = 'apache';
        $cfg->vhostDir = '/etc/apache2/sites-enabled';
        $cfg->vhostAvailableDir = '/etc/apache2/sites-available';
        $cfg->webLogDir = '/var/log/apache2';
        $cfg->controllableServices = ['apache2', 'mariadb'];
        $cfg->phpUser = 'www-data';
        $cfg->phpGroup = 'www-data';
        $cfg->webUser = 'www-data';
        return $cfg;
    }

    private function runtime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->dirs['/etc/apache2'] = true;
        $rt->dirs['/etc/apache2/sites-available'] = true;
        $rt->dirs['/etc/apache2/sites-enabled'] = true;
        $rt->dirs['/var/log/apache2'] = true;
        $rt->files['/usr/sbin/apachectl'] = '';
        $rt->files['/usr/sbin/a2ensite'] = '';
        $rt->files['/usr/sbin/a2dissite'] = '';
        $rt->script(['/usr/sbin/apachectl', '-t'], 0, 'Syntax OK');
        $rt->script(['/usr/sbin/a2ensite', 'shop.example.com'], 0, 'Enabling site shop.example.com');
        $rt->script(['/usr/sbin/a2dissite', 'shop.example.com'], 0);
        $rt->script(['/usr/bin/systemctl', 'reload', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'apache2'], 0, "active\n");
        return $rt;
    }

    public function test_adds_php_vhost_as_loopback_backend(): void
    {
        $rt = $this->runtime();
        $cfg = $this->lampConfig();
        $driver = new ApacheDriver($cfg);
        $result = $driver->addVhost($rt, $cfg, [
            'domain' => 'shop.example.com',
            'root' => '/data/www/shop.example.com',
            'type' => 'php',
            'php_version' => '8.4',
            'upstream' => null,
        ]);
        $path = '/etc/apache2/sites-available/shop.example.com.conf';
        $this->assertArrayHasKey($path, $rt->files);
        $this->assertStringContainsString('ServerName shop.example.com', $rt->files[$path]);
        $this->assertStringContainsString('<VirtualHost 127.0.0.1:8081>', $rt->files[$path]);
        $this->assertStringContainsString('proxy:unix:/run/php/php8.4-fpm.sock', $rt->files[$path]);
        $this->assertStringContainsString("ProxyFCGISetEnvIf \"req('X-Forwarded-Proto') == 'https'\" REQUEST_SCHEME https", $rt->files[$path]);
        $this->assertStringContainsString('ProxyTimeout 35', $rt->files[$path]);
        $this->assertStringNotContainsString('php_fastcgi', $rt->files[$path]);
        $this->assertStringNotContainsString('SSLEngine', $rt->files[$path]);
        $this->assertStringNotContainsString('*:80', $rt->files[$path]);
        $this->assertSame('systemctl', $result['apply']['path']);
    }

    public function test_rolls_back_when_apachectl_fails(): void
    {
        $rt = $this->runtime();
        $rt->script(['/usr/sbin/apachectl', '-t'], 1, '', 'AH00526: Syntax error');
        $cfg = $this->lampConfig();
        $driver = new ApacheDriver($cfg);
        try {
            $driver->addVhost($rt, $cfg, [
                'domain' => 'shop.example.com',
                'root' => '/data/www/shop.example.com',
                'type' => 'php',
                'php_version' => '8.4',
                'upstream' => null,
            ]);
            $this->fail('expected BrokerException');
        } catch (\AzerioidPanel\Broker\BrokerException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }
        $this->assertArrayNotHasKey('/etc/apache2/sites-available/shop.example.com.conf', $rt->files);
    }

    public function test_deletes_apache_vhost(): void
    {
        $rt = $this->runtime();
        $rt->files['/etc/apache2/sites-available/shop.example.com.conf'] = "<VirtualHost 127.0.0.1:8081>\n    ServerName shop.example.com\n    DocumentRoot /data/www/shop.example.com\n</VirtualHost>\n";
        $rt->files['/etc/apache2/sites-enabled/shop.example.com.conf'] = $rt->files['/etc/apache2/sites-available/shop.example.com.conf'];
        $cfg = $this->lampConfig();
        (new ApacheDriver($cfg))->removeVhost($rt, $cfg, 'shop.example.com');
        $this->assertArrayNotHasKey('/etc/apache2/sites-available/shop.example.com.conf', $rt->files);
        $this->assertArrayNotHasKey('/etc/apache2/sites-enabled/shop.example.com.conf', $rt->files);
    }

    public function test_lists_each_vhost_once_despite_available_and_enabled(): void
    {
        $rt = $this->runtime();
        $panel = "<VirtualHost *:443>\n    ServerName 157.245.84.199\n    SSLEngine on\n    DocumentRoot /usr/local/lib/azerioid-panel/web/public\n</VirtualHost>\n";
        $ssl = "<VirtualHost *:443>\n    ServerName localhost\n    SSLEngine on\n    DocumentRoot /var/www/html\n</VirtualHost>\n";
        $user = "<VirtualHost 127.0.0.1:8081>\n    ServerName shop.example.com\n    DocumentRoot /data/www/shop.example.com\n</VirtualHost>\n";
        $disabled = "<VirtualHost 127.0.0.1:8081>\n    ServerName idle.example.com\n    DocumentRoot /data/www/idle.example.com\n</VirtualHost>\n";
        foreach (['azerioid-panel' => $panel, 'default-ssl' => $ssl, 'shop.example.com' => $user] as $name => $body) {
            $rt->files['/etc/apache2/sites-available/' . $name . '.conf'] = $body;
            $rt->files['/etc/apache2/sites-enabled/' . $name . '.conf'] = $body;
        }
        $rt->files['/etc/apache2/sites-available/idle.example.com.conf'] = $disabled;

        $cfg = $this->lampConfig();
        $vhosts = (new ApacheDriver($cfg))->listVhosts($rt, $cfg);
        $domains = array_column($vhosts, 'domain');
        $this->assertSame(array_values(array_unique($domains)), $domains);
        $this->assertCount(4, $vhosts);
        $byDomain = [];
        foreach ($vhosts as $row) {
            $byDomain[$row['domain']] = $row;
        }
        $this->assertTrue($byDomain['157.245.84.199']['readonly']);
        $this->assertTrue($byDomain['localhost']['readonly']);
        $this->assertFalse($byDomain['shop.example.com']['readonly']);
        $this->assertTrue($byDomain['shop.example.com']['enabled']);
        $this->assertFalse($byDomain['idle.example.com']['enabled']);
        $this->assertFalse($byDomain['idle.example.com']['readonly']);
    }

    public function test_edits_apache_php_vhost(): void
    {
        $rt = $this->runtime();
        $rt->files['/etc/apache2/sites-available/shop.example.com.conf'] = "<VirtualHost 127.0.0.1:8081>\n    ServerName shop.example.com\n    DocumentRoot /data/www/shop.example.com\n    <FilesMatch \\.php\$>\n        SetHandler \"proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost\"\n    </FilesMatch>\n</VirtualHost>\n";
        $rt->files['/etc/apache2/sites-enabled/shop.example.com.conf'] = $rt->files['/etc/apache2/sites-available/shop.example.com.conf'];
        $rt->dirs['/data/www/shop.example.com'] = true;
        $cfg = $this->lampConfig();
        $result = (new ApacheDriver($cfg))->updateVhost($rt, $cfg, 'shop.example.com', ['php_version' => '8.3']);

        $conf = $rt->files['/etc/apache2/sites-available/shop.example.com.conf'];
        $this->assertStringContainsString('php8.3-fpm.sock', $conf);
        $this->assertStringNotContainsString('SSLEngine on', $conf);
        $this->assertSame('8.4', $result['before']['php_version']);
        $this->assertSame('8.3', $result['after']['php_version']);
    }

    public function test_apache_edit_rolls_back_on_configtest_failure(): void
    {
        $rt = $this->runtime();
        $original = "<VirtualHost 127.0.0.1:8081>\n    ServerName shop.example.com\n    DocumentRoot /data/www/shop.example.com\n</VirtualHost>\n";
        $rt->files['/etc/apache2/sites-available/shop.example.com.conf'] = $original;
        $rt->files['/etc/apache2/sites-enabled/shop.example.com.conf'] = $original;
        $rt->script(['/usr/sbin/apachectl', '-t'], 1, '', 'Syntax error');
        $cfg = $this->lampConfig();

        try {
            (new ApacheDriver($cfg))->updateVhost($rt, $cfg, 'shop.example.com', [
                'root' => '/data/www/shop-moved.example.com',
            ]);
            $this->fail('expected BrokerException');
        } catch (\AzerioidPanel\Broker\BrokerException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
        }
        $this->assertSame($original, $rt->files['/etc/apache2/sites-available/shop.example.com.conf']);
    }
}
