<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\VhostIsolationMigrator;
use AzerioidPanel\Broker\Vhost\VhostUser;
use PHPUnit\Framework\TestCase;

/**
 * ADR A49. Every vhost identity used to share the primary group azerioid-vhosts, and every
 * docroot was 2770 with that group — so each site's Terminal, SFTP login and cron jobs could
 * read and write every other site. These tests run against a simulated host (accounts,
 * groups and the group a docroot's files carry) so the property is checked the way the
 * kernel checks it, not by matching command strings.
 */
final class VhostIsolationTest extends TestCase
{
    private FakeRuntime $rt;

    private Config $config;

    /** @var array<string, array{group:string, home:string}> */
    private array $users = [];

    /** @var array<string, array{gid:int, members:list<string>}> */
    private array $groups = [];

    /** @var array<string, string> docroot => group its files carry */
    private array $treeGroup = [];

    /** @var array<string, string> docroot => owner */
    private array $treeOwner = [];

    /** @var list<string> commands that must fail, as "argv joined by spaces" prefixes */
    private array $failing = [];

    private int $nextGid = 2000;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->config = new Config();
        $this->config->webUser = 'caddy';
        $this->config->phpUser = 'www-data';
        $this->config->webService = 'caddy';
        $this->config->panelRoot = '/usr/local/lib/azerioid-panel';
        $this->rt->installedPhp = ['8.3'];

        $this->addGroup('azerioid-vhosts', ['caddy', 'www-data', 'azerioid-supervised']);
        foreach (['caddy', 'www-data', 'azerioid-supervised'] as $reader) {
            $this->addGroup($reader);
            $this->users[$reader] = ['group' => $reader, 'home' => '/nonexistent'];
        }
        $this->rt->execFn = fn (array $cmd, ?string $stdin): ?ExecResult => $this->host($cmd);
    }

    // ------------------------------------------------------------ new vhosts

    public function test_a_new_vhost_identity_gets_a_group_of_its_own(): void
    {
        $root = '/data/www/shop.test';
        $this->rt->dirs[$root] = true;

        VhostUser::ensure($this->rt, $this->config, 'shop.test', $root);

        $this->assertSame('az-vh-shop-test', $this->users['az-vh-shop-test']['group']);
        $this->assertSame('az-vh-shop-test', $this->treeGroup[$root]);
        foreach (['caddy', 'www-data', 'azerioid-supervised'] as $reader) {
            $this->assertContains($reader, $this->groups['az-vh-shop-test']['members']);
        }
        $this->assertNotContains('az-vh-shop-test', $this->groups['azerioid-vhosts']['members']);
    }

    public function test_two_new_vhosts_cannot_open_each_other(): void
    {
        $this->newVhost('a.test');
        $this->newVhost('b.test');

        $this->assertFalse($this->canRead('az-vh-a-test', '/data/www/b.test'));
        $this->assertFalse($this->canRead('az-vh-b-test', '/data/www/a.test'));
        $this->assertTrue($this->canRead('az-vh-a-test', '/data/www/a.test'));
        $this->assertTrue($this->canRead('caddy', '/data/www/b.test'));
        $this->assertTrue($this->canRead('azerioid-supervised', '/data/www/a.test'));
    }

    public function test_creating_a_vhost_defers_the_web_server_restart(): void
    {
        $this->newVhost('a.test');

        $run = $this->commandsStartingWith('/usr/bin/systemd-run');
        $this->assertCount(1, $run);
        $this->assertContains('--on-active=3', $run[0]);
        $this->assertSame(['/usr/bin/systemctl', 'try-restart', 'caddy'], array_slice($run[0], -3));
        $this->assertSame([], array_filter(
            $this->commandsStartingWith('/usr/bin/systemctl'),
            static fn (array $c): bool => ($c[1] ?? '') === 'try-restart' && ($c[2] ?? '') === 'caddy'
        ), 'Caddy must not restart inside the request that created the vhost');
        $this->assertNotEmpty(array_filter(
            $this->commandsStartingWith('/usr/bin/systemctl'),
            static fn (array $c): bool => $c === ['/usr/bin/systemctl', 'try-reload-or-restart', 'php8.3-fpm']
        ), 'php-fpm re-reads groups for each worker it spawns, so a graceful reload is enough');
    }

    public function test_a_legacy_identity_is_left_for_the_migration(): void
    {
        $this->legacyVhost('old.test');
        $this->rt->execLog = [];

        VhostUser::ensure($this->rt, $this->config, 'old.test', '/data/www/old.test');

        $this->assertSame('azerioid-vhosts', $this->users['az-vh-old-test']['group']);
        $this->assertSame('azerioid-vhosts', $this->treeGroup['/data/www/old.test']);
        $this->assertSame([], $this->commandsStartingWith('/usr/sbin/groupadd'));
        $this->assertSame([], $this->commandsStartingWith('/usr/sbin/usermod'));
    }

    public function test_deleting_a_vhost_removes_its_group(): void
    {
        $this->newVhost('gone.test');

        VhostUser::deprovision($this->rt, $this->config, 'gone.test');

        $this->assertArrayNotHasKey('az-vh-gone-test', $this->users);
        $this->assertArrayNotHasKey('az-vh-gone-test', $this->groups);
    }

    // ------------------------------------------------------------- migration

    public function test_before_migration_legacy_identities_can_open_each_other(): void
    {
        $this->legacyVhost('a.test');
        $this->legacyVhost('b.test');

        // The defect itself, reproduced in the model: a group grant across sites.
        $this->assertTrue($this->canRead('az-vh-a-test', '/data/www/b.test'));
        $this->assertTrue($this->canWrite('az-vh-a-test', '/data/www/b.test'));
    }

    public function test_migration_isolates_every_legacy_identity(): void
    {
        $this->legacyVhost('a.test');
        $this->legacyVhost('b.test');
        $this->legacyVhost('c.test');

        $result = $this->migrator()->apply(Validator::ISOLATE_VHOSTS_CONFIRM);

        $this->assertTrue($result['changed']);
        foreach (['a', 'b', 'c'] as $x) {
            $user = "az-vh-{$x}-test";
            $this->assertSame($user, $this->users[$user]['group']);
            $this->assertSame($user, $this->treeGroup["/data/www/{$x}.test"]);
            foreach (['a', 'b', 'c'] as $y) {
                $this->assertSame($x === $y, $this->canRead($user, "/data/www/{$y}.test"), "{$user} → {$y}.test");
            }
            $this->assertTrue($this->canRead('caddy', "/data/www/{$x}.test"));
            $this->assertTrue($this->canRead('www-data', "/data/www/{$x}.test"));
            $this->assertTrue($this->canRead('azerioid-supervised', "/data/www/{$x}.test"));
        }
        $this->assertSame('migrated', $this->state()['result']);
        $this->assertTrue($this->migrator()->status()['migrated']);
    }

    public function test_readers_join_the_new_groups_before_any_file_moves(): void
    {
        $this->legacyVhost('a.test');
        $this->legacyVhost('b.test');

        $this->migrator()->apply(Validator::ISOLATE_VHOSTS_CONFIRM);

        $order = array_map(static fn (array $e): string => implode(' ', $e['command']), $this->rt->execLog);
        $lastGpasswdAdd = max(array_keys(array_filter($order, static fn (string $c): bool => str_starts_with($c, '/usr/bin/gpasswd -a'))));
        $webRestart = array_search('/usr/bin/systemctl try-restart caddy', $order, true);
        $firstChgrp = min(array_keys(array_filter($order, static fn (string $c): bool => str_contains($c, '-exec /usr/bin/chgrp'))));
        $firstUsermod = min(array_keys(array_filter($order, static fn (string $c): bool => str_starts_with($c, '/usr/sbin/usermod'))));

        $this->assertLessThan($webRestart, $lastGpasswdAdd);
        $this->assertLessThan($firstChgrp, $webRestart, 'the web server must hold the new groups before files change group');
        $this->assertLessThan($firstUsermod, $firstChgrp);
    }

    public function test_regrouping_never_follows_symlinks_or_crosses_filesystems(): void
    {
        $this->legacyVhost('a.test');

        $this->migrator()->apply(Validator::ISOLATE_VHOSTS_CONFIRM);

        $chgrp = array_values(array_filter(
            $this->commandsStartingWith('/usr/bin/find'),
            static fn (array $c): bool => in_array('/usr/bin/chgrp', $c, true)
        ));
        $this->assertNotEmpty($chgrp);
        foreach ($chgrp as $cmd) {
            $this->assertContains('-xdev', $cmd);
            $this->assertContains('-h', $cmd);
        }
    }

    public function test_a_failed_verification_puts_everything_back(): void
    {
        $this->legacyVhost('a.test');
        $this->legacyVhost('b.test');
        // A site stops answering once its files change group.
        $this->failing[] = '/usr/bin/curl AFTER';

        try {
            $this->migrator()->apply(Validator::ISOLATE_VHOSTS_CONFIRM);
            $this->fail('expected the migration to fail');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }

        foreach (['a', 'b'] as $x) {
            $user = "az-vh-{$x}-test";
            $this->assertSame('azerioid-vhosts', $this->users[$user]['group']);
            $this->assertSame('azerioid-vhosts', $this->treeGroup["/data/www/{$x}.test"]);
            $this->assertArrayNotHasKey($user, $this->groups, 'the new group is removed again');
        }
        $this->assertSame('failed', $this->state()['result']);
        $this->assertSame([], $this->state()['rollback_errors']);
        $this->assertSame([], array_filter(
            $this->commandsStartingWith('/usr/bin/pkill'),
            static fn (array $c): bool => true
        ), 'processes are only ended once the migration has committed');
    }

    public function test_a_failed_attempt_is_not_retried_automatically(): void
    {
        $this->legacyVhost('a.test');
        $this->rt->files[VhostIsolationMigrator::STATE_FILE] = json_encode(['result' => 'failed', 'error' => 'x']);

        $result = $this->migrator()->converge(false);

        $this->assertFalse($result['started']);
        $this->assertStringContainsString('apply --confirm', $result['reason']);
    }

    public function test_converge_hands_off_to_its_own_unit(): void
    {
        $this->legacyVhost('a.test');

        $result = $this->migrator()->converge(false);

        $this->assertTrue($result['started']);
        $run = $this->commandsStartingWith('/usr/bin/systemd-run');
        $this->assertContains('--unit=' . VhostIsolationMigrator::CONVERGE_UNIT, $run[0]);
        $this->assertSame(['vhost.isolation.converge', 'now'], array_slice($run[0], -2));
        $this->assertSame('azerioid-vhosts', $this->users['az-vh-a-test']['group'], 'converge alone changes nothing');
    }

    public function test_an_identity_whose_home_is_a_system_directory_stops_the_migration(): void
    {
        $this->legacyVhost('a.test');
        $this->users['az-vh-a-test']['home'] = '/etc';

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage('not a site directory');
        $this->migrator()->apply(Validator::ISOLATE_VHOSTS_CONFIRM);
    }

    public function test_apply_requires_the_typed_confirm(): void
    {
        $this->expectException(BrokerException::class);
        $this->migrator()->apply('yes');
    }

    public function test_nothing_to_do_on_a_host_that_never_had_the_shared_group(): void
    {
        unset($this->groups['azerioid-vhosts']);
        $this->newVhost('a.test');

        $status = $this->migrator()->status();

        $this->assertTrue($status['migrated']);
        $this->assertSame([], $status['pending']);
    }

    // ---------------------------------------------------------------- helpers

    private function migrator(): VhostIsolationMigrator
    {
        return new VhostIsolationMigrator($this->rt, $this->config, 0);
    }

    private function newVhost(string $domain): void
    {
        $root = '/data/www/' . $domain;
        $this->rt->dirs[$root] = true;
        VhostUser::ensure($this->rt, $this->config, $domain, $root);
    }

    private function legacyVhost(string $domain): void
    {
        $user = VhostUser::username($domain);
        $root = '/data/www/' . $domain;
        $this->rt->dirs[$root] = true;
        $this->users[$user] = ['group' => 'azerioid-vhosts', 'home' => $root];
        $this->treeGroup[$root] = 'azerioid-vhosts';
        $this->treeOwner[$root] = $user;
        $this->rt->files["/etc/caddy/conf.d/{$domain}.conf"] = "# azerioid-managed engine=caddy type=static root={$root}\n{$domain} {\n    root * {$root}\n    file_server\n}\n";
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        return json_decode($this->rt->files[VhostIsolationMigrator::STATE_FILE], true);
    }

    /** @return list<list<string>> */
    private function commandsStartingWith(string $bin): array
    {
        $out = [];
        foreach ($this->rt->execLog as $entry) {
            if (($entry['command'][0] ?? '') === $bin) {
                $out[] = $entry['command'];
            }
        }

        return $out;
    }

    /** @param list<string> $members */
    private function addGroup(string $name, array $members = []): void
    {
        $this->groups[$name] = ['gid' => $this->nextGid++, 'members' => $members];
    }

    /** @return list<string> */
    private function groupsOf(string $user): array
    {
        $out = [$this->users[$user]['group']];
        foreach ($this->groups as $name => $g) {
            if (in_array($user, $g['members'], true)) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /** 2770 docroots: owner and group get everything, others nothing. */
    private function canRead(string $user, string $root): bool
    {
        return ($this->treeOwner[$root] ?? null) === $user
            || in_array($this->treeGroup[$root] ?? '', $this->groupsOf($user), true);
    }

    private function canWrite(string $user, string $root): bool
    {
        return $this->canRead($user, $root);
    }

    private function ok(string $out = ''): ExecResult
    {
        return new ExecResult([], 0, $out, '');
    }

    private function no(string $err = ''): ExecResult
    {
        return new ExecResult([], 1, '', $err);
    }

    /** @param list<string> $c */
    private function host(array $c): ?ExecResult
    {
        $bin = $c[0] ?? '';
        switch ($bin) {
            case '/usr/bin/getent':
                if ($c[1] === 'group') {
                    $g = $this->groups[$c[2]] ?? null;

                    return $g === null ? new ExecResult([], 2, '', '') : $this->ok("{$c[2]}:x:{$g['gid']}:" . implode(',', $g['members']) . "\n");
                }
                $lines = [];
                foreach ($this->users as $name => $u) {
                    $gid = $this->groups[$u['group']]['gid'] ?? 0;
                    $lines[] = "{$name}:x:1000:{$gid}::{$u['home']}:/bin/bash";
                }

                return $this->ok(implode("\n", $lines) . "\n");
            case '/usr/bin/id':
                $user = $c[2];
                if (!isset($this->users[$user])) {
                    return $this->no();
                }

                return match ($c[1]) {
                    '-gn' => $this->ok($this->users[$user]['group'] . "\n"),
                    '-nG' => $this->ok(implode(' ', $this->groupsOf($user)) . "\n"),
                    default => $this->ok("1000\n"),
                };
            case '/usr/sbin/groupadd':
                $this->addGroup(end($c));

                return $this->ok();
            case '/usr/sbin/groupdel':
                unset($this->groups[$c[1]]);

                return $this->ok();
            case '/usr/bin/gpasswd':
                [$op, $user, $group] = [$c[1], $c[2], $c[3]];
                if (!isset($this->groups[$group])) {
                    return $this->no('no such group');
                }
                $members = array_values(array_diff($this->groups[$group]['members'], [$user]));
                if ($op === '-a') {
                    $members[] = $user;
                }
                $this->groups[$group]['members'] = $members;

                return $this->ok();
            case '/usr/sbin/useradd':
                $name = end($c);
                $gid = $c[array_search('--gid', $c, true) + 1];
                $home = $c[array_search('--home-dir', $c, true) + 1];
                $this->users[$name] = ['group' => $gid, 'home' => $home];

                return $this->ok();
            case '/usr/sbin/userdel':
                unset($this->users[end($c)]);

                return $this->ok();
            case '/usr/sbin/usermod':
                $this->users[$c[3]]['group'] = $c[2];

                return $this->ok();
            case '/usr/bin/chown':
                [$owner, $group] = explode(':', $c[2]);
                $this->treeOwner[$c[3]] = $owner;
                $this->treeGroup[$c[3]] = $group;

                return $this->ok();
            case '/usr/bin/find':
                $root = $c[1];
                $from = $c[array_search('-group', $c, true) + 1];
                $matches = ($this->treeGroup[$root] ?? null) === $from;
                if (in_array('/usr/bin/chgrp', $c, true)) {
                    if ($matches) {
                        $this->treeGroup[$root] = $c[array_search('-h', $c, true) + 1];
                    }

                    return $this->ok();
                }

                return $this->ok($matches ? $root . "\n" : '');
            case '/usr/sbin/runuser':
                $user = $c[2];
                $root = $c[6];

                return $this->canRead($user, $root) ? $this->ok() : $this->no();
            case '/usr/bin/stat':
                return $this->ok("2770\n");
            case '/usr/bin/curl':
                // "before" probes run while every identity is still legacy.
                $migrated = !in_array('azerioid-vhosts', array_values($this->treeGroup), true);
                if ($migrated && in_array('/usr/bin/curl AFTER', $this->failing, true)) {
                    return $this->ok('403');
                }

                return $this->ok('200');
            case '/usr/bin/systemctl':
                return ($c[1] ?? '') === 'is-active' ? new ExecResult([], 3, "inactive\n", '') : $this->ok();
            case '/usr/bin/pgrep':
                return $this->no();
        }

        return null;
    }
}
