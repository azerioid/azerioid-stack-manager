<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Php\SitePool;
use AzerioidPanel\Broker\Php\SitePoolMigrator;
use PHPUnit\Framework\TestCase;

/**
 * ADR A55: every PHP site in a PHP-FPM pool of its own, running as the site's identity.
 */
final class SitePoolTest extends TestCase
{
    private const CONF = '/etc/caddy/conf.d/shop.example.com.conf';
    private const OWN = '/run/php/azv-shop-example-com-8.4.sock';
    private const SHARED = '/run/php/php8.4-fpm.sock';

    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    /** HTTP status the site answers with, by which socket its vhost uses. */
    private int $httpOwn = 200;
    private int $httpShared = 200;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->execFn = function (array $c, ?string $stdin): ?ExecResult {
            if (($c[0] ?? '') === '/usr/bin/curl') {
                $conf = $this->rt->files[self::CONF] ?? '';
                $code = str_contains($conf, 'azv-') ? $this->httpOwn : $this->httpShared;

                return new ExecResult($c, 0, (string) $code, '');
            }

            return null;
        };
        $this->cfg = new Config();
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    public function test_a_new_php_site_gets_its_own_pool_running_as_the_site(): void
    {
        $this->assertSame(0, $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com/public', 'php', '8.4'])[0]);

        $this->assertStringContainsString('php_fastcgi unix/' . self::OWN, $this->rt->files[self::CONF]);
        $pool = $this->rt->files[$this->poolPath('8.4')];
        $this->assertStringContainsString("[azv-shop-example-com]\nuser = az-vh-shop-example-com\ngroup = az-vh-shop-example-com\n", $pool);
        $this->assertStringContainsString('listen = ' . self::OWN, $pool);
        $this->assertStringContainsString("listen.owner = caddy\nlisten.group = az-vh-shop-example-com\nlisten.mode = 0660", $pool);
        $this->assertStringContainsString("pm = ondemand\npm.max_children = 5\npm.process_idle_timeout = 10s", $pool);
        $this->assertStringContainsString('php_admin_value[open_basedir] = /data/www/shop.example.com/:/tmp/:/var/lib/azerioid-php-sessions/shop-example-com/:', $pool);
        $this->assertSame(0700, $this->rt->modes['/var/lib/azerioid-php-sessions/shop-example-com'] ?? null);
    }

    public function test_a_pool_php_fpm_refuses_is_taken_out_before_any_reload(): void
    {
        $this->rt->files['/usr/sbin/php-fpm8.4'] = '';
        $this->rt->script(['/usr/sbin/php-fpm8.4', '-t'], 78, '', 'ERROR: [pool azv-shop-example-com] cannot get uid');

        [$code, $json] = $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('refused the pool', (string) $json['error']);
        $this->assertArrayNotHasKey($this->poolPath('8.4'), $this->rt->files);
        foreach ($this->commands() as $c) {
            $this->assertFalse(($c[0] ?? '') === '/usr/bin/systemctl' && str_contains($c[2] ?? '', 'fpm'), 'no php-fpm reload with a bad pool');
        }
    }

    public function test_deleting_the_site_removes_its_pool_before_its_account(): void
    {
        $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4']);
        $this->rt->execLog = [];

        $this->assertSame(0, $this->broker(['vhost.del', 'shop.example.com'])[0]);

        $this->assertArrayNotHasKey($this->poolPath('8.4'), $this->rt->files);
        $reload = $userdel = null;
        foreach ($this->commands() as $i => $c) {
            if ($reload === null && ($c[0] ?? '') === '/usr/bin/systemctl' && str_contains($c[2] ?? '', 'fpm')) {
                $reload = $i;
            }
            if ($userdel === null && ($c[0] ?? '') === '/usr/sbin/userdel') {
                $userdel = $i;
            }
        }
        $this->assertNotNull($reload);
        $this->assertTrue($userdel === null || $reload < $userdel, 'php-fpm will not reload a pool whose user is gone');
    }

    public function test_a_php_version_change_moves_the_site_and_takes_the_old_pool_out(): void
    {
        $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4']);

        $this->assertSame(0, $this->broker(['vhost.edit', 'shop.example.com'], ['php_version' => '8.3'])[0]);

        $this->assertStringContainsString('azv-shop-example-com-8.3.sock', $this->rt->files[self::CONF]);
        $this->assertArrayHasKey($this->poolPath('8.3'), $this->rt->files);
        $this->assertArrayNotHasKey($this->poolPath('8.4'), $this->rt->files);
    }

    public function test_the_migration_moves_an_existing_site_off_the_shared_pool(): void
    {
        $this->legacySite();
        $migrator = new SitePoolMigrator($this->rt, $this->cfg, 0);
        $this->assertSame(['shop.example.com'], $migrator->status()['pending']);

        $out = $migrator->converge(true);

        $this->assertSame('ok', $out['result']);
        $this->assertSame('isolated', $out['sites']['shop.example.com']['result']);
        $this->assertStringContainsString(self::OWN, $this->rt->files[self::CONF]);
        // What the shared pool wrote (uploads, caches) is handed to the site, group write kept.
        $this->assertContains(['/usr/bin/chown', '-R', '-h', 'az-vh-shop-example-com:az-vh-shop-example-com', '/data/www/shop.example.com'], $this->commands());
        $this->assertContains(['/usr/bin/chmod', '-R', 'g+rwX', '/data/www/shop.example.com'], $this->commands());
        $status = $migrator->status();
        $this->assertTrue($status['migrated']);
        $this->assertSame(['started' => false, 'reason' => 'nothing to do'], $migrator->converge(false));
    }

    public function test_a_site_that_breaks_after_the_move_goes_back_to_the_shared_pool(): void
    {
        $this->legacySite();
        $this->httpOwn = 502;
        $migrator = new SitePoolMigrator($this->rt, $this->cfg, 0);

        $out = $migrator->converge(true);

        $this->assertSame('partial', $out['result']);
        $this->assertSame('shared', $out['sites']['shop.example.com']['result']);
        $this->assertStringContainsString('HTTP 200 before the move, 502 after', $out['sites']['shop.example.com']['reason']);
        $this->assertStringContainsString(self::SHARED, $this->rt->files[self::CONF]);
        $this->assertArrayNotHasKey($this->poolPath('8.4'), $this->rt->files);
        $status = $migrator->status();
        $this->assertFalse($status['migrated']);
        $this->assertFalse($status['auto_eligible'], 'not retried automatically');
        $this->assertSame('shop.example.com', $status['shared'][0]['domain']);

        // The operator fixes the site and retries.
        $this->httpOwn = 200;
        $retry = $migrator->apply('ISOLATE-PHP');
        $this->assertSame('isolated', $retry['sites']['shop.example.com']['result']);
        $this->assertTrue($migrator->status()['migrated']);
    }

    public function test_pools_no_site_uses_are_taken_out(): void
    {
        $this->legacySite();
        $stale = '/etc/php/8.4/fpm/pool.d/azv-gone-example-com.conf';
        $this->rt->files[$stale] = "[azv-gone-example-com]\nlisten = /run/php/azv-gone-example-com-8.4.sock\n";
        $migrator = new SitePoolMigrator($this->rt, $this->cfg, 0);
        $this->assertContains($stale, $migrator->status()['stale']);

        $out = $migrator->converge(true);

        $this->assertContains($stale, $out['removed_pools']);
        $this->assertArrayNotHasKey($stale, $this->rt->files);
        $this->assertArrayHasKey($this->poolPath('8.4'), $this->rt->files);
    }

    public function test_open_basedir_can_be_switched_off_for_one_site(): void
    {
        $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4']);

        [$code, $json] = $this->broker(['vhost.phppool.set', 'shop.example.com'], ['open_basedir' => false]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertFalse($json['data']['open_basedir']);
        $pool = $this->rt->files[$this->poolPath('8.4')];
        $this->assertStringNotContainsString('php_admin_value[open_basedir]', $pool);
        $this->assertStringContainsString('; open_basedir switched off', $pool);
    }

    public function test_the_operator_retry_needs_the_typed_confirm(): void
    {
        [$code] = $this->broker(['vhost.phppool.apply'], ['confirm' => 'yes']);

        $this->assertSame(3, $code);
    }

    private function legacySite(): void
    {
        // Created by a release before A55: rendered while no pool could be made.
        $this->rt->uid = 1000;
        $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com', 'php', '8.4']);
        $this->rt->uid = 0;
        $this->rt->dirs['/data/www/shop.example.com'] = true;
        $this->assertStringContainsString(self::SHARED, $this->rt->files[self::CONF]);
    }

    private function poolPath(string $version): string
    {
        return (new SitePool($this->cfg, $this->rt))->poolFile('shop.example.com', $version);
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function broker(array $argv, array $input = []): array
    {
        ob_start();
        $code = $this->kernel->run(array_merge(['broker'], $argv), $input);
        $out = (string) ob_get_clean();

        return [$code, (array) json_decode($out, true)];
    }

    /** @return list<list<string>> */
    private function commands(): array
    {
        return array_map(static fn (array $e): array => $e['command'], $this->rt->execLog);
    }

    public function test_a80_per_site_resource_limits_land_in_the_pool(): void
    {
        $this->assertSame(0, $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com/public', 'php', '8.4'])[0]);

        // Default pool: 5 children, no explicit memory_limit.
        $pool = $this->rt->files[$this->poolPath('8.4')];
        $this->assertStringContainsString('pm.max_children = 5', $pool);
        $this->assertStringNotContainsString('memory_limit]', $pool);

        [$code, $json] = $this->broker(
            ['vhost.limits.set', 'shop.example.com'],
            ['php_memory_limit_mb' => '256', 'max_children' => '12']
        );
        $this->assertSame(0, $code, json_encode($json));

        $pool = $this->rt->files[$this->poolPath('8.4')];
        $this->assertStringContainsString('pm.max_children = 12', $pool);
        $this->assertStringContainsString('php_admin_value[memory_limit] = 256M', $pool);

        // show reflects the stored caps.
        [, $shown] = $this->broker(['vhost.limits.show', 'shop.example.com']);
        $this->assertSame(256, $shown['data']['php_memory_limit_mb'] ?? null);
        $this->assertSame(12, $shown['data']['max_children'] ?? null);

        // Clearing returns the pool to the panel default.
        $this->assertSame(0, $this->broker(['vhost.limits.set', 'shop.example.com'], ['php_memory_limit_mb' => '', 'max_children' => 'default'])[0]);
        $pool = $this->rt->files[$this->poolPath('8.4')];
        $this->assertStringContainsString('pm.max_children = 5', $pool);
        $this->assertStringNotContainsString('memory_limit]', $pool);
    }

    public function test_a80_rejects_out_of_range_limits(): void
    {
        $this->assertSame(0, $this->broker(['vhost.add', 'shop.example.com', '/data/www/shop.example.com/public', 'php', '8.4'])[0]);
        $this->assertNotSame(0, $this->broker(['vhost.limits.set', 'shop.example.com'], ['php_memory_limit_mb' => '999999'])[0]);
        $this->assertNotSame(0, $this->broker(['vhost.limits.set', 'shop.example.com'], ['max_children' => '0'])[0]);
        $this->assertNotSame(0, $this->broker(['vhost.limits.set', 'shop.example.com'], ['php_memory_limit_mb' => 'lots'])[0]);
    }
}
