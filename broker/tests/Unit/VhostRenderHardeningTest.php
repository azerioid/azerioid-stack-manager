<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Web\CaddyDriver;
use AzerioidPanel\Broker\Web\VhostFrontRouter;
use PHPUnit\Framework\TestCase;

/** A115: repository (.git) and Composer state (.composer) are never served by any engine. */
final class VhostRenderHardeningTest extends TestCase
{
    private const OLD = "# azerioid-managed engine=caddy type=php php=8.4 root=/data/www/shop.example.com\n"
        . "shop.example.com {\n    root * /data/www/shop.example.com\n    php_fastcgi unix//run/php/azv-shop-example-com-8.4.sock\n"
        . "    file_server {\n        index index.html index.php\n    }\n}\n";

    public function test_the_hide_line_goes_into_every_file_server_block_once(): void
    {
        $once = VhostFrontRouter::withHiddenFiles(self::OLD);
        $this->assertStringContainsString("    file_server {\n        hide .git .composer\n        index index.html index.php\n    }\n", $once);
        $this->assertSame($once, VhostFrontRouter::withHiddenFiles($once));
        $proxy = "x.example.com {\n    reverse_proxy 127.0.0.1:34000\n}\n";
        $this->assertSame($proxy, VhostFrontRouter::withHiddenFiles($proxy), 'a proxy vhost has no file_server to change');
    }

    public function test_new_caddy_vhosts_hide_repository_state(): void
    {
        $this->assertSame('hide .git .composer', CaddyDriver::HIDE_LINE);
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Web/CaddyDriver.php');
        $this->assertStringContainsString('self::HIDE_LINE', $src);
    }

    private function runtime(int $validate): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "import /etc/caddy/conf.d/*.conf\n";
        $rt->files['/etc/caddy/conf.d/shop.example.com.conf'] = self::OLD;
        $rt->files['/etc/caddy/conf.d/azerioid-panel.conf'] = "# azerioid-managed panel type=php\nhttp://127.0.0.1:3169 {\n    file_server {\n    }\n}\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], $validate, $validate === 0 ? 'Valid configuration' : '', $validate === 0 ? '' : 'Error: bad hide');
        $rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'caddy'], 0, "active\n");

        return $rt;
    }

    public function test_converge_adds_the_hide_line_to_existing_caddy_vhosts(): void
    {
        $rt = $this->runtime(0);
        $out = (new VhostFrontRouter())->convergeRenders($rt, new Config());
        $this->assertSame(['shop.example.com'], $out['caddy']);
        $this->assertStringContainsString('hide .git .composer', $rt->files['/etc/caddy/conf.d/shop.example.com.conf']);
        $this->assertStringNotContainsString('hide', $rt->files['/etc/caddy/conf.d/azerioid-panel.conf'], 'the panel snippet is not touched');
        $this->assertSame([], (new VhostFrontRouter())->convergeRenders($rt, new Config())['caddy'], 'idempotent');
    }

    public function test_converge_puts_the_files_back_when_caddy_refuses_them(): void
    {
        $rt = $this->runtime(1);
        try {
            (new VhostFrontRouter())->convergeRenders($rt, new Config());
            $this->fail('expected refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('put back', $e->getMessage());
        }
        $this->assertSame(self::OLD, $rt->files['/etc/caddy/conf.d/shop.example.com.conf']);
    }
}
