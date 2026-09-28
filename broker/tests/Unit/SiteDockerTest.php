<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Supervisor\ProgramIdentity;
use AzerioidPanel\Broker\Supervisor\ProgramIdentityMigrator;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\SiteDocker;
use AzerioidPanel\Broker\Vhost\VhostUser;
use PHPUnit\Framework\TestCase;

/**
 * ADR A56 part 2 and 3: every Docker site gets a rootless daemon of its own, run by the site's
 * identity; once no site program runs as azerioid-supervised it leaves every site's group.
 */
final class SiteDockerTest extends TestCase
{
    private const DOMAIN = 'box.example.com';
    private const ROOT = '/data/www/box.example.com';
    private const CONF = '/etc/caddy/conf.d/box.example.com.conf';
    private const USER = 'az-vh-box-example-com';
    private const HOME = '/var/lib/azerioid-docker-home/box-example-com';

    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    private int $httpOnSite = 200;
    private string $volumes = '';

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files[self::CONF] = '# azerioid-managed engine=caddy type=static root=' . self::ROOT . "\n"
            . self::DOMAIN . " {\n    root * " . self::ROOT . "\n    file_server\n}\n";
        $this->rt->dirs[self::ROOT] = true;
        $this->rt->dirs['/run/user/1005'] = true;
        $this->rt->dirs['/run/user/1001'] = true;
        $this->rt->files[$this->cfg->managedComponentsPath] = json_encode(['components' => [
            'supervisor' => ['unit' => 'supervisor'], 'docker' => ['unit' => ''],
        ]]);
        $this->rt->files['/etc/subuid'] = "azerioid-supervised:100000:65536\n";
        $this->rt->files['/etc/subgid'] = "azerioid-supervised:100000:65536\n";
        $this->rt->dirs['/etc/supervisor/conf.d'] = true;
        $this->rt->files['/usr/sbin/runuser'] = '';
        $this->rt->files['/usr/bin/docker'] = '';
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $this->rt->execFn = function (array $c, ?string $stdin): ?ExecResult {
            $line = implode(' ', $c);
            $ok = static fn (string $out): ExecResult => new ExecResult($c, 0, $out, '');

            return match (true) {
                $c === ['/usr/bin/id', '-u', self::USER] => $ok("1005\n"),
                $c === ['/usr/bin/id', '-u', 'azerioid-supervised'] => $ok("1001\n"),
                $c === ['/usr/bin/id', '-g', self::USER] => $ok("969\n"),
                $c === ['/usr/bin/id', '-g', 'azerioid-supervised'] => $ok("1001\n"),
                $c === ['/usr/bin/id', '-nG', 'azerioid-supervised'] => $ok("azerioid-supervised " . self::USER . " az-vh-other-example-com\n"),
                str_contains($line, 'docker info') => $ok("[name=rootless]\n"),
                str_contains($line, 'volume ls') => $ok($this->volumes),
                ($c[0] ?? '') === '/usr/bin/find' && in_array('-printf', $c, true) => $ok("1001 969\n100101 100101\n969 969\n"),
                ($c[0] ?? '') === '/usr/bin/curl' => $ok(str_contains($this->rt->files[$this->programConf()] ?? '', 'user=' . self::USER) ? (string) $this->httpOnSite : '200'),
                ($c[0] ?? '') === '/usr/bin/supervisorctl' && ($c[1] ?? '') === 'status' => $ok(($c[2] ?? '') . ' RUNNING pid 42'),
                default => null,
            };
        };
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    public function test_a_new_docker_site_gets_a_daemon_of_its_own(): void
    {
        [$code, $json] = $this->broker(['vhost.docker.enable', self::DOMAIN], ['mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80]);

        $this->assertSame(0, $code, json_encode($json));
        $this->assertTrue(SiteDocker::ready($this->rt, self::DOMAIN));
        $this->assertStringContainsString(self::USER . ":165536:65536\n", $this->rt->files['/etc/subuid'], 'a subordinate range of its own, after the shared one');
        $dropin = $this->rt->files['/etc/systemd/system/user@1005.service.d/azerioid-docker.conf'];
        $this->assertStringContainsString('XDG_DATA_HOME=' . self::HOME . '/.local/share', $dropin, 'image data outside the docroot');
        $this->assertSame(0700, $this->rt->modes[self::HOME] ?? null);
        $this->assertContains(['/usr/bin/loginctl', 'enable-linger', self::USER], $this->commands());

        $program = $this->rt->files[$this->programConf()];
        $this->assertStringContainsString('user=' . self::USER . "\n", $program);
        $this->assertStringContainsString('DOCKER_HOST=unix:///run/user/1005/docker.sock', $program);
        $this->assertStringContainsString('environment=HOME="' . self::HOME . '"', $program);
        foreach ($this->commands() as $cmd) {
            if (($cmd[0] ?? '') === '/usr/sbin/runuser' && str_contains(implode(' ', $cmd), 'pull')) {
                $this->assertSame(self::USER, $cmd[2], 'the image is pulled into the site\'s daemon, as the site');
            }
        }
    }

    public function test_the_migration_moves_a_shared_daemon_site_and_renumbers_its_files(): void
    {
        $this->legacyDockerSite();
        $migrator = new ProgramIdentityMigrator($this->rt, $this->cfg, 0);
        $this->assertSame([DockerManager::programName(self::DOMAIN)], $migrator->status()['pending']);

        $out = $migrator->converge(true);

        $result = $out['programs'][DockerManager::programName(self::DOMAIN)];
        $this->assertSame('isolated', $result['result'], json_encode($result));
        $this->assertStringContainsString('user=' . self::USER . "\n", $this->rt->files[$this->programConf()]);
        // The old daemon owner (a container's root) becomes the site; a container's uid 101
        // moves from the shared range to the same offset in the site's.
        $this->assertContains(['/usr/bin/find', self::ROOT, '-xdev', '-uid', '1001', '-exec', '/usr/bin/chown', '-h', '1005', '{}', '+'], $this->commands());
        $this->assertContains(['/usr/bin/find', self::ROOT, '-xdev', '-uid', '100101', '-exec', '/usr/bin/chown', '-h', '165637', '{}', '+'], $this->commands());
        $this->assertContains(['/usr/bin/find', self::ROOT, '-xdev', '-gid', '100101', '-exec', '/usr/bin/chgrp', '-h', '165637', '{}', '+'], $this->commands());
        $this->assertNotContains(['/usr/bin/find', self::ROOT, '-xdev', '-gid', '969', '-exec', '/usr/bin/chgrp', '-h', '969', '{}', '+'], $this->commands());
    }

    public function test_every_site_program_moved_takes_the_shared_account_out_of_the_site_groups(): void
    {
        $this->legacyDockerSite();

        $out = (new ProgramIdentityMigrator($this->rt, $this->cfg, 0))->converge(true);

        $this->assertSame([self::USER, 'az-vh-other-example-com'], $out['detached_from']);
        $this->assertContains(['/usr/bin/gpasswd', '-d', 'azerioid-supervised', self::USER], $this->commands());
        $this->assertTrue(ProgramIdentity::detached($this->rt));
        $this->assertTrue((new ProgramIdentityMigrator($this->rt, $this->cfg, 0))->status()['migrated']);
        // Processes keep the groups they started with: the shared daemon and the shared
        // account's programs restart after the removal.
        $this->assertContains(['/usr/bin/systemctl', 'restart', 'user@1001.service'], $this->commands());
        $this->assertNotContains('azerioid-supervised', VhostUser::readerUsers($this->rt, $this->cfg), 'new sites no longer add it');
    }

    public function test_a_removal_by_an_older_release_is_done_again(): void
    {
        $this->legacyDockerSite();
        $migrator = new ProgramIdentityMigrator($this->rt, $this->cfg, 0);
        $migrator->converge(true);
        $this->rt->files[ProgramIdentity::DETACHED_MARKER] = "2026-09-28T14:50:59+00:00\n";

        $this->assertFalse($migrator->status()['migrated']);
        $this->assertTrue($migrator->status()['auto_eligible']);
    }

    public function test_status_is_not_done_before_the_shared_account_left_the_groups(): void
    {
        $this->legacyDockerSite();
        $migrator = new ProgramIdentityMigrator($this->rt, $this->cfg, 0);
        $migrator->converge(true);
        $this->rt->deleteFile(ProgramIdentity::DETACHED_MARKER);

        $status = $migrator->status();

        $this->assertFalse($status['migrated']);
        $this->assertTrue($status['auto_eligible'], 'the converge finishes the removal');
    }

    public function test_a_docker_site_that_breaks_on_its_own_daemon_goes_back(): void
    {
        $this->legacyDockerSite();
        $this->httpOnSite = 502;

        $out = (new ProgramIdentityMigrator($this->rt, $this->cfg, 0))->converge(true);

        $result = $out['programs'][DockerManager::programName(self::DOMAIN)];
        $this->assertSame('shared', $result['result']);
        $this->assertStringContainsString('user=azerioid-supervised', $this->rt->files[$this->programConf()]);
        $this->assertFalse(SiteDocker::ready($this->rt, self::DOMAIN));
        $this->assertStringNotContainsString(self::USER . ':', $this->rt->files['/etc/subuid'], 'its daemon and range are removed again');
        $this->assertSame([], $out['detached_from'], 'the shared account keeps its groups while it runs a site');
    }

    public function test_compose_named_volumes_are_not_left_behind(): void
    {
        $this->legacyDockerSite();
        $this->volumes = "docker-box-example-com_pgdata\n";

        $out = (new ProgramIdentityMigrator($this->rt, $this->cfg, 0))->converge(true);

        $result = $out['programs'][DockerManager::programName(self::DOMAIN)];
        $this->assertSame('shared', $result['result']);
        $this->assertStringContainsString('docker-box-example-com_pgdata', $result['reason']);
        $this->assertArrayNotHasKey('/etc/systemd/system/user@1005.service.d/azerioid-docker.conf', $this->rt->files, 'nothing was set up');
    }

    /** A Docker site enabled before A56: its program runs on the shared daemon. */
    private function legacyDockerSite(): void
    {
        ProgramIdentity::putBack($this->rt, self::DOMAIN, 'test: created before A56');
        [$code, $json] = $this->broker(['vhost.docker.enable', self::DOMAIN], ['mode' => 'image', 'image' => 'nginx:alpine', 'internal_port' => 80]);
        $this->assertSame(0, $code, json_encode($json));
        $this->rt->files[ProgramIdentity::SHARED_FILE] = "{}\n";
        $this->assertStringContainsString('user=azerioid-supervised', $this->rt->files[$this->programConf()]);
        $this->rt->execLog = [];
    }

    private function programConf(): string
    {
        return '/etc/supervisor/conf.d/azerioid-' . DockerManager::programName(self::DOMAIN) . '.conf';
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
}
