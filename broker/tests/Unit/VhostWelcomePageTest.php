<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Vhost\VhostWelcomePage;
use PHPUnit\Framework\TestCase;

final class VhostWelcomePageTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/etc/caddy/conf.d/projob.az.conf'] = file_get_contents(__DIR__ . '/../fixtures/vhost-projob.conf');
        $this->cfg = new Config();
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    public function test_seeds_index_php_for_empty_php_vhost(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4'], []);
        ob_end_clean();
        $this->assertSame(0, $code);
        $path = '/data/www/shop.example.com/index.php';
        $this->assertArrayHasKey($path, $this->rt->files);
        $body = $this->rt->files[$path];
        $this->assertStringContainsString('AZERIOID Stack Manager', $body);
        $this->assertStringContainsString('shop.example.com', $body);
        $this->assertStringContainsString('/data/www/shop.example.com', $body);
        $this->assertStringContainsString('<?php echo htmlspecialchars(PHP_VERSION', $body);
        $this->assertArrayNotHasKey('/data/www/shop.example.com/index.html', $this->rt->files);
    }

    public function test_seeds_index_html_for_empty_static_vhost(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'static.example.com', '/data/www/static.example.com', 'static'], []);
        ob_end_clean();
        $this->assertSame(0, $code);
        $path = '/data/www/static.example.com/index.html';
        $this->assertArrayHasKey($path, $this->rt->files);
        $this->assertStringContainsString('AZERIOID Stack Manager', $this->rt->files[$path]);
        $this->assertStringNotContainsString('<?php', $this->rt->files[$path]);
        $this->assertArrayNotHasKey('/data/www/static.example.com/index.php', $this->rt->files);
    }

    public function test_does_not_overwrite_existing_docroot_content(): void
    {
        $this->rt->dirs['/data/www/existing.example.com'] = true;
        $this->rt->files['/data/www/existing.example.com/app.php'] = '<?php echo "real";';
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'existing.example.com', '/data/www/existing.example.com', 'php', '8.4'], []);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('/data/www/existing.example.com/index.php', $this->rt->files);
        $this->assertSame('<?php echo "real";', $this->rt->files['/data/www/existing.example.com/app.php']);
    }

    public function test_does_not_seed_when_hidden_file_present(): void
    {
        $this->rt->dirs['/data/www/dot.example.com'] = true;
        $this->rt->files['/data/www/dot.example.com/.gitkeep'] = '';
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.add', 'dot.example.com', '/data/www/dot.example.com', 'static'], []);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('/data/www/dot.example.com/index.html', $this->rt->files);
    }

    public function test_edit_does_not_restore_welcome_after_operator_replace(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        ob_start();
        $this->kernel->run(['broker', 'vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4'], []);
        ob_end_clean();

        $this->rt->files['/data/www/shop.example.com/index.php'] = '<?php echo "operator";';
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.edit', 'shop.example.com'], ['tls' => false]);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertSame('<?php echo "operator";', $this->rt->files['/data/www/shop.example.com/index.php']);
    }

    public function test_apache_add_also_seeds_welcome(): void
    {
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
        $rt->script(['/usr/sbin/a2ensite', 'lamp.example.com'], 0);
        $rt->script(['/usr/bin/systemctl', 'reload', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'restart', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'apache2'], 0, "active\n");
        $rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);

        $kernel = new Kernel($cfg, $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'lamp.example.com', '/data/www/lamp.example.com', 'php', '8.4'],
            ['engine' => 'apache']
        );
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertArrayHasKey('/data/www/lamp.example.com/index.php', $rt->files);
        $this->assertStringContainsString('AZERIOID Stack Manager', $rt->files['/data/www/lamp.example.com/index.php']);
    }

    public function test_nginx_add_also_seeds_welcome(): void
    {
        $cfg = new Config();
        $cfg->stack = 'nginx';
        $cfg->webServer = 'nginx';
        $cfg->webService = 'nginx';
        $cfg->vhostFormat = 'nginx';
        $cfg->vhostDir = '/etc/nginx/sites-enabled';
        $cfg->vhostAvailableDir = '/etc/nginx/sites-available';
        $cfg->webLogDir = '/var/log/nginx';
        $cfg->phpUser = 'www-data';
        $cfg->phpGroup = 'www-data';
        $cfg->webUser = 'www-data';

        $rt = new FakeRuntime();
        $rt->dirs['/etc/nginx'] = true;
        $rt->dirs['/etc/nginx/sites-available'] = true;
        $rt->dirs['/etc/nginx/sites-enabled'] = true;
        $rt->dirs['/var/log/nginx'] = true;
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->files['/usr/sbin/nginx'] = '';
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/sbin/nginx', '-t'], 0, 'syntax is ok');
        $rt->script(['/usr/bin/systemctl', 'reload', 'nginx'], 0);
        $rt->script(['/usr/bin/systemctl', 'restart', 'nginx'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'nginx'], 0, "active\n");
        $rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);
        $rt->script(['/usr/bin/ln', '-sf', '/etc/nginx/sites-available/ngx.example.com.conf', '/etc/nginx/sites-enabled/ngx.example.com.conf'], 0);
        $rt->files['/usr/bin/ln'] = '';

        $kernel = new Kernel($cfg, $rt);
        ob_start();
        $code = $kernel->run(
            ['broker', 'vhost.add', 'ngx.example.com', '/data/www/ngx.example.com', 'static'],
            ['engine' => 'nginx']
        );
        $out = ob_get_clean();
        $this->assertSame(0, $code, $out);
        $this->assertArrayHasKey('/data/www/ngx.example.com/index.html', $rt->files);
    }

    public function test_is_genuinely_empty_helper(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/data/www/empty'] = true;
        $this->assertTrue(VhostWelcomePage::isGenuinelyEmpty($rt, '/data/www/empty'));
        $rt->files['/data/www/empty/x'] = 'y';
        $this->assertFalse(VhostWelcomePage::isGenuinelyEmpty($rt, '/data/www/empty'));
    }
}
