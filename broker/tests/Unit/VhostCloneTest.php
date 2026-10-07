<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A79 (increment 1): clone a site to a new domain — vhost replicated and the
 * file tree copied, as a fully isolated vhost. Databases and Octane/PM2/Docker
 * runtimes are not cloned yet and the action refuses those sources.
 */
final class VhostCloneTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->cfg = new Config();
        $this->kernel = new Kernel($this->cfg, $this->rt);
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
    }

    /** @return array{0:int,1:array} */
    private function kernelRun(array $argv): array
    {
        ob_start();
        $code = $this->kernel->run($argv, []);
        $out = (string) ob_get_clean();

        return [$code, json_decode(trim($out), true) ?: []];
    }

    public function test_clone_replicates_the_vhost_and_copies_the_files(): void
    {
        [$c] = $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->assertSame(0, $c);
        // The ownership handover reads the settled group off the docroot, then
        // chowns the copied top. Model rsync creating the target directory so the
        // handover's isDir() check sees it (and the pre-copy existence guard does not).
        $this->rt->script(['/usr/bin/stat', '-c', '%G', '/data/www/dst.test/public'], 0, 'az-vh-dst-test');
        $this->rt->execFn = function (array $cmd): mixed {
            if (($cmd[0] ?? '') === '/usr/bin/rsync') {
                $this->rt->dirs['/data/www/dst.test'] = true;
            }

            return null;
        };

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertArrayHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);

        $rsynced = false;
        $ownedByClone = false;
        foreach ($this->rt->execLog as $e) {
            $cmd = $e['command'];
            // The copy must not preserve the source's uid/gid (--no-o --no-g).
            if (($cmd[0] ?? '') === '/usr/bin/rsync'
                && in_array('/data/www/src.test/', $cmd, true)
                && in_array('/data/www/dst.test/', $cmd, true)
                && in_array('--no-o', $cmd, true)
                && in_array('--no-g', $cmd, true)) {
                $rsynced = true;
            }
            // The WHOLE copied tree (not just the docroot) must be handed to the clone.
            if (($cmd[0] ?? '') === '/usr/bin/chown'
                && in_array('-R', $cmd, true)
                && in_array('/data/www/dst.test', $cmd, true)) {
                $ownedByClone = true;
            }
        }
        $this->assertTrue($rsynced, 'the source site tree must be copied without preserving source ownership');
        $this->assertTrue($ownedByClone, 'the whole copied tree must be chowned to the clone identity');
    }

    public function test_clone_refuses_same_domain_and_an_existing_target(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        [$same] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'src.test']);
        $this->assertNotSame(0, $same);

        $this->kernelRun(['broker', 'vhost.add', 'dst.test', '/data/www/dst.test/public', 'php', '8.4']);
        [$exists] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertNotSame(0, $exists);
    }

    public function test_clone_refuses_when_another_vhost_shares_the_source_or_target_tree(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);

        // A site whose root sits under the target top — cloning there would
        // overwrite and take ownership of its files.
        $this->kernelRun(['broker', 'vhost.add', 'other.test', '/data/www/claimed.test/public', 'php', '8.4']);
        [$claimed, $cj] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'claimed.test']);
        $this->assertNotSame(0, $claimed);
        $this->assertStringContainsString('under claimed.test', str_replace('/data/www/', '', strtolower((string) ($cj['error'] ?? ''))));

        // A second site nested inside the source tree — copying it would leak its
        // files into the clone and hand them to the clone's identity.
        $this->kernelRun(['broker', 'vhost.add', 'nested.test', '/data/www/src.test/nested/public', 'php', '8.4']);
        [$nested, $nj] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'fresh.test']);
        $this->assertNotSame(0, $nested);
        $this->assertStringContainsString('source directory', strtolower((string) ($nj['error'] ?? '')));
    }

    public function test_clone_fails_closed_and_rolls_back_when_the_identity_handover_cannot_resolve(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->rt->execFn = function (array $cmd): mixed {
            if (($cmd[0] ?? '') === '/usr/bin/rsync') {
                $this->rt->dirs['/data/www/dst.test'] = true;
            }

            return null;
        };
        // No stat script → the settled group reads empty → the handover must fail
        // closed: remove the partial copy and the just-created vhost, then error.
        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertNotSame(0, $code);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);

        $removed = false;
        foreach ($this->rt->execLog as $e) {
            $cmd = $e['command'];
            if (($cmd[0] ?? '') === '/bin/rm' && in_array('/data/www/dst.test', $cmd, true)) {
                $removed = true;
            }
        }
        $this->assertTrue($removed, 'the partial copy must be removed on a failed handover');
    }

    public function test_clone_refuses_a_docker_source(): void
    {
        // Docker carries too much state to replicate safely — still refused.
        $this->rt->files['/etc/caddy/conf.d/box.test.conf'] =
            "# azerioid-managed engine=caddy type=proxy root=/data/www/box.test runtime=docker docker_port=37000\n"
            . "box.test {\n    reverse_proxy 127.0.0.1:37000\n}\n";

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'box.test', 'boxclone.test']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not supported yet', strtolower((string) ($json['error'] ?? '')));
    }

    public function test_clone_attempts_to_replicate_an_octane_runtime(): void
    {
        // An Octane source is no longer refused: the clone's files are copied and
        // the runtime is enabled best-effort. Enabling needs Supervisor/Composer,
        // which the fake host lacks, so it is reported as a runtime_error rather
        // than failing the whole clone.
        $this->rt->files['/etc/caddy/conf.d/app.test.conf'] =
            "# azerioid-managed engine=caddy type=php root=/data/www/app.test/public runtime=octane octane_port=34000 octane_max_requests=500 php_version=8.4\n"
            . "app.test {\n    reverse_proxy 127.0.0.1:34000\n}\n";
        $this->rt->script(['/usr/bin/stat', '-c', '%G', '/data/www/appclone.test/public'], 0, 'az-vh-appclone-test');
        $this->rt->execFn = function (array $cmd): mixed {
            if (($cmd[0] ?? '') === '/usr/bin/rsync') {
                $this->rt->dirs['/data/www/appclone.test'] = true;
            }

            return null;
        };

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'app.test', 'appclone.test']);
        $this->assertSame(0, $code, json_encode($json));
        $data = $json['data'] ?? [];
        $this->assertTrue(
            ($data['runtime'] ?? null) === 'octane' || ($data['runtime_error'] ?? null) !== null,
            'an octane source must be replicated or report why it could not be'
        );
    }
}
