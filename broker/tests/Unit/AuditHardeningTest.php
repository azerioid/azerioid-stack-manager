<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\PosixRuntime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Validator;
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

    public function test_web_root_rejects_control_characters(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/data/www'] = true;
        $this->expectException(BrokerException::class);
        Validator::webRoot("/data/www/evil\n[program:x]", '/data/www', $rt);
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
