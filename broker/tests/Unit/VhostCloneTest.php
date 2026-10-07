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

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertArrayHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);

        $rsynced = false;
        foreach ($this->rt->execLog as $e) {
            $cmd = $e['command'];
            if (($cmd[0] ?? '') === '/usr/bin/rsync'
                && in_array('/data/www/src.test/', $cmd, true)
                && in_array('/data/www/dst.test/', $cmd, true)) {
                $rsynced = true;
            }
        }
        $this->assertTrue($rsynced, 'the source site tree must be copied to the clone');
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

    public function test_clone_refuses_a_runtime_source_for_now(): void
    {
        // A docker-runtime source is not cloneable in increment 1.
        $this->rt->files['/etc/caddy/conf.d/box.test.conf'] =
            "# azerioid-managed engine=caddy type=proxy root=/data/www/box.test runtime=docker docker_port=37000\n"
            . "box.test {\n    reverse_proxy 127.0.0.1:37000\n}\n";

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'box.test', 'boxclone.test']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not supported yet', strtolower((string) ($json['error'] ?? '')));
    }
}
