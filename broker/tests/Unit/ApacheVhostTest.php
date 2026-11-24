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
        $this->assertStringContainsString('proxy:unix:/run/php/azv-shop-example-com-8.4.sock', $rt->files[$path]);
        $this->assertStringContainsString("ProxyFCGISetEnvIf \"req('X-Forwarded-Proto') == 'https'\" REQUEST_SCHEME https", $rt->files[$path]);
        $this->assertStringContainsString('ProxyTimeout 35', $rt->files[$path]);
        $this->assertStringNotContainsString('php_fastcgi', $rt->files[$path]);
        $this->assertStringNotContainsString('SSLEngine', $rt->files[$path]);
        $this->assertStringNotContainsString('*:80', $rt->files[$path]);
        $this->assertSame('systemctl', $result['apply']['path']);
    }

    /**
     * A116: .htaccess cannot pick a handler (no FileInfo), forbidden directives are ignored rather
     * than fatal, PHP front controllers keep working; A115: repository and Composer state are 404.
     */
    public function test_apache_vhost_takes_no_handler_from_htaccess_and_hides_repository_state(): void
    {
        $rt = $this->runtime();
        $cfg = $this->lampConfig();
        (new ApacheDriver($cfg))->addVhost($rt, $cfg, ['domain' => 'shop.example.com', 'root' => '/data/www/shop.example.com', 'type' => 'php', 'php_version' => '8.4', 'upstream' => null]);
        $conf = $rt->files['/etc/apache2/sites-available/shop.example.com.conf'];
        $this->assertStringNotContainsString('AllowOverride All', $conf);
        $this->assertStringContainsString('AllowOverride AuthConfig Indexes Limit Nonfatal=Override', $conf);
        $this->assertStringContainsString('FallbackResource /index.php', $conf);
        $this->assertStringContainsString('RedirectMatch 404 "/\\.(git|composer)(/|$)"', $conf);
    }

    /**
     * A115/A116: an old backend vhost gets only the hardening lines, in place — its per-site PHP
     * socket and everything else stay, and a disabled vhost is not enabled.
     */
    public function test_converge_patches_an_old_backend_vhost_in_place_once(): void
    {
        $rt = $this->runtime();
        $cfg = $this->lampConfig();
        $path = '/etc/apache2/sites-available/shop.example.com.conf';
        $old = "# azerioid-backend engine=apache\n<VirtualHost 127.0.0.1:8081>\n    ServerName shop.example.com\n    DocumentRoot /data/www/shop.example.com\n"
            . "    <Directory /data/www/shop.example.com>\n        Options -Indexes +FollowSymLinks\n        AllowOverride All\n        Require all granted\n    </Directory>\n"
            . "    <FilesMatch \\.php\$>\n        SetHandler \"proxy:unix:/run/php/azv-shop-example-com-8.4.sock|fcgi://localhost\"\n    </FilesMatch>\n</VirtualHost>\n";
        $rt->files[$path] = $old;
        $rt->files['/etc/apache2/sites-available/other.conf'] = "# not ours\nAllowOverride All\n";
        $this->assertSame(['shop.example.com'], (new ApacheDriver($cfg))->patchHardening($rt, $cfg));
        $new = $rt->files[$path];
        $this->assertStringNotContainsString('AllowOverride All', $new);
        $this->assertStringContainsString("        AllowOverride AuthConfig Indexes Limit Nonfatal=Override\n        FallbackResource /index.php\n", $new);
        $this->assertStringContainsString("    </Directory>\n    RedirectMatch 404 \"/\\.(git|composer)(/|\$)\"\n", $new);
        $this->assertStringContainsString('proxy:unix:/run/php/azv-shop-example-com-8.4.sock', $new, 'the per-site socket is kept');
        $this->assertSame("# not ours\nAllowOverride All\n", $rt->files['/etc/apache2/sites-available/other.conf']);
        $this->assertNull(array_filter(array_column($rt->execLog, 'command'), static fn (array $c): bool => ($c[0] ?? '') === '/usr/sbin/a2ensite') ?: null, 'nothing is enabled');
        $this->assertSame([], (new ApacheDriver($cfg))->patchHardening($rt, $cfg), 'idempotent');
    }

    public function test_converge_puts_apache_files_back_when_the_config_test_fails(): void
    {
        $rt = $this->runtime();
        $rt->script(['/usr/sbin/apachectl', '-t'], 1, '', 'Syntax error');
        $cfg = $this->lampConfig();
        $path = '/etc/apache2/sites-available/shop.example.com.conf';
        $old = "# azerioid-backend engine=apache\n<Directory /x>\n        AllowOverride All\n    </Directory>\n";
        $rt->files[$path] = $old;
        try {
            (new ApacheDriver($cfg))->patchHardening($rt, $cfg);
            $this->fail('expected refusal');
        } catch (\AzerioidPanel\Broker\BrokerException $e) {
            $this->assertStringContainsString('put back', $e->getMessage());
        }
        $this->assertSame($old, $rt->files[$path]);
    }

    public function test_nginx_backend_gets_the_hidden_path_block_once_and_proxies_none(): void
    {
        $old = "# azerioid-backend engine=nginx\nserver {\n    root /data/www/s;\n    location / {\n        try_files \$uri =404;\n    }\n}\n";
        $new = \AzerioidPanel\Broker\Web\NginxDriver::hardenedBody($old);
        $this->assertStringContainsString("    location ~ /\\.(git|composer)(/|\$) {\n        return 404;\n    }\n\n    location / {", $new);
        $this->assertSame($new, \AzerioidPanel\Broker\Web\NginxDriver::hardenedBody($new));
        $proxy = "# azerioid-backend engine=nginx\nserver {\n    location / {\n        proxy_pass http://127.0.0.1:3000;\n    }\n}\n";
        $this->assertSame($proxy, \AzerioidPanel\Broker\Web\NginxDriver::hardenedBody($proxy));
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
        $panel = "<VirtualHost *:443>\n    ServerName 203.0.113.20\n    SSLEngine on\n    DocumentRoot /usr/local/lib/azerioid-panel/web/public\n</VirtualHost>\n";
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
        $this->assertTrue($byDomain['203.0.113.20']['readonly']);
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
        $this->assertStringContainsString('/run/php/azv-shop-example-com-8.3.sock', $conf);
        $pools = array_filter(array_keys($rt->files), static fn (string $p): bool => str_ends_with($p, '/azv-shop-example-com.conf'));
        $this->assertCount(1, $pools, 'the pool of the new version');
        $this->assertStringContainsString('8.3', (string) reset($pools));
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
