<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\PosixRuntime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Config;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the A47 broker hardening pass (source audit of broker/src).
 * Each asserts the broker emits the safe behaviour, not the OS outcome, so they run
 * under FakeRuntime / a scratch dir without a Linux host.
 */
final class AuditHardeningTest extends TestCase
{
    public function test_supervised_log_dir_stays_root_owned(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/id', '-u', SupervisedUser::USERNAME], 0);

        SupervisedUser::ensure($rt);

        $logChowns = array_values(array_filter(
            $rt->execLog,
            static fn (array $e): bool => ($e['command'][0] ?? '') === '/usr/bin/chown'
                && str_contains((string) end($e['command']), SupervisedUser::LOG_DIR)
        ));
        $this->assertNotEmpty($logChowns, 'the log dir ownership must be set explicitly');
        foreach ($logChowns as $e) {
            $spec = (string) $e['command'][array_search('-R', $e['command'], true) + 1];
            $this->assertSame('root:' . SupervisedUser::USERNAME, $spec,
                'supervisord writes these logs as root; the account must not own the dir');
        }
    }

    public function test_assert_no_symlink_under_refuses_planted_links(): void
    {
        // A61: a symlinked component under a site-owned home must be refused before
        // root chowns/writes a fixed path there.
        $rt = new FakeRuntime();
        $base = '/var/lib/azerioid-docker-home';
        $home = $base . '/site-com';
        // All real: passes.
        \AzerioidPanel\Broker\Vhost\VhostUser::assertNoSymlinkUnder($rt, $base, $home . '/.config');
        $this->assertTrue(true);
        // Final component is a symlink: refused.
        $rt->links[$home . '/.config'] = true;
        try {
            \AzerioidPanel\Broker\Vhost\VhostUser::assertNoSymlinkUnder($rt, $base, $home . '/.config');
            $this->fail('expected a symlink refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('symlink', strtolower($e->getMessage()));
        }
        // Intermediate component is a symlink: also refused.
        $rt2 = new FakeRuntime();
        $rt2->links[$home . '/.local'] = true;
        $this->expectException(BrokerException::class);
        \AzerioidPanel\Broker\Vhost\VhostUser::assertNoSymlinkUnder($rt2, $base, $home . '/.local/share');
    }

    public function test_shared_top_is_detected_so_one_site_cannot_claim_it(): void
    {
        // A62: two sites under one www_root top — the top is not the isolation unit
        // (A49-E1), so a new site there must not be able to claim it.
        $rt = new FakeRuntime();
        $config = new \AzerioidPanel\Broker\Config();
        $config->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $rt->files['/var/lib/azerioid-panel/vhost-users.json'] = json_encode(['users' => [
            'a.test' => ['username' => 'az-vh-a-test', 'root' => '/data/www/app/public'],
        ]]);

        // b.test's docroot shares the /data/www/app top with a.test → shared.
        $this->assertTrue(\AzerioidPanel\Broker\Vhost\VhostUser::topSharedByAnotherSite(
            $rt, $config, 'b.test', '/data/www/app'
        ));
        // A top that no other site lives under → not shared.
        $this->assertFalse(\AzerioidPanel\Broker\Vhost\VhostUser::topSharedByAnotherSite(
            $rt, $config, 'b.test', '/data/www/other'
        ));
        // The owning site itself does not count as a collision.
        $this->assertFalse(\AzerioidPanel\Broker\Vhost\VhostUser::topSharedByAnotherSite(
            $rt, $config, 'a.test', '/data/www/app'
        ));
    }

    public function test_claiming_a_shared_top_resets_it_to_root_order_independent(): void
    {
        // A63: even if site A claimed the top before site B existed, B's ensure()
        // must reset the shared container to root so neither site owns it.
        $rt = new FakeRuntime();
        $rt->uid = 0;
        $config = new \AzerioidPanel\Broker\Config();
        $config->wwwRoot = '/data/www';
        $config->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        foreach (['/data/www', '/data/www/app', '/data/www/app/public', '/data/www/app/admin'] as $d) {
            $rt->dirs[$d] = true;
        }
        // A already created and recorded, and already owns the shared top.
        $rt->files['/var/lib/azerioid-panel/vhost-users.json'] = json_encode(['users' => [
            'a.test' => ['username' => 'az-vh-a-test', 'root' => '/data/www/app/public'],
        ]]);

        \AzerioidPanel\Broker\Vhost\VhostUser::ensure($rt, $config, 'b.test', '/data/www/app/admin');

        $reset = false;
        foreach ($rt->execLog as $e) {
            if ($e['command'] === ['/usr/bin/chown', '-h', 'root:root', '/data/www/app']) {
                $reset = true;
            }
        }
        $this->assertTrue($reset, 'a shared top must be reset to root during the second site\'s ensure');
        // And it must NOT be claimed by b.
        foreach ($rt->execLog as $e) {
            $this->assertNotSame(['/usr/bin/chown', '-h', 'az-vh-b-test:az-vh-b-test', '/data/www/app'], $e['command']);
        }
    }

    public function test_docker_volume_refuses_symlinked_path_before_chown(): void
    {
        // A64: a symlinked volume component under the site app dir must be refused
        // before any root mkdir/chown — the old code mutated then checked.
        $rt = new FakeRuntime();
        $rt->uid = 0;
        $config = new \AzerioidPanel\Broker\Config();
        $appDir = '/data/www/site.test';
        $rt->dirs[$appDir] = true;
        $rt->links[$appDir . '/data'] = true; // site planted a symlink where the volume goes
        $mgr = new \AzerioidPanel\Broker\Vhost\DockerManager($config, $rt);
        $m = new \ReflectionMethod(\AzerioidPanel\Broker\Vhost\DockerManager::class, 'ensureVolumeDirs');
        $m->setAccessible(true);
        try {
            $m->invoke($mgr, ['domain' => 'site.test', 'app_dir' => $appDir, 'volumes' => [['host' => 'data']]]);
            $this->fail('expected a symlink refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('symlink', strtolower($e->getMessage()));
        }
        foreach ($rt->execLog as $e) {
            $this->assertNotSame('/usr/bin/chown', $e['command'][0] ?? '', 'no chown may run for a refused volume');
        }
    }

    public function test_octane_composer_home_is_not_predictable_tmp(): void
    {
        // A65: COMPOSER_HOME for the Octane install must not be a predictable
        // /tmp path (pre-creatable by another local user), and plugins are off.
        $rt = new FakeRuntime();
        $rt->uid = 0;
        $rt->files['/usr/local/bin/composer'] = '';
        $rt->files['/usr/sbin/runuser'] = '';
        $config = new \AzerioidPanel\Broker\Config();
        $appDir = '/data/www/oct.test';
        $mgr = new \AzerioidPanel\Broker\Vhost\OctaneManager($config, $rt);
        $m = new \ReflectionMethod(\AzerioidPanel\Broker\Vhost\OctaneManager::class, 'installOctanePackage');
        $m->setAccessible(true);
        $m->invoke($mgr, $appDir, '/usr/bin/php8.4', 'az-vh-oct-test');

        $requireCmd = '';
        foreach ($rt->execLog as $e) {
            $joined = implode(' ', $e['command']);
            if (str_contains($joined, 'require') && str_contains($joined, 'laravel/octane')) {
                $requireCmd = $joined;
            }
        }
        $this->assertNotSame('', $requireCmd, 'composer require must run');
        $this->assertStringNotContainsString('/tmp/azerioid-octane', $requireCmd);
        $this->assertStringContainsString('/var/lib/azerioid-octane/', $requireCmd);
        $this->assertStringContainsString('no-plugins', $requireCmd);
    }

    public function test_panel_self_update_composer_home_is_not_world_writable_tmp(): void
    {
        // A67: the panel self-update COMPOSER_HOME must not be a predictable
        // /tmp path or the fixed world-writable /tmp — either lets a local user
        // plant a config.json/auth.json composer reads as the web user.
        $rt = new FakeRuntime();
        $rt->uid = 0;
        $config = new \AzerioidPanel\Broker\Config();
        $updater = new \AzerioidPanel\Broker\Panel\PanelUpdater($config, $rt);
        $m = new \ReflectionMethod(\AzerioidPanel\Broker\Panel\PanelUpdater::class, 'panelComposerHome');
        $m->setAccessible(true);
        $home = $m->invoke($updater, 'caddy');

        $this->assertStringStartsWith('/var/lib/azerioid-panel-composer/', $home);
        $this->assertStringNotContainsString('/tmp', $home);
        // Base is reset to root:root 0711 so no other local user can pre-stage
        // the random child.
        $baseRootOwned = false;
        $homeToWeb = false;
        foreach ($rt->execLog as $e) {
            if ($e['command'] === ['/usr/bin/chown', 'root:root', '/var/lib/azerioid-panel-composer']) {
                $baseRootOwned = true;
            }
            if ($e['command'] === ['/usr/bin/chown', '-h', 'caddy:caddy', $home]) {
                $homeToWeb = true;
            }
        }
        $this->assertTrue($baseRootOwned, 'composer base must be reset to root:root');
        $this->assertTrue($homeToWeb, 'the per-run home must be handed to the web user');
    }

    public function test_bootstrap_cache_is_relocked_against_the_web_user(): void
    {
        // A69: config:cache writes config.php 0644 (APP_KEY + DB/mail secrets) in
        // a 0755 dir; caddy traverses the group-caddy web root, so the cache must
        // be re-locked (all "other" access stripped) after it is built.
        $rt = new FakeRuntime();
        $config = new \AzerioidPanel\Broker\Config();
        $prefix = $config->panelRoot;
        $rt->dirs[$prefix . '/web/bootstrap/cache'] = true;
        $updater = new \AzerioidPanel\Broker\Panel\PanelUpdater($config, $rt);
        $m = new \ReflectionMethod(\AzerioidPanel\Broker\Panel\PanelUpdater::class, 'lockBootstrapCache');
        $m->setAccessible(true);
        $log = new \AzerioidPanel\Broker\Component\OperationLogger($rt, '/dev/null');
        $m->invoke($updater, $prefix, $log);

        $locked = false;
        foreach ($rt->execLog as $e) {
            // The directory itself is denied to "other" (caddy), which blocks
            // traversal to config.php — a single chmod, no recursive symlink walk.
            if ($e['command'] === ['/bin/chmod', '0750', $prefix . '/web/bootstrap/cache']) {
                $locked = true;
            }
            $this->assertNotSame('-R', $e['command'][1] ?? '', 'no recursive chmod as root over the cache tree');
        }
        $this->assertTrue($locked, 'bootstrap/cache dir must be denied to the web user after config:cache');
    }

    public function test_harden_content_sweeps_symlinks_and_tightens_env(): void
    {
        // A72: per-site hardening sweeps escaping symlinks (bounded by the site
        // top) and forces .env to 0600 so caddy cannot read site secrets.
        $rt = new FakeRuntime();
        $rt->uid = 0;
        $rt->files['/usr/sbin/runuser'] = '';
        $config = new \AzerioidPanel\Broker\Config();
        $config->wwwRoot = '/data/www';
        $rt->dirs['/data/www/site'] = true;
        \AzerioidPanel\Broker\Vhost\VhostUser::hardenContent($rt, $config, '/data/www/site/public', 'az-vh-site');

        $this->assertNotEmpty($rt->execLog);
        $cmd = $rt->execLog[array_key_last($rt->execLog)]['command'];
        // Runs AS THE SITE USER (not root), so a raced swap cannot escalate.
        $this->assertSame('/usr/sbin/runuser', $cmd[0]);
        $this->assertSame(['-u', 'az-vh-site'], [$cmd[1], $cmd[2]]);
        $this->assertSame('-c', $cmd[5]);
        $script = (string) ($cmd[6] ?? '');
        $args = array_slice($cmd, 7);
        // boundary is the site top, not the docroot (so storage links survive)
        $this->assertContains('/data/www/site', $args);
        $this->assertStringContainsString('-print0', $script, 'newline-safe iteration');
        $this->assertStringContainsString('realpath', $script);
        $this->assertStringContainsString('rm -f', $script);
        $this->assertStringContainsString('.env', $script);
        $this->assertStringContainsString('chmod 0600', $script);
    }

    public function test_caddy_access_log_is_created_as_the_web_user(): void
    {
        // A76: /var/log/caddy is owned by the web user; the log file must be
        // created/moded AS that user, never via a root op on the path, or a
        // planted symlink would make root chown/chmod an arbitrary target.
        $rt = new FakeRuntime();
        $rt->uid = 0;
        $rt->files['/usr/sbin/runuser'] = '';
        $rt->dirs['/var/log/caddy'] = true;
        $config = new \AzerioidPanel\Broker\Config();
        $m = new \ReflectionMethod(\AzerioidPanel\Broker\Web\CaddyDriver::class, 'ensureAccessLog');
        $m->setAccessible(true);
        $m->invoke(new \AzerioidPanel\Broker\Web\CaddyDriver(), $rt, $config, 'shop.test');

        $logPath = '/var/log/caddy/access_shop.test.log';
        $asWebUser = false;
        foreach ($rt->execLog as $e) {
            $cmd = $e['command'];
            if (($cmd[0] ?? '') === '/usr/sbin/runuser' && in_array($logPath, $cmd, true)) {
                $asWebUser = true;
                $this->assertSame(['-u', $config->webUser], [$cmd[1], $cmd[2]]);
            }
            if (in_array(($cmd[0] ?? ''), ['/usr/bin/chown', '/bin/chmod'], true)) {
                $this->assertNotContains($logPath, $cmd, 'no root chown/chmod of the log path');
            }
        }
        $this->assertTrue($asWebUser, 'the access log must be created as the web user');
    }

    public function test_web_root_rejects_control_characters(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/data/www'] = true;
        $this->expectException(BrokerException::class);
        Validator::webRoot("/data/www/evil\n[program:x]", '/data/www', $rt);
    }

    /** @return array<string, array{0:string}> */
    public static function configSyntaxNames(): array
    {
        return ['semicolon' => ['x;y'], 'space' => ['x y'], 'brace' => ['x}y'], 'dollar' => ['x$y'], 'quote' => ["x'y"]];
    }

    /**
     * A93: a docroot that resolves, through a symlink the site controls, to a directory
     * whose name is web-server config syntax must be refused - the path is rendered
     * unquoted into nginx and Caddy files.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configSyntaxNames')]
    public function test_web_root_refuses_a_resolved_path_with_config_syntax(string $name): void
    {
        $www = sys_get_temp_dir() . '/azaudit-www-' . bin2hex(random_bytes(4));
        mkdir($www . '/site/' . $name, 0700, true);
        symlink($www . '/site/' . $name, $www . '/site/public');
        try {
            Validator::webRoot($www . '/site/public', $www, new PosixRuntime());
            $this->fail('expected refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('cannot hold', $e->getMessage());
        } finally {
            unlink($www . '/site/public');
            rmdir($www . '/site/' . $name);
            rmdir($www . '/site');
            rmdir($www);
        }
    }

    /** A94: a symlink inside one site's tree must not move its docroot into another site. */
    public function test_web_root_refuses_a_symlink_into_another_site_directory(): void
    {
        $www = sys_get_temp_dir() . '/azaudit-www-' . bin2hex(random_bytes(4));
        mkdir($www . '/a.test', 0700, true);
        mkdir($www . '/b.test/current', 0700, true);
        symlink($www . '/b.test', $www . '/a.test/public');
        symlink($www . '/b.test/current', $www . '/b.test/public');
        try {
            try {
                Validator::webRoot($www . '/a.test/public', $www, new PosixRuntime());
                $this->fail('expected refusal');
            } catch (BrokerException $e) {
                $this->assertStringContainsString('another directory under the base', $e->getMessage());
            }
            // A link that stays inside its own site directory is still fine.
            $this->assertSame(
                realpath($www) . '/b.test/current',
                Validator::webRoot($www . '/b.test/public', $www, new PosixRuntime())
            );
        } finally {
            unlink($www . '/a.test/public');
            unlink($www . '/b.test/public');
            rmdir($www . '/b.test/current');
            rmdir($www . '/b.test');
            rmdir($www . '/a.test');
            rmdir($www);
        }
    }

    public function test_web_root_still_accepts_an_ordinary_nested_docroot(): void
    {
        $www = sys_get_temp_dir() . '/azaudit-www-' . bin2hex(random_bytes(4));
        mkdir($www . '/shop.example.com/releases/v1.2_3/public', 0700, true);
        try {
            $real = (string) realpath($www);
            $this->assertSame(
                $real . '/shop.example.com/releases/v1.2_3/public',
                Validator::webRoot($www . '/shop.example.com/releases/v1.2_3/public', $www, new PosixRuntime())
            );
        } finally {
            foreach (['/shop.example.com/releases/v1.2_3/public', '/shop.example.com/releases/v1.2_3', '/shop.example.com/releases', '/shop.example.com', ''] as $d) {
                rmdir($www . $d);
            }
        }
    }

    /** A94: a dangling symlink in the requested path is refused (its future target is unknown). */
    public function test_web_root_refuses_a_dangling_symlink_on_the_way(): void
    {
        $www = sys_get_temp_dir() . '/azaudit-www-' . bin2hex(random_bytes(4));
        mkdir($www . '/a.test', 0700, true);
        symlink($www . '/b.test/not-yet', $www . '/a.test/public');
        try {
            $this->expectException(BrokerException::class);
            $this->expectExceptionMessage('target does not exist');
            Validator::webRoot($www . '/a.test/public/html', $www, new PosixRuntime());
        } finally {
            unlink($www . '/a.test/public');
            rmdir($www . '/a.test');
            rmdir($www);
        }
    }

    /** A94: the overlap check compares resolved roots, so a stored root written through a symlinked base still matches. */
    public function test_root_overlap_check_compares_resolved_paths(): void
    {
        $real = sys_get_temp_dir() . '/azaudit-real-' . bin2hex(random_bytes(4));
        $alias = $real . '-alias';
        mkdir($real . '/b.test/public', 0700, true);
        symlink($real, $alias);
        $cfg = new Config();
        $cfg->wwwRoot = $alias;
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "import /etc/caddy/conf.d/*.conf\n";
        // b.test was stored through the alias of the base, not its real path.
        $rt->files['/etc/caddy/conf.d/b.test.conf'] = "b.test {\n    root * {$alias}/b.test/public\n    file_server\n}\n";
        try {
            \AzerioidPanel\Broker\Web\WebServers::assertRootNotShared($rt, $cfg, 'a.test', realpath($real) . '/b.test/public');
            $this->fail('expected an overlap refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('overlaps the web root of b.test', $e->getMessage());
        } finally {
            unlink($alias);
            rmdir($real . '/b.test/public');
            rmdir($real . '/b.test');
            rmdir($real);
        }
    }

    /** A94: a site that points its own stored root at '/' must not block every other vhost's edit. */
    public function test_root_overlap_check_does_not_follow_links_inside_other_sites(): void
    {
        $www = sys_get_temp_dir() . '/azaudit-www-' . bin2hex(random_bytes(4));
        mkdir($www . '/b.test', 0700, true);
        mkdir($www . '/a.test/public', 0700, true);
        symlink('/', $www . '/b.test/public');
        $cfg = new Config();
        $cfg->wwwRoot = $www;
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "import /etc/caddy/conf.d/*.conf\n";
        $rt->files['/etc/caddy/conf.d/b.test.conf'] = "b.test {\n    root * {$www}/b.test/public\n    file_server\n}\n";
        try {
            \AzerioidPanel\Broker\Web\WebServers::assertRootNotShared($rt, $cfg, 'a.test', realpath($www) . '/a.test/public');
            $this->assertTrue(true);
        } finally {
            unlink($www . '/b.test/public');
            rmdir($www . '/a.test/public');
            rmdir($www . '/a.test');
            rmdir($www . '/b.test');
            rmdir($www);
        }
    }

    public function test_write_file_is_never_world_readable_for_a_private_mode(): void
    {
        $rt = new PosixRuntime();
        $dir = sys_get_temp_dir() . '/azaudit-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $target = $dir . '/secret';
        try {
            $rt->writeFile($target, "relay:password\n", 0600);
            $this->assertSame("relay:password\n", file_get_contents($target));
            clearstatcache();
            $this->assertSame('0600', substr(sprintf('%o', fileperms($target)), -4),
                'a 0600 secret must land at its final mode, never a 0644 window');
            // No leftover temp files from the atomic write.
            $this->assertSame(['secret'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
        } finally {
            @unlink($target);
            @rmdir($dir);
        }
    }
}
