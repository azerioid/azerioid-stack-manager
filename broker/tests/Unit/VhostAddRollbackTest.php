<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class VhostAddRollbackTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/etc/caddy/conf.d/projob.az.conf'] = file_get_contents(__DIR__ . '/../fixtures/vhost-projob.conf');
        $this->rt->files['/etc/caddy/conf.d/default.conf'] = file_get_contents(__DIR__ . '/../fixtures/vhost-default.conf');
        $this->cfg = new Config();
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    public function test_adds_php_vhost_after_validate_and_reload(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4'], []);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertArrayHasKey('/etc/caddy/conf.d/shop.example.com.conf', $this->rt->files);
        $conf = $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'];
        $this->assertStringContainsString('php_fastcgi unix//run/php/php8.4-fpm.sock', $conf);
        $this->assertStringContainsString('root * /data/www/shop.example.com', $conf);
        $this->assertTrue($this->rt->isDir('/data/www/shop.example.com'));
        $decoded = json_decode($out, true);
        $this->assertSame('restart', $decoded['data']['apply']['path']);
        $this->assertTrue($decoded['data']['apply']['admin_enabled']);
        $this->assertSame('127.0.0.1:2019', $decoded['data']['apply']['address']);
        $this->assertStringContainsString('admin 127.0.0.1:2019', $this->rt->files['/etc/caddy/Caddyfile']);
        $apiReloads = 0;
        $restarts = 0;
        foreach ($this->rt->execLog as $e) {
            if (($e['command'][0] ?? '') === '/usr/bin/caddy' && ($e['command'][1] ?? '') === 'reload') {
                $apiReloads++;
            }
            if (($e['command'][1] ?? '') === 'restart') {
                $restarts++;
            }
        }
        $this->assertSame(0, $apiReloads);
        $this->assertSame(1, $restarts);
    }

    public function test_reload_failure_falls_back_to_caddy_restart(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 1, '', 'connection refused');
        $this->rt->script(['/usr/bin/systemctl', 'reload', 'caddy'], 1, '', 'Job for caddy.service failed');
        $this->rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'shop2.example.com', '/data/www/shop2.example.com', 'php', '8.4'], []);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertArrayHasKey('/etc/caddy/conf.d/shop2.example.com.conf', $this->rt->files);
        $decoded = json_decode($out, true);
        $this->assertSame('restart', $decoded['data']['apply']['path']);
    }

    public function test_rolls_back_when_validate_fails(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 1, '', 'Error: invalid config');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'bad.example.com', '/data/www/bad.example.com', 'php', '8.4'], []);
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/bad.example.com.conf', $this->rt->files);
        $this->assertStringContainsString('rolled back', $out);
        $this->assertArrayHasKey('/etc/caddy/conf.d/projob.az.conf', $this->rt->files);
    }

    public function test_rolls_back_when_reload_fails(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 1, '', 'connection refused');
        $this->rt->script(['/usr/bin/systemctl', 'reload', 'caddy'], 1, '', 'reload failed');
        $this->rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 1, '', 'restart failed');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'oops.example.com', '/data/www/oops.example.com', 'php', '8.4'], []);
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/oops.example.com.conf', $this->rt->files);
        $this->assertArrayHasKey('/etc/caddy/conf.d/projob.az.conf', $this->rt->files);
        $this->assertStringContainsString('systemctl restart caddy failed', $out);
    }

    public function test_refuses_to_delete_projob(): void
    {
        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.del', 'projob.az'], []);
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertArrayHasKey('/etc/caddy/conf.d/projob.az.conf', $this->rt->files);
        $this->assertStringContainsString('managed externally', $out);
    }

    public function test_refuses_to_delete_default(): void
    {
        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.del', 'default.example.com'], []);
        ob_end_clean();
        // default.conf is keyed by filename default, not domain
        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.del', 'example.com'], []);
        ob_end_clean();
        $this->assertArrayHasKey('/etc/caddy/conf.d/default.conf', $this->rt->files);
        $this->assertNotSame(0, $code);
    }

    public function test_prefers_lacmp_default_pool_socket_not_panel_pool(): void
    {
        $this->rt->files['/run/php/php-fpm.sock'] = '';
        $this->rt->files['/run/php/azerioid-panel.sock'] = '';
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'pool.example.com', '/data/www/pool.example.com', 'php', '8.4'], []);
        ob_end_clean();
        $this->assertSame(0, $code);
        $conf = $this->rt->files['/etc/caddy/conf.d/pool.example.com.conf'];
        $this->assertStringContainsString('php_fastcgi unix//run/php/php-fpm.sock', $conf);
        $this->assertStringNotContainsString('azerioid-panel.sock', $conf);
    }

    public function test_refuses_readonly_domain_create(): void
    {
        $this->cfg->readonlyVhosts = ['projob.az'];
        $this->kernel = new Kernel($this->cfg, $this->rt);
        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'projob.az', '/data/www/projob.az', 'php', '8.4'], []);
        $out = ob_get_clean();
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('managed externally', $out);
    }

    public function test_rejects_duplicate_domain(): void
    {
        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'projob.az', '/data/www/projob.az', 'php', '8.4'], []);
        ob_end_clean();
        $this->assertNotSame(0, $code);
    }

    public function test_recreates_over_leftover_docroot_and_orphan_conf(): void
    {
        $this->rt->dirs['/data/www/let.az'] = true;
        $this->rt->files['/data/www/let.az/index.php'] = '<?php echo "kept";';
        // Orphan conf with no site address — must not count as registration.
        $this->rt->files['/etc/caddy/conf.d/let.az.conf'] = "# leftover orphan\n";
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'let.az', '/data/www/let.az', 'php', '8.4'], []);
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('let.az {', $this->rt->files['/etc/caddy/conf.d/let.az.conf']);
        // Existing non-empty docroot must not get a second welcome index overwrite.
        $this->assertSame('<?php echo "kept";', $this->rt->files['/data/www/let.az/index.php']);
    }

    public function test_rejects_path_traversal_root(): void
    {
        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'evil.example.com', '/etc/passwd', 'php', '8.4'], []);
        $out = ob_get_clean();
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('allowlisted', $out);
    }
}
