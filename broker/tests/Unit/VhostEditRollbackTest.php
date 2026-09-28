<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class VhostEditRollbackTest extends TestCase
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
        $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'] = <<<'CADDY'
shop.example.com {
    root * /data/www/shop.example.com
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server {
        index index.html index.php
    }
    log {
        output file /var/log/caddy/access_shop.example.com.log
    }
}

CADDY;
        $this->rt->dirs['/data/www/shop.example.com'] = true;
        $this->cfg = new Config();
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    public function test_edits_php_version_after_validate_and_reload(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);

        ob_start();
        $code = $this->kernel->run(
            ['broker', 'vhost.edit', 'shop.example.com'],
            ['php_version' => '8.3']
        );
        $out = ob_get_clean();

        $this->assertSame(0, $code, $out);
        $conf = $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'];
        $this->assertStringContainsString('php_fastcgi unix//run/php/azv-shop-example-com-8.3.sock', $conf);
        $decoded = json_decode($out, true);
        $this->assertSame('8.4', $decoded['data']['before']['php_version']);
        $this->assertSame('8.3', $decoded['data']['after']['php_version']);
    }

    public function test_edits_docroot_and_tls(): void
    {
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->script(['/usr/bin/systemctl', 'restart', 'caddy'], 0);

        ob_start();
        $code = $this->kernel->run(
            ['broker', 'vhost.edit', 'shop.example.com'],
            ['root' => '/data/www/shop-new.example.com', 'tls' => false]
        );
        ob_get_clean();

        $this->assertSame(0, $code);
        $conf = $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'];
        $this->assertStringContainsString('http://shop.example.com', $conf);
        $this->assertStringContainsString('root * /data/www/shop-new.example.com', $conf);
        $this->assertTrue($this->rt->isDir('/data/www/shop-new.example.com'));
    }

    public function test_rolls_back_edit_when_validate_fails(): void
    {
        $before = $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'];
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 1, '', 'Error: invalid');

        ob_start();
        $code = $this->kernel->run(
            ['broker', 'vhost.edit', 'shop.example.com'],
            ['php_version' => '8.3']
        );
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertSame($before, $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf']);
        $this->assertStringContainsString('rolled back', $out);
    }

    public function test_refuses_to_edit_readonly_vhost(): void
    {
        ob_start();
        $code = $this->kernel->run(['broker', 'vhost.edit', 'projob.az'], ['root' => '/data/www/x']);
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('managed externally', $out);
    }

    public function test_rejects_type_change_in_input(): void
    {
        ob_start();
        $code = $this->kernel->run(
            ['broker', 'vhost.edit', 'shop.example.com'],
            ['type' => 'static']
        );
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Changing vhost type', $out);
    }

    public function test_rejects_domain_rename(): void
    {
        ob_start();
        $code = $this->kernel->run(
            ['broker', 'vhost.edit', 'shop.example.com'],
            ['new_domain' => 'other.example.com']
        );
        $out = ob_get_clean();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Renaming a vhost domain', $out);
    }
}
