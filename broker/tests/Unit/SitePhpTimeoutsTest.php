<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Network\SiteHttpFirewall;
use AzerioidPanel\Broker\Php\SitePhpTimeouts;
use PHPUnit\Framework\TestCase;

final class SitePhpTimeoutsTest extends TestCase
{
    public function test_patches_commented_www_pool_timeout(): void
    {
        $pool = <<<'CONF'
[www]
user = www-data
;request_terminate_timeout = 0
pm = dynamic
CONF;
        $out = (new SitePhpTimeouts())->patchPool($pool, false);
        $this->assertStringContainsString('request_terminate_timeout = 30', $out);
        $this->assertStringNotContainsString(';request_terminate_timeout', $out);
        $this->assertStringContainsString('php_admin_value[max_execution_time] = 30', $out);
    }

    public function test_el_www_acl_includes_caddy(): void
    {
        $pool = <<<'CONF'
[www]
user = apache
listen = /run/php-fpm/www.sock
listen.acl_users = apache,nginx
CONF;
        $out = (new SitePhpTimeouts())->patchPool($pool, false, ['caddy']);
        $this->assertMatchesRegularExpression('/^listen\.acl_users = apache,nginx,caddy$/m', $out);
        $this->assertSame($out, (new SitePhpTimeouts())->patchPool($out, false, ['caddy']));
    }

    public function test_panel_pool_uses_longer_bound(): void
    {
        $out = (new SitePhpTimeouts())->patchPool("[azerioid-panel]\nuser = caddy\n", true);
        $this->assertStringContainsString('request_terminate_timeout = 60', $out);
        $this->assertStringContainsString('php_admin_value[max_execution_time] = 60', $out);
    }

    public function test_caddy_rewrite_is_idempotent(): void
    {
        $bare = "example.com {\n    php_fastcgi unix//run/php/php8.4-fpm.sock\n    file_server\n}\n";
        $once = SitePhpTimeouts::rewriteCaddyPhpFastcgi($bare);
        $this->assertStringContainsString('dial_timeout 10s', $once);
        $this->assertStringContainsString('read_timeout 35s', $once);
        $twice = SitePhpTimeouts::rewriteCaddyPhpFastcgi($once);
        $this->assertSame($once, $twice);
    }

    public function test_nginx_and_apache_rewrites(): void
    {
        $nginx = "    fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n";
        $out = SitePhpTimeouts::rewriteNginxFastcgi($nginx);
        $this->assertStringContainsString('fastcgi_read_timeout 35s;', $out);
        $again = SitePhpTimeouts::rewriteNginxFastcgi($out);
        $this->assertSame($out, $again);

        $apache = "<VirtualHost *:80>\n    SetHandler \"proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost\"\n</VirtualHost>\n";
        $a1 = SitePhpTimeouts::rewriteApacheProxy($apache);
        $this->assertStringContainsString('ProxyTimeout 35', $a1);
        $this->assertSame($a1, SitePhpTimeouts::rewriteApacheProxy($a1));
    }

    public function test_kernel_ensure_patches_pool_ini_and_caddy_vhost(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/php/8.4/fpm/pool.d/www.conf'] = "[www]\n;request_terminate_timeout = 0\n";
        $rt->files['/etc/php/8.4/fpm/php.ini'] = "max_execution_time = 0\n";
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->files['/etc/caddy/conf.d/let.az.conf'] = "let.az {\n    php_fastcgi unix//run/php/php8.4-fpm.sock\n}\n";
        $rt->script(['/usr/bin/systemctl', 'is-active', 'php8.4-fpm'], 0, "active\n");
        $rt->script(['/usr/bin/systemctl', 'reload', 'php8.4-fpm'], 0);
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);

        ob_start();
        $code = (new Kernel(new Config(), $rt))->run(['broker', 'php.timeouts.ensure'], []);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $json = json_decode($out, true);
        $this->assertTrue($json['ok']);
        $this->assertStringContainsString('request_terminate_timeout = 30', $rt->files['/etc/php/8.4/fpm/pool.d/www.conf']);
        $this->assertStringContainsString('max_execution_time = 30', $rt->files['/etc/php/8.4/fpm/php.ini']);
        $this->assertStringContainsString('read_timeout 35s', $rt->files['/etc/caddy/conf.d/let.az.conf']);
        $this->assertContains('/etc/php/8.4/fpm/pool.d/www.conf', $json['data']['timeouts']['pools']);
    }

    public function test_http_firewall_opens_80_and_443_when_ufw_active(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/usr/sbin/ufw'] = '';
        $rt->script(['/usr/sbin/ufw', 'status'], 0, "Status: active\n");
        $result = (new SiteHttpFirewall($rt))->ensure();
        $this->assertTrue($result['applied']);
        $commands = array_map(static fn (array $row) => $row['command'], $rt->execLog);
        $this->assertContains(['/usr/sbin/ufw', 'allow', '80/tcp', 'comment', 'azerioid-http'], $commands);
        $this->assertContains(['/usr/sbin/ufw', 'allow', '443/tcp', 'comment', 'azerioid-https'], $commands);
    }

    public function test_vhost_add_renders_caddy_php_fastcgi_timeouts(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        ob_start();
        $code = (new Kernel(new Config(), $rt))->run(
            ['broker', 'vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4'],
            []
        );
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $conf = $rt->files['/etc/caddy/conf.d/shop.example.com.conf'];
        $this->assertStringContainsString('php_fastcgi unix//run/php/php8.4-fpm.sock', $conf);
        $this->assertStringContainsString('read_timeout 35s', $conf);
    }

    public function test_apache_vhost_add_includes_proxy_timeout(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/etc/apache2'] = true;
        $rt->dirs['/etc/apache2/sites-available'] = true;
        $rt->dirs['/etc/apache2/sites-enabled'] = true;
        $rt->dirs['/var/log/apache2'] = true;
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->files['/etc/apache2/ports.conf'] = "Listen 80\n";
        $rt->files['/usr/sbin/apachectl'] = '';
        $rt->files['/usr/sbin/a2ensite'] = '';
        $rt->files['/usr/sbin/a2enmod'] = '';
        $rt->files['/usr/sbin/a2dissite'] = '';
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/sbin/apachectl', '-t'], 0, 'Syntax OK');
        $rt->script(['/usr/sbin/a2ensite', 'shop.example.com'], 0);
        $rt->script(['/usr/bin/systemctl', 'reload', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'restart', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'apache2'], 0, "active\n");
        $rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);

        $cfg = new Config();
        $cfg->stack = 'lamp';
        $cfg->webServer = 'apache';
        $cfg->webService = 'apache2';
        $cfg->vhostFormat = 'apache';
        $cfg->vhostDir = '/etc/apache2/sites-enabled';
        $cfg->vhostAvailableDir = '/etc/apache2/sites-available';
        $cfg->webLogDir = '/var/log/apache2';
        $cfg->phpUser = 'www-data';
        $cfg->phpGroup = 'www-data';
        $cfg->webUser = 'www-data';

        ob_start();
        $code = (new Kernel($cfg, $rt))->run(
            ['broker', 'vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4'],
            ['engine' => 'apache']
        );
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $conf = $rt->files['/etc/apache2/sites-available/shop.example.com.conf'];
        $this->assertStringContainsString('ProxyTimeout 35', $conf);
        $this->assertStringContainsString('Timeout 35', $conf);
    }
}
