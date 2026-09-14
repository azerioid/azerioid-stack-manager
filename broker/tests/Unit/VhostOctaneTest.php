<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Vhost\OctaneManager;
use PHPUnit\Framework\TestCase;

final class VhostOctaneTest extends TestCase
{
    private const DOMAIN = 'app.example.com';
    private const APP_DIR = '/data/www/app.example.com';
    private const DOCROOT = '/data/www/app.example.com/public';
    private const CONF = '/etc/caddy/conf.d/app.example.com.conf';

    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';

        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files[self::CONF] = $this->fpmVhost();
        $this->rt->dirs[self::APP_DIR] = true;
        $this->rt->dirs[self::DOCROOT] = true;
        $this->seedLaravelApp();

        $this->rt->files[$this->cfg->managedComponentsPath] = json_encode([
            'components' => ['supervisor' => ['unit' => 'supervisor', 'installed_at' => '2026-01-01']],
        ], JSON_THROW_ON_ERROR);
        $this->rt->dirs['/etc/supervisor/conf.d'] = true;
        $this->rt->dirs['/var/lib/azerioid-supervised'] = true;
        $this->rt->dirs['/var/log/azerioid-supervised'] = true;

        $this->rt->files['/usr/sbin/runuser'] = '';
        $this->rt->files['/usr/bin/php8.4'] = '';
        $this->rt->files['/usr/local/bin/composer'] = '';

        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);
        $this->mockSupervisorctlOk();

        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    private function fpmVhost(): string
    {
        return <<<'CADDY'
# azerioid-managed engine=caddy type=php php=8.4 root=/data/www/app.example.com/public
app.example.com {
    root * /data/www/app.example.com/public
    encode gzip zstd
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server {
        index index.html index.php
    }
}

CADDY;
    }

    private function seedLaravelApp(): void
    {
        $this->rt->files[self::APP_DIR . '/artisan'] = "#!/usr/bin/env php\n";
        $this->rt->dirs[self::APP_DIR . '/vendor'] = true;
        $this->rt->dirs[self::APP_DIR . '/vendor/laravel'] = true;
        $this->rt->dirs[self::APP_DIR . '/vendor/laravel/framework'] = true;
        $this->rt->dirs[self::APP_DIR . '/vendor/laravel/octane'] = true;
    }

    private function mockSupervisorctlOk(): void
    {
        $program = 'azerioid-' . OctaneManager::programName(self::DOMAIN);
        foreach (['reread', 'update', 'start', 'stop', 'restart', 'remove'] as $verb) {
            $this->rt->script(['/usr/bin/supervisorctl', $verb], 0, "{$verb}: ok");
            $this->rt->script(['/usr/bin/supervisorctl', $verb, $program], 0, "{$verb} {$program}: ok");
        }
        $this->rt->script(['/usr/bin/supervisorctl', 'status', $program], 0, "{$program} RUNNING pid 42, uptime 0:01:00");
    }

    /** @return array{0:int,1:array} */
    private function capture(array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $this->kernel->run($argv, $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    private function manager(): OctaneManager
    {
        return new OctaneManager($this->cfg, $this->rt);
    }

    public function test_detects_laravel_app_from_public_docroot(): void
    {
        $detected = OctaneManager::detectLaravel($this->rt, self::DOCROOT);

        $this->assertTrue($detected['laravel']);
        $this->assertSame(self::APP_DIR, $detected['app_dir']);
    }

    public function test_detects_laravel_app_from_composer_require(): void
    {
        unset($this->rt->dirs[self::APP_DIR . '/vendor/laravel/framework']);
        $this->rt->files[self::APP_DIR . '/composer.json'] = json_encode([
            'require' => ['php' => '^8.3', 'laravel/framework' => '^12.0'],
        ], JSON_THROW_ON_ERROR);

        $detected = OctaneManager::detectLaravel($this->rt, self::DOCROOT);

        $this->assertTrue($detected['laravel']);
        $this->assertStringContainsString('composer.json', $detected['detail']);
    }

    public function test_plain_php_site_is_not_a_laravel_app(): void
    {
        $detected = OctaneManager::detectLaravel($this->rt, '/data/www/plain.example.com');

        $this->assertFalse($detected['laravel']);
        $this->assertNull($detected['app_dir']);
        $this->assertStringContainsString('artisan', $detected['detail']);
    }

    public function test_artisan_without_framework_is_not_a_laravel_app(): void
    {
        unset($this->rt->dirs[self::APP_DIR . '/vendor/laravel/framework']);
        $this->rt->files[self::APP_DIR . '/composer.json'] = json_encode([
            'require' => ['symfony/console' => '^7.0'],
        ], JSON_THROW_ON_ERROR);

        $detected = OctaneManager::detectLaravel($this->rt, self::DOCROOT);

        $this->assertFalse($detected['laravel']);
        $this->assertSame(self::APP_DIR, $detected['app_dir']);
        $this->assertStringContainsString('laravel/framework', $detected['detail']);
    }

    public function test_program_name_is_a_valid_supervisor_name(): void
    {
        $this->assertSame('octane-app-example-com', OctaneManager::programName(self::DOMAIN));

        $long = str_repeat('verylongsegment', 4) . '.example.com';
        $name = OctaneManager::programName($long);
        $this->assertLessThanOrEqual(49, strlen($name));
        $this->assertMatchesRegularExpression(\AzerioidPanel\Broker\Validator::SUPERVISOR_PROGRAM_PATTERN, $name);
    }

    public function test_allocates_first_free_port_in_range(): void
    {
        $this->assertSame(OctaneManager::PORT_MIN, $this->manager()->allocatePort());
        $this->assertSame(
            OctaneManager::PORT_MIN + 2,
            $this->manager()->allocatePort([OctaneManager::PORT_MIN, OctaneManager::PORT_MIN + 1])
        );
    }

    public function test_allocate_port_skips_listening_ports(): void
    {
        $this->rt->files['/usr/sbin/ss'] = '';
        $this->rt->script(
            ['/usr/sbin/ss', '-H', '-tln', 'sport', '=', ':' . OctaneManager::PORT_MIN],
            0,
            'LISTEN 0 511 127.0.0.1:34000 0.0.0.0:*'
        );

        $this->assertSame(OctaneManager::PORT_MIN + 1, $this->manager()->allocatePort());
    }

    public function test_port_outside_reserved_range_is_rejected(): void
    {
        [$code, $json] = $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN], ['port' => 8080]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('34000', (string) $json['error']);
    }

    public function test_enable_rewrites_caddy_to_reverse_proxy_and_creates_program(): void
    {
        [$code, $json] = $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN], ['max_requests' => 250]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame(OctaneManager::PORT_MIN, $json['data']['octane_port']);
        $this->assertSame(250, $json['data']['octane_max_requests']);
        $this->assertSame(self::APP_DIR, $json['data']['app_dir']);

        $conf = $this->rt->files[self::CONF];
        $this->assertStringContainsString('runtime=octane octane_port=34000 octane_max_requests=250', $conf);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:34000 {', $conf);
        $this->assertStringContainsString('header_up X-Forwarded-Proto {http.request.scheme}', $conf);
        $this->assertStringNotContainsString('php_fastcgi', $conf);
        $this->assertStringNotContainsString('file_server', $conf);

        $supervisorConf = $this->rt->files['/etc/supervisor/conf.d/azerioid-octane-app-example-com.conf'] ?? '';
        $this->assertStringContainsString(
            'command=/usr/bin/php8.4 artisan octane:start --server=frankenphp --host=127.0.0.1 --port=34000 --max-requests=250',
            $supervisorConf
        );
        $this->assertStringContainsString('user=azerioid-supervised', $supervisorConf);
        $this->assertStringContainsString('directory=' . self::APP_DIR, $supervisorConf);
    }

    public function test_enabled_vhost_is_still_parsed_as_php(): void
    {
        [$code] = $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN]);
        $this->assertSame(0, $code);

        $parsed = CaddyParser::parseFile(self::CONF, $this->rt->files[self::CONF], []);

        $this->assertSame('php', $parsed['type']);
        $this->assertSame('8.4', $parsed['php_version']);
        $this->assertSame(self::DOCROOT, $parsed['root']);
        $this->assertSame('octane', $parsed['runtime']);
        $this->assertSame(34000, $parsed['octane_port']);
        $this->assertSame(500, $parsed['octane_max_requests']);
        $this->assertFalse($parsed['readonly']);
    }

    public function test_fpm_vhost_reports_fpm_runtime(): void
    {
        $parsed = CaddyParser::parseFile(self::CONF, $this->fpmVhost(), []);

        $this->assertSame('fpm', $parsed['runtime']);
        $this->assertNull($parsed['octane_port']);
    }

    public function test_enable_installs_octane_package_when_missing(): void
    {
        unset($this->rt->dirs[self::APP_DIR . '/vendor/laravel/octane']);

        [$code] = $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN]);
        $this->assertSame(0, $code);

        $commands = array_map(
            static fn (array $entry): string => implode(' ', $entry['command']),
            $this->rt->execLog
        );
        $joined = implode("\n", $commands);
        $this->assertStringContainsString("'/usr/local/bin/composer' 'require' 'laravel/octane'", $joined);
        $this->assertStringContainsString("'octane:install' '--server=frankenphp'", $joined);
        $this->assertStringContainsString('-u azerioid-supervised', $joined);
    }

    public function test_enable_is_refused_for_non_laravel_vhost(): void
    {
        unset($this->rt->files[self::APP_DIR . '/artisan']);

        [$code, $json] = $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('does not look like a Laravel application', (string) $json['error']);
        $this->assertStringContainsString('php_fastcgi', $this->rt->files[self::CONF]);
    }

    public function test_enable_is_refused_for_backend_engine_vhost(): void
    {
        $this->rt->files[self::CONF] = str_replace(
            'engine=caddy',
            'engine=apache',
            str_replace('php_fastcgi unix//run/php/php8.4-fpm.sock', 'reverse_proxy 127.0.0.1:8081', $this->fpmVhost())
        );

        [$code, $json] = $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Caddy engine', (string) $json['error']);
    }

    public function test_enable_rolls_back_when_worker_never_listens(): void
    {
        $this->rt->files['/usr/sbin/ss'] = '';

        [$code, $json] = $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('did not start listening', (string) $json['error']);
        $this->assertStringContainsString('php_fastcgi', $this->rt->files[self::CONF]);
        $this->assertArrayNotHasKey('/etc/supervisor/conf.d/azerioid-octane-app-example-com.conf', $this->rt->files);
    }

    public function test_disable_restores_php_fastcgi_and_removes_program(): void
    {
        $this->assertSame(0, $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN])[0]);

        [$code, $json] = $this->capture(['broker', 'vhost.octane.disable', self::DOMAIN]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertTrue($json['data']['program_removed']);
        $conf = $this->rt->files[self::CONF];
        $this->assertStringContainsString('php_fastcgi unix//run/php/php8.4-fpm.sock', $conf);
        $this->assertStringNotContainsString('runtime=octane', $conf);
        $this->assertStringContainsString('root * ' . self::DOCROOT, $conf);
        $this->assertArrayNotHasKey('/etc/supervisor/conf.d/azerioid-octane-app-example-com.conf', $this->rt->files);
    }

    public function test_disable_requires_octane_to_be_enabled(): void
    {
        [$code, $json] = $this->capture(['broker', 'vhost.octane.disable', self::DOMAIN]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not enabled', (string) $json['error']);
    }

    public function test_reload_prefers_artisan_octane_reload(): void
    {
        $this->assertSame(0, $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN])[0]);

        [$code, $json] = $this->capture(['broker', 'vhost.octane.reload', self::DOMAIN]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('octane:reload', $json['data']['method']);
    }

    public function test_reload_falls_back_to_supervisor_restart(): void
    {
        $this->assertSame(0, $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN])[0]);
        $shell = 'cd ' . escapeshellarg(self::APP_DIR)
            . " && exec '/usr/bin/php8.4' 'artisan' 'octane:reload'";
        $this->rt->script(
            ['/usr/sbin/runuser', '-u', 'azerioid-supervised', '--', '/bin/bash', '-lc', $shell],
            1,
            '',
            'octane:reload failed'
        );

        [$code, $json] = $this->capture(['broker', 'vhost.octane.reload', self::DOMAIN]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('supervisor-restart', $json['data']['method']);
    }

    public function test_status_reports_runtime_and_laravel_detection(): void
    {
        [$code, $json] = $this->capture(['broker', 'vhost.octane.status', self::DOMAIN]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('fpm', $json['data']['runtime']);
        $this->assertTrue($json['data']['laravel_app']);
        $this->assertSame('octane-app-example-com', $json['data']['octane_program']);
    }

    public function test_vhost_list_exposes_octane_fields(): void
    {
        $this->assertSame(0, $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN])[0]);

        [$code, $json] = $this->capture(['broker', 'vhost.list'], ['probe_certs' => false]);

        $this->assertSame(0, $code);
        $row = $json['data']['vhosts'][0];
        $this->assertSame('php', $row['type']);
        $this->assertSame('octane', $row['runtime']);
        $this->assertSame(34000, $row['octane_port']);
        $this->assertSame(500, $row['octane_max_requests']);
        $this->assertSame('octane-app-example-com', $row['octane_program']);
        $this->assertTrue($row['laravel_app']);
    }

    public function test_vhost_delete_removes_octane_program_automatically(): void
    {
        $this->assertSame(0, $this->capture(['broker', 'vhost.octane.enable', self::DOMAIN])[0]);

        [$code, $json] = $this->capture(['broker', 'vhost.del', self::DOMAIN]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertArrayNotHasKey('/etc/supervisor/conf.d/azerioid-octane-app-example-com.conf', $this->rt->files);
        $this->assertArrayNotHasKey(self::CONF, $this->rt->files);
    }
}
