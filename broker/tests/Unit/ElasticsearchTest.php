<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\ComponentPreflight;
use AzerioidPanel\Broker\Component\ComponentRepoInstaller;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Search\ElasticsearchSetup;
use PHPUnit\Framework\TestCase;

/**
 * B7 / ADR A54: Elasticsearch single-node, loopback, password, heap, hard physical-RAM floor.
 */
final class ElasticsearchTest extends TestCase
{
    /** What the 9.x package's security auto-configuration leaves behind. */
    private const AUTOCONFIGURED = <<<'YAML'
# ======================== Elasticsearch Configuration =========================
path.data: /var/lib/elasticsearch
path.logs: /var/log/elasticsearch
#network.host: 192.168.0.1
xpack.security.enabled: true
xpack.security.enrollment.enabled: true
xpack.security.http.ssl:
  enabled: true
  keystore.path: certs/http.p12
xpack.security.transport.ssl:
  enabled: true
  verification_mode: certificate
  keystore.path: certs/transport.p12
  truststore.path: certs/transport.p12
cluster.initial_master_nodes: ["ubuntu-s-1vcpu"]
http.host: 0.0.0.0
YAML;

    public function test_the_config_is_rewritten_for_a_single_loopback_node(): void
    {
        $out = ElasticsearchSetup::rewriteConfig(self::AUTOCONFIGURED);

        $this->assertStringNotContainsString('cluster.initial_master_nodes', $out, 'conflicts with discovery.type single-node');
        $this->assertStringNotContainsString('0.0.0.0', $out);
        $this->assertStringNotContainsString('certs/http.p12', $out, 'the HTTP TLS block goes with its children');
        $this->assertStringContainsString("xpack.security.transport.ssl:\n  enabled: true", $out, 'transport TLS is left as it is');
        $this->assertStringContainsString("path.data: /var/lib/elasticsearch", $out);
        $this->assertStringContainsString("#network.host: 192.168.0.1", $out, 'comments are kept');
        $this->assertSame(1, substr_count($out, 'xpack.security.enabled:'), 'no setting twice');
        foreach (['network.host: 127.0.0.1', 'http.host: 127.0.0.1', 'transport.host: 127.0.0.1', 'discovery.type: single-node', 'xpack.security.http.ssl.enabled: false'] as $line) {
            $this->assertStringContainsString("\n{$line}\n", $out);
        }
    }

    public function test_rewriting_twice_changes_nothing(): void
    {
        $once = ElasticsearchSetup::rewriteConfig(self::AUTOCONFIGURED);

        $this->assertSame($once, ElasticsearchSetup::rewriteConfig($once));
    }

    public function test_heap_is_half_the_ram_within_bounds(): void
    {
        $this->assertSame(1024, ElasticsearchSetup::heapMb(2048));
        $this->assertSame(512, ElasticsearchSetup::heapMb(900));
        $this->assertSame(31744, ElasticsearchSetup::heapMb(128000));
    }

    /** @return iterable<string, array{0:int, 1:int, 2:bool}> MemTotal MB, SwapFree MB, blocked */
    public static function memory(): iterable
    {
        yield '961 MB, no swap' => [961, 0, true];
        yield '961 MB with 4 GB swap: swap does not count' => [961, 4096, true];
        yield '1.9 GB' => [1946, 2048, true];
        yield '2 GB' => [2048, 0, false];
        yield '8 GB' => [8192, 0, false];
    }

    /** @dataProvider memory */
    #[\PHPUnit\Framework\Attributes\DataProvider('memory')]
    public function test_physical_ram_is_a_hard_floor(int $totalMb, int $swapMb, bool $blocked): void
    {
        $rt = new FakeRuntime();
        $rt->files['/proc/meminfo'] = sprintf("MemTotal: %d kB\nMemAvailable: %d kB\nSwapFree: %d kB\n", $totalMb * 1024, $totalMb * 900, $swapMb * 1024);
        $rt->script(['/bin/df', '-Pk', '/var'], 0, "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/vda1 100000000 1 90000000 1% /\n");
        $definition = json_decode((string) file_get_contents(__DIR__ . '/../../../registry/components/elasticsearch.json'), true);

        $result = (new ComponentPreflight(new Config(), $rt, new OsRelease('ubuntu', '24.04', 'noble', 'ubuntu', 'apt')))->check($definition);
        $issues = implode(' ', $result['issues']);

        $this->assertSame($blocked, str_contains($issues, 'physical RAM'), $issues);
        if ($blocked) {
            $this->assertStringContainsString("this host has {$totalMb} MB", $issues);
            $this->assertStringContainsString('Swap is not counted', $issues);
        }
    }

    public function test_a_signing_key_without_the_pinned_fingerprint_is_refused(): void
    {
        $rt = new FakeRuntime();
        $rt->execFn = static fn (array $c, ?string $s): ?ExecResult => ($c[0] ?? '') === '/usr/bin/gpg' && in_array('--show-keys', $c, true)
            ? new ExecResult($c, 0, "pub:-:2048:1:D27D666CD88E42B4:1379356800:::-:\nfpr:::::::::0000000000000000000000000000000000000000:\n", '')
            : null;

        try {
            (new ComponentRepoInstaller($rt))->ensureForInstall(new OsRelease('ubuntu', '24.04', 'noble', 'ubuntu', 'apt'), 'elasticsearch', [], new OperationLogger($rt, '/tmp/es.log'));
            $this->fail('expected refusal');
        } catch (BrokerException $e) {
            $this->assertStringContainsString('pinned fingerprint', $e->getMessage());
        }
        $this->assertArrayNotHasKey('/etc/apt/sources.list.d/elastic-9.x.list', $rt->files);
    }

    public function test_the_pinned_key_adds_the_repository(): void
    {
        $rt = new FakeRuntime();
        $rt->execFn = static fn (array $c, ?string $s): ?ExecResult => ($c[0] ?? '') === '/usr/bin/gpg' && in_array('--show-keys', $c, true)
            ? new ExecResult($c, 0, 'fpr:::::::::' . ComponentRepoInstaller::ELASTIC_KEY_FINGERPRINT . ":\n", '')
            : null;

        (new ComponentRepoInstaller($rt))->ensureForInstall(new OsRelease('ubuntu', '24.04', 'noble', 'ubuntu', 'apt'), 'elasticsearch', [], new OperationLogger($rt, '/tmp/es.log'));

        $this->assertSame(
            "deb [signed-by=/usr/share/keyrings/elasticsearch-keyring.gpg] https://artifacts.elastic.co/packages/9.x/apt stable main\n",
            $rt->files['/etc/apt/sources.list.d/elastic-9.x.list']
        );
    }

    public function test_the_password_is_stored_root_only_and_reaches_curl_on_stdin(): void
    {
        $rt = new FakeRuntime();
        $rt->execFn = static fn (array $c, ?string $s): ?ExecResult => match (true) {
            str_ends_with((string) ($c[0] ?? ''), 'elasticsearch-reset-password') => new ExecResult($c, 0, "S3cr3t-pw\n", ''),
            ($c[0] ?? '') === '/usr/bin/curl' => new ExecResult($c, 0, '{"cluster_name":"es","status":"green"}', ''),
            default => null,
        };
        $es = new ElasticsearchSetup(new Config(), $rt);

        $this->assertSame('S3cr3t-pw', $es->resetPassword()['password']);
        $this->assertSame(0600, $rt->modes[ElasticsearchSetup::CREDENTIALS] ?? null);
        $es->get('/_cluster/health');

        $curl = array_values(array_filter($rt->execLog, static fn (array $e): bool => ($e['command'][0] ?? '') === '/usr/bin/curl'))[0];
        $this->assertStringNotContainsString('S3cr3t-pw', implode(' ', $curl['command']));
        $this->assertStringContainsString('user = "elastic:S3cr3t-pw"', (string) $curl['stdin']);
    }
}
