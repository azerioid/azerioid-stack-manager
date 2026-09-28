<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Vhost\NodeRuntimes;
use AzerioidPanel\Broker\Vhost\Pm2Manager;
use PHPUnit\Framework\TestCase;

/**
 * Request #7 / ADR A51: Node.js majors side by side at versioned prefixes, one per PM2 vhost.
 */
final class NodeRuntimesTest extends TestCase
{
    private const DOMAIN = 'node.example.com';
    private const ROOT = '/data/www/node.example.com';

    private FakeRuntime $rt;
    private Config $cfg;

    private string $tarballSha = 'df450af89261115ef9f9e3830c3eeb2cc9213b63c720b1af623cb5dcbe2e02de';

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $this->cfg->stagingDir = '/var/lib/azerioid-panel/staging';
        $this->rt->execFn = function (array $c, ?string $stdin): ?ExecResult {
            return match (true) {
                $c === ['/usr/bin/uname', '-m'] => new ExecResult($c, 0, "x86_64\n", ''),
                ($c[0] ?? '') === '/usr/bin/sha256sum' => new ExecResult($c, 0, $this->tarballSha . '  ' . $c[1] . "\n", ''),
                str_ends_with((string) ($c[0] ?? ''), '/bin/node') && ($c[1] ?? '') === '--version' => new ExecResult($c, 0, "v22.23.3\n", ''),
                ($c[0] ?? '') === '/usr/bin/tar' => $this->unpack($c),
                in_array('install', $c, true) && in_array(NodeRuntimes::PM2_SPEC, $c, true) => $this->installPm2($c),
                default => null,
            };
        };
    }

    // -------------------------------------------------------------- install

    public function test_install_unpacks_the_pinned_build_into_its_prefix_with_its_own_pm2(): void
    {
        (new NodeRuntimes($this->cfg, $this->rt))->install($this->definition(), $this->log());

        $this->assertArrayHasKey('/opt/azerioid-node/22/bin/node', $this->rt->files);
        $this->assertArrayHasKey('/opt/azerioid-node/22/bin/pm2-runtime', $this->rt->files);
        $npm = $this->command(static fn (array $c): bool => in_array(NodeRuntimes::PM2_SPEC, $c, true));
        $this->assertStringStartsWith('PATH=/opt/azerioid-node/.22.new/bin:', $npm[1], 'pm2 installs under the new Node, not the system one');
        $curl = $this->command(static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/curl');
        $this->assertSame('https://nodejs.org/dist/v22.23.3/node-v22.23.3-linux-x64.tar.xz', end($curl));
        $tar = $this->command(static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/tar');
        $this->assertContains('--no-same-owner', $tar);
    }

    public function test_a_checksum_mismatch_installs_nothing(): void
    {
        $this->tarballSha = str_repeat('0', 64);

        try {
            (new NodeRuntimes($this->cfg, $this->rt))->install($this->definition(), $this->log());
            $this->fail('expected refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('checksum mismatch', $e->getMessage());
        }
        $this->assertArrayNotHasKey('/opt/azerioid-node/22/bin/node', $this->rt->files);
        $this->assertNull($this->command(static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/tar'));
    }

    public function test_an_artifact_url_off_nodejs_org_is_refused(): void
    {
        $definition = $this->definition();
        $definition['artifact']['sources']['x64']['url'] = 'https://example.com/node-v22.23.3-linux-x64.tar.xz';

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage('no valid pinned artifact');
        (new NodeRuntimes($this->cfg, $this->rt))->install($definition, $this->log());
    }

    public function test_the_registry_pins_every_major_for_both_architectures(): void
    {
        foreach (NodeRuntimes::MAJORS as $major) {
            $def = json_decode((string) file_get_contents(__DIR__ . "/../../../registry/components/nodejs-{$major}.json"), true);
            foreach (['x64', 'arm64'] as $arch) {
                $src = $def['artifact']['sources'][$arch];
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $src['sha256']);
                $this->assertStringStartsWith("https://nodejs.org/dist/v{$major}.", $src['url']);
            }
            $this->assertSame(["/opt/azerioid-node/{$major}/bin/node"], $def['distros']['ubuntu']['detect']['paths']);
        }
    }

    // -------------------------------------------------------------- choice

    public function test_default_is_system_node_when_present_otherwise_the_newest_major(): void
    {
        $nodes = new NodeRuntimes($this->cfg, $this->rt);
        $this->rt->files['/opt/azerioid-node/20/bin/node'] = '';
        $this->rt->files['/opt/azerioid-node/24/bin/node'] = '';
        $this->assertSame('24', $nodes->resolve(null));

        $this->rt->files['/usr/bin/node'] = '';
        $this->assertSame(NodeRuntimes::SYSTEM, $nodes->resolve(null));
        $this->assertSame('20', $nodes->resolve('20'));
    }

    public function test_a_major_that_is_not_installed_is_refused(): void
    {
        $this->rt->files['/usr/bin/node'] = '';

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage('Node.js 22 is not installed');
        (new NodeRuntimes($this->cfg, $this->rt))->resolve('22');
    }

    public function test_path_puts_the_chosen_prefix_first(): void
    {
        $this->assertStringStartsWith('PATH=/opt/azerioid-node/24/bin:/usr/local/sbin', NodeRuntimes::pathEnv('24'));
        $this->assertStringStartsWith('PATH=/usr/local/sbin', NodeRuntimes::pathEnv(NodeRuntimes::SYSTEM));
    }

    // ----------------------------------------------------------- PM2 vhosts

    public function test_a_pm2_vhost_runs_under_the_chosen_major_and_can_move(): void
    {
        $kernel = $this->pm2Host();

        [$code, $json] = $this->run_($kernel, 'vhost.pm2.enable', ['node' => '22']);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('22', $json['data']['node']);
        $conf = $this->programConf();
        $this->assertStringContainsString('PATH=/opt/azerioid-node/22/bin:', $conf);
        $this->assertStringContainsString('/opt/azerioid-node/22/bin/pm2-runtime start', $conf);

        [$code, $json] = $this->run_($kernel, 'vhost.pm2.node', ['node' => '24']);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertSame('22', $json['data']['previous']);
        $this->assertStringContainsString('/opt/azerioid-node/24/bin/pm2-runtime start', $this->programConf());
        $this->assertStringContainsString('npm rebuild', $json['data']['note']);
        $this->assertSame([self::DOMAIN], (new Pm2Manager($this->cfg, $this->rt))->vhostsUsingNode('24'));
    }

    public function test_a_major_in_use_cannot_be_uninstalled(): void
    {
        $kernel = $this->pm2Host();
        $this->run_($kernel, 'vhost.pm2.enable', ['node' => '22']);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage(self::DOMAIN);
        (new NodeRuntimes($this->cfg, $this->rt))->uninstall('22', $this->log());
    }

    public function test_vhosts_enabled_before_a51_count_as_system_node(): void
    {
        $this->assertSame(NodeRuntimes::SYSTEM, (new Pm2Manager($this->cfg, $this->rt))->nodeOf(self::DOMAIN));
    }

    // -------------------------------------------------------------- helpers

    private function pm2Host(): Kernel
    {
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/etc/caddy/conf.d/' . self::DOMAIN . '.conf'] = "# azerioid-managed engine=caddy type=static root=" . self::ROOT . "\n"
            . self::DOMAIN . " {\n    root * " . self::ROOT . "\n    file_server\n}\n";
        $this->rt->dirs[self::ROOT] = true;
        $this->rt->files[self::ROOT . '/server.js'] = 'require("http")';
        $this->rt->files[$this->cfg->managedComponentsPath] = json_encode(['components' => [
            'supervisor' => ['unit' => 'supervisor', 'installed_at' => '2026-01-01'],
        ]], JSON_THROW_ON_ERROR);
        $this->rt->dirs['/etc/supervisor/conf.d'] = true;
        $this->rt->dirs['/var/lib/azerioid-supervised'] = true;
        $this->rt->dirs['/var/log/azerioid-supervised'] = true;
        $this->rt->files['/usr/sbin/runuser'] = '';
        foreach (['22', '24'] as $major) {
            foreach (['node', 'npm', 'pm2', 'pm2-runtime'] as $bin) {
                $this->rt->files["/opt/azerioid-node/{$major}/bin/{$bin}"] = '';
            }
        }
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        return new Kernel($this->cfg, $this->rt);
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function run_(Kernel $kernel, string $action, array $stdin): array
    {
        ob_start();
        $code = $kernel->run(['broker', $action, self::DOMAIN], $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true) ?? []];
    }

    private function programConf(): string
    {
        foreach ($this->rt->files as $path => $body) {
            if (str_starts_with($path, '/etc/supervisor/conf.d/') && str_contains($path, 'pm2-node')) {
                return $body;
            }
        }
        $this->fail('no PM2 program written');
    }

    /** @return array<string,mixed> */
    private function definition(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../../registry/components/nodejs-22.json'), true);
    }

    private function log(): OperationLogger
    {
        return new OperationLogger($this->rt, '/var/log/azerioid-panel/components/test.log');
    }

    private function unpack(array $c): ExecResult
    {
        $dest = $c[array_search('-C', $c, true) + 1];
        foreach (['node', 'npm'] as $bin) {
            $this->rt->files["{$dest}/bin/{$bin}"] = '';
        }
        $this->rt->dirs[$dest] = true;

        return new ExecResult($c, 0, '', '');
    }

    private function installPm2(array $c): ExecResult
    {
        $prefix = dirname((string) $c[2], 2);
        foreach (['pm2', 'pm2-runtime'] as $bin) {
            $this->rt->files["{$prefix}/bin/{$bin}"] = '';
        }

        return new ExecResult($c, 0, '', '');
    }

    /** @return list<string>|null */
    private function command(callable $match): ?array
    {
        foreach ($this->rt->execLog as $e) {
            if ($match($e['command'])) {
                return $e['command'];
            }
        }

        return null;
    }
}
