<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Web\BackendEngineBind;
use AzerioidPanel\Broker\Web\VhostEngine;
use PHPUnit\Framework\TestCase;

final class FrontRouterTest extends TestCase
{
    private function caddyRuntime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'caddy'], 0, "active\n");
        return $rt;
    }

    private function withApache(FakeRuntime $rt): void
    {
        $rt->dirs['/etc/apache2'] = true;
        $rt->dirs['/etc/apache2/sites-available'] = true;
        $rt->dirs['/etc/apache2/sites-enabled'] = true;
        $rt->dirs['/etc/apache2/conf-available'] = true;
        $rt->dirs['/etc/apache2/conf-enabled'] = true;
        $rt->dirs['/var/log/apache2'] = true;
        $rt->files['/etc/apache2/ports.conf'] = "Listen 80\n<IfModule ssl_module>\n\tListen 443\n</IfModule>\n";
        $rt->files['/usr/sbin/apachectl'] = '';
        $rt->files['/usr/sbin/apache2ctl'] = '';
        $rt->files['/usr/sbin/a2ensite'] = '';
        $rt->files['/usr/sbin/a2dissite'] = '';
        $rt->files['/usr/sbin/a2enmod'] = '';
        $rt->files['/usr/sbin/a2enconf'] = '';
        $rt->script(['/usr/sbin/apachectl', '-t'], 0, 'Syntax OK');
        $rt->script(['/usr/sbin/apache2ctl', '-t'], 0, 'Syntax OK');
        $rt->script(['/usr/bin/systemctl', 'reload', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'restart', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'apache2'], 0, "active\n");
    }

    private function withNginx(FakeRuntime $rt): void
    {
        $rt->dirs['/etc/nginx'] = true;
        $rt->dirs['/etc/nginx/sites-available'] = true;
        $rt->dirs['/etc/nginx/sites-enabled'] = true;
        $rt->dirs['/etc/nginx/conf.d'] = true;
        $rt->dirs['/var/log/nginx'] = true;
        $rt->files['/usr/sbin/nginx'] = '';
        $rt->files['/etc/nginx/sites-enabled/default'] = "server { listen 80; server_name _; }\n";
        $rt->script(['/usr/sbin/nginx', '-t'], 0, 'syntax is ok');
        $rt->script(['/usr/bin/systemctl', 'reload', 'nginx'], 0);
        $rt->script(['/usr/bin/systemctl', 'restart', 'nginx'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'nginx'], 0, "active\n");
    }

    public function test_caddy_engine_serves_directly(): void
    {
        $rt = $this->caddyRuntime();
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'vhost.add', 'example.az', '/data/www/example.az', 'php', '8.4'], []);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $conf = $rt->files['/etc/caddy/conf.d/example.az.conf'];
        $this->assertStringContainsString('engine=caddy', $conf);
        $this->assertStringContainsString('php_fastcgi', $conf);
        $this->assertStringNotContainsString('127.0.0.1:8081', $conf);
        $this->assertStringNotContainsString('127.0.0.1:8082', $conf);
    }

    public function test_apache_engine_writes_caddy_reverse_proxy_and_backend(): void
    {
        $rt = $this->caddyRuntime();
        $this->withApache($rt);
        $rt->script(['/usr/sbin/a2ensite', 'abc.az'], 0, 'Enabling site abc.az');
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'abc.az', '/data/www/abc.az', 'php', '8.4'],
            ['engine' => 'apache']
        );
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $front = $rt->files['/etc/caddy/conf.d/abc.az.conf'];
        $this->assertStringContainsString('engine=apache', $front);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:8081', $front);
        $this->assertStringContainsString('header_up X-Forwarded-For {http.request.remote.host}', $front);
        $this->assertStringContainsString('header_up X-Forwarded-Proto {http.request.scheme}', $front);
        $this->assertStringContainsString('header_up X-Forwarded-Host {http.request.host}', $front);
        $this->assertStringContainsString('header_up X-Real-IP {http.request.remote.host}', $front);
        $this->assertStringNotContainsString('php_fastcgi', $front);
        $backend = $rt->files['/etc/apache2/sites-available/abc.az.conf'];
        $this->assertStringContainsString('<VirtualHost 127.0.0.1:8081>', $backend);
        $this->assertStringContainsString('ServerName abc.az', $backend);
        $this->assertStringContainsString('php8.4-fpm.sock', $backend);
        $this->assertStringContainsString('SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on', $backend);
        $this->assertStringContainsString("ProxyFCGISetEnvIf \"req('X-Forwarded-Proto') == 'https'\" HTTPS on", $backend);
        $this->assertStringContainsString("ProxyFCGISetEnvIf \"req('X-Forwarded-Proto') == 'https'\" REQUEST_SCHEME https", $backend);
        $this->assertStringContainsString('RemoteIPHeader X-Forwarded-For', $rt->files['/etc/apache2/conf-available/azerioid-backend.conf']);
        $this->assertStringContainsString('RemoteIPInternalProxy 127.0.0.1', $rt->files['/etc/apache2/conf-available/azerioid-backend.conf']);
        $this->assertStringContainsString('SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on', $rt->files['/etc/apache2/conf-available/azerioid-backend.conf']);
        $this->assertStringNotContainsString('RemoteIPInternalProxy 0.0.0.0', $rt->files['/etc/apache2/conf-available/azerioid-backend.conf']);
        $parsed = CaddyParser::parseFile('/etc/caddy/conf.d/abc.az.conf', $front, []);
        $this->assertSame('php', $parsed['type']);
        $this->assertSame('apache', $parsed['engine']);
        $this->assertSame('8.4', $parsed['php_version']);
        $this->assertSame('/data/www/abc.az', $parsed['root']);
    }

    public function test_nginx_engine_writes_loopback_backend(): void
    {
        $rt = $this->caddyRuntime();
        $this->withNginx($rt);
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'xyz.az', '/data/www/xyz.az', 'static', null],
            ['engine' => 'nginx']
        );
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $front = $rt->files['/etc/caddy/conf.d/xyz.az.conf'];
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:8082', $front);
        $this->assertStringContainsString('engine=nginx', $front);
        $backend = $rt->files['/etc/nginx/sites-available/xyz.az.conf'];
        $this->assertStringContainsString('listen 127.0.0.1:8082', $backend);
        $this->assertStringContainsString('server_name xyz.az', $backend);
        $this->assertArrayNotHasKey('/etc/nginx/sites-enabled/default', $rt->files);
        $http = $rt->files['/etc/nginx/conf.d/00-azerioid-backend.conf'];
        $this->assertStringContainsString('set_real_ip_from 127.0.0.1', $http);
        $this->assertStringContainsString('real_ip_header X-Forwarded-For', $http);
        $this->assertStringNotContainsString('set_real_ip_from 0.0.0.0', $http);
    }

    public function test_nginx_php_backend_passes_https_from_forwarded_proto(): void
    {
        $rt = $this->caddyRuntime();
        $this->withNginx($rt);
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'xyz.az', '/data/www/xyz.az', 'php', '8.4'],
            ['engine' => 'nginx']
        );
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $backend = $rt->files['/etc/nginx/sites-available/xyz.az.conf'];
        $this->assertStringContainsString('fastcgi_param HTTPS $azerioid_https', $backend);
        $this->assertStringContainsString('fastcgi_param REQUEST_SCHEME $azerioid_scheme', $backend);
        $http = $rt->files['/etc/nginx/conf.d/00-azerioid-backend.conf'];
        $this->assertStringContainsString('map $http_x_forwarded_proto $azerioid_https', $http);
    }

    public function test_engine_switch_apache_to_nginx_removes_old_backend(): void
    {
        $rt = $this->caddyRuntime();
        $this->withApache($rt);
        $this->withNginx($rt);
        $rt->script(['/usr/sbin/a2ensite', 'abc.az'], 0, 'Enabling site abc.az');
        $rt->script(['/usr/sbin/a2dissite', 'abc.az'], 0);
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $this->assertSame(0, $kernel->run(
            ['broker', 'vhost.add', 'abc.az', '/data/www/abc.az', 'php', '8.4'],
            ['engine' => 'apache']
        ));
        ob_end_clean();
        $this->assertArrayHasKey('/etc/apache2/sites-available/abc.az.conf', $rt->files);

        ob_start();
        $code = $kernel->run(['broker', 'vhost.edit', 'abc.az'], ['engine' => 'nginx']);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $front = $rt->files['/etc/caddy/conf.d/abc.az.conf'];
        $this->assertStringContainsString('engine=nginx', $front);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:8082', $front);
        $this->assertArrayHasKey('/etc/nginx/sites-available/abc.az.conf', $rt->files);
        $this->assertArrayNotHasKey('/etc/apache2/sites-available/abc.az.conf', $rt->files);
    }

    public function test_migrate_does_not_steal_existing_caddy_vhost(): void
    {
        $rt = $this->caddyRuntime();
        $this->withNginx($rt);
        $rt->files['/etc/caddy/conf.d/let.az.conf'] = <<<'CADDY'
# azerioid-managed engine=caddy type=php php=8.4 root=/data/www/let.az
let.az {
    root * /data/www/let.az
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}
CADDY;
        $rt->files['/etc/nginx/sites-available/let.az.conf'] = "server {\n    listen 80;\n    server_name let.az;\n    root /data/www/let.az;\n}\n";
        $rt->files['/etc/nginx/sites-enabled/let.az.conf'] = $rt->files['/etc/nginx/sites-available/let.az.conf'];
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'web.front-router.migrate'], []);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $front = $rt->files['/etc/caddy/conf.d/let.az.conf'];
        $this->assertStringContainsString('engine=caddy', $front);
        $this->assertStringContainsString('php_fastcgi', $front);
        $this->assertStringNotContainsString('127.0.0.1:8082', $front);
        $this->assertArrayNotHasKey('/etc/nginx/sites-available/let.az.conf', $rt->files);
        $this->assertArrayNotHasKey('/etc/nginx/sites-enabled/let.az.conf', $rt->files);
    }

    public function test_apache_engine_requires_apache_installed(): void
    {
        $rt = $this->caddyRuntime();
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'abc.az', '/data/www/abc.az', 'php', '8.4'],
            ['engine' => 'apache']
        );
        $out = ob_get_clean();
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Apache is not installed', $out);
        $this->assertStringContainsString('Install it from Components before creating an Apache-engine vhost', $out);
        $this->assertStringNotContainsString('Directory does not exist', $out);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/abc.az.conf', $rt->files);
    }

    public function test_apache_leftover_config_dir_is_not_an_install(): void
    {
        $rt = $this->caddyRuntime();
        $rt->dirs['/etc/apache2'] = true;
        $rt->dirs['/etc/apache2/conf-available'] = true;
        $rt->dirs['/etc/apache2/sites-available'] = true;
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'leftover.az', '/data/www/leftover.az', 'php', '8.4'],
            ['engine' => 'apache']
        );
        $out = ob_get_clean();
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Apache is not installed', $out);
        $this->assertStringNotContainsString('Directory does not exist', $out);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/leftover.az.conf', $rt->files);
    }

    public function test_nginx_engine_requires_nginx_installed(): void
    {
        $rt = $this->caddyRuntime();
        $rt->dirs['/etc/nginx'] = true;
        $rt->dirs['/etc/nginx/sites-available'] = true;
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'ngx.az', '/data/www/ngx.az', 'php', '8.4'],
            ['engine' => 'nginx']
        );
        $out = ob_get_clean();
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Nginx is not installed', $out);
        $this->assertStringContainsString('Install it from Components before creating an Nginx-engine vhost', $out);
        $this->assertStringNotContainsString('Directory does not exist', $out);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/ngx.az.conf', $rt->files);
    }

    public function test_backend_bind_rewrites_ports_conf(): void
    {
        $rt = new FakeRuntime();
        $this->withApache($rt);
        $cfg = new Config();
        $result = (new BackendEngineBind($rt, $cfg))->ensure(VhostEngine::APACHE);
        $this->assertSame('127.0.0.1:8081', $result['listen']);
        $this->assertStringContainsString('Listen 127.0.0.1:8081', $rt->files['/etc/apache2/ports.conf']);
        $this->assertStringContainsString('# AZERIOID-disabled Listen 80', $rt->files['/etc/apache2/ports.conf']);
        $this->assertStringNotContainsString(
            'Listen 127.0.0.1:8081',
            $rt->files['/etc/apache2/conf-available/azerioid-backend.conf'] ?? ''
        );
        $remoteip = $rt->files['/etc/apache2/conf-available/azerioid-backend.conf'];
        $this->assertStringContainsString('RemoteIPHeader X-Forwarded-For', $remoteip);
        $this->assertStringContainsString('RemoteIPInternalProxy 127.0.0.1', $remoteip);
        $this->assertStringNotContainsString('RemoteIPInternalProxy 0.0.0.0', $remoteip);
    }

    public function test_backend_bind_relabels_selinux_http_ports_when_enforcing(): void
    {
        $rt = new FakeRuntime();
        $this->withApache($rt);
        $rt->files['/usr/sbin/semanage'] = '';
        $rt->files['/usr/sbin/getenforce'] = '';
        $rt->script(['/usr/sbin/getenforce'], 0, "Enforcing\n");
        $rt->script(['/usr/sbin/semanage', 'port', '-a', '-t', 'http_port_t', '-p', 'tcp', '8081'], 1, '', 'already defined');
        $rt->script(['/usr/sbin/semanage', 'port', '-m', '-t', 'http_port_t', '-p', 'tcp', '8081'], 0);
        (new BackendEngineBind($rt, new Config()))->ensure(VhostEngine::APACHE);
        $cmds = array_map(static fn ($row) => $row['command'], $rt->execLog);
        $this->assertContains(['/usr/sbin/semanage', 'port', '-m', '-t', 'http_port_t', '-p', 'tcp', '8081'], $cmds);
    }

    public function test_backend_bind_rewrites_el_nginx_conf_listen_80(): void
    {
        $rt = new FakeRuntime();
        $this->withNginx($rt);
        $rt->files['/etc/nginx/nginx.conf'] = <<<'NGINX'
http {
    include /etc/nginx/conf.d/*.conf;
    server {
        listen       80;
        listen       [::]:80;
        server_name  _;
        root         /usr/share/nginx/html;
    }
}
NGINX;
        $result = (new BackendEngineBind($rt, new Config()))->ensure(VhostEngine::NGINX);
        $this->assertSame('127.0.0.1:8082', $result['listen']);
        $conf = $rt->files['/etc/nginx/nginx.conf'];
        $this->assertStringContainsString('listen 127.0.0.1:8082;', $conf);
        $this->assertStringContainsString('# AZERIOID-disabled listen [::]:80;', $conf);
        $this->assertDoesNotMatchRegularExpression('/^\s*listen\s+80\s*;/m', $conf);
    }

    public function test_release_site_ports_is_deprecated_noop(): void
    {
        $rt = $this->caddyRuntime();
        $original = $rt->files['/etc/caddy/Caddyfile'];
        $rt->files['/etc/caddy/conf.d/let.az.conf'] = "let.az {\n    root * /data/www/let.az\n}\n";
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $code = $kernel->run(['broker', 'web.release-site-ports'], []);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['data']['deprecated']);
        $this->assertFalse($decoded['data']['released']);
        $this->assertSame($original, $rt->files['/etc/caddy/Caddyfile']);
        $this->assertArrayHasKey('/etc/caddy/conf.d/let.az.conf', $rt->files);
    }

    public function test_tls_auto_is_always_caddy_native(): void
    {
        $rt = $this->caddyRuntime();
        $this->withApache($rt);
        $rt->script(['/usr/sbin/a2ensite', 'abc.az'], 0, 'Enabling site abc.az');
        $kernel = new Kernel(new Config(), $rt);
        ob_start();
        $this->assertSame(0, $kernel->run(
            ['broker', 'vhost.add', 'abc.az', '/data/www/abc.az', 'php', '8.4'],
            ['engine' => 'apache']
        ));
        ob_end_clean();
        ob_start();
        $code = $kernel->run(['broker', 'vhost.edit', 'abc.az'], ['tls_mode' => 'auto']);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('caddy_native', $decoded['data']['tls_issue']['path'] ?? null);
        $this->assertStringNotContainsString('certbot', json_encode($decoded['data']['tls_issue'] ?? []));
        $front = $rt->files['/etc/caddy/conf.d/abc.az.conf'];
        $this->assertStringContainsString('abc.az {', $front);
        $this->assertStringNotContainsString('http://abc.az', $front);
        $backend = $rt->files['/etc/apache2/sites-available/abc.az.conf'];
        $this->assertStringNotContainsString('SSLEngine', $backend);
    }
}
