<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Vhost\DockerManager;
use PHPUnit\Framework\TestCase;

final class DockerImageActionsTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $this->rt->dirs['/var/lib/azerioid-panel'] = true;
        $this->rt->files['/usr/bin/docker'] = 'fake';
        $this->rt->files['/usr/bin/curl'] = 'fake';
        $this->rt->files['/usr/sbin/runuser'] = 'fake';
        $this->rt->script(['/usr/bin/id', '-u', 'azerioid-supervised'], 0, "1001\n");
        ManagedManifest::record($this->rt, $this->cfg->managedComponentsPath, 'docker', [
            'unit' => '',
            'packages' => ['docker-ce'],
            'installed_at' => $this->rt->now(),
        ]);
        $this->kernel = new Kernel($this->cfg, $this->rt);
    }

    /** @return array{0:int,1:array} */
    private function capture(array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $this->kernel->run($argv, $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    public function test_image_validate_reports_exists_on_manifest_ok(): void
    {
        $image = 'nginx:alpine';
        $env = 'DOCKER_HOST=unix:///run/user/1001/docker.sock';
        $shell = "cd '/tmp' && exec "
            . escapeshellarg('/usr/bin/env') . ' '
            . escapeshellarg($env) . ' '
            . escapeshellarg('/usr/bin/docker') . ' '
            . escapeshellarg('manifest') . ' '
            . escapeshellarg('inspect') . ' '
            . escapeshellarg($image);
        $this->rt->script(
            ['/usr/sbin/runuser', '-u', 'azerioid-supervised', '--', '/bin/bash', '-lc', $shell],
            0,
            "{\"schemaVersion\":2}\n"
        );

        [$code, $json] = $this->capture(['broker', 'vhost.docker.image.validate'], ['image' => $image]);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertTrue($json['data']['ok'] ?? false);
        $this->assertTrue($json['data']['exists'] ?? false);
        $this->assertSame($image, $json['data']['image'] ?? null);
    }

    public function test_image_validate_reports_missing(): void
    {
        $image = 'no/such:image';
        $env = 'DOCKER_HOST=unix:///run/user/1001/docker.sock';
        foreach (['manifest inspect', 'buildx imagetools inspect'] as $unused) {
            // script both failure paths via defaultExec non-ok after overriding default
        }
        $this->rt->defaultExec = new \AzerioidPanel\Broker\ExecResult([], 1, '', 'not found');
        $this->rt->script(['/usr/bin/id', '-u', 'azerioid-supervised'], 0, "1001\n");

        [$code, $json] = $this->capture(['broker', 'vhost.docker.image.validate'], ['image' => $image]);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertTrue($json['data']['ok'] ?? false);
        $this->assertFalse($json['data']['exists'] ?? true);
    }

    public function test_image_search_returns_repo_suggestions(): void
    {
        $query = 'nginx';
        $url = 'https://hub.docker.com/v2/search/repositories/?query=' . rawurlencode($query) . '&page_size=8';
        $payload = json_encode([
            'results' => [
                ['repo_name' => 'library/nginx'],
                ['repo_name' => 'bitnami/nginx'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->rt->script(['/usr/bin/curl', '-fsSL', '--max-time', '8', $url], 0, $payload);

        [$code, $json] = $this->capture(['broker', 'vhost.docker.image.search'], ['query' => $query]);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertTrue($json['data']['ok'] ?? false);
        $names = array_column($json['data']['suggestions'] ?? [], 'repo_name');
        $this->assertContains('library/nginx', $names);
        $this->assertContains('bitnami/nginx', $names);
    }

    public function test_image_search_fails_soft_when_hub_unreachable(): void
    {
        $query = 'redis';
        $url = 'https://hub.docker.com/v2/search/repositories/?query=' . rawurlencode($query) . '&page_size=8';
        $this->rt->script(['/usr/bin/curl', '-fsSL', '--max-time', '8', $url], 1, '', 'timeout');

        [$code, $json] = $this->capture(['broker', 'vhost.docker.image.search'], ['query' => $query]);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertFalse($json['data']['ok'] ?? true);
        $this->assertSame([], $json['data']['suggestions'] ?? null);
    }

    public function test_image_search_rejects_short_query(): void
    {
        [$code, $json] = $this->capture(['broker', 'vhost.docker.image.search'], ['query' => 'a']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('2 characters', (string) ($json['error'] ?? ''));
    }

    public function test_validate_image_static_helper(): void
    {
        $this->assertSame('nginx:alpine', DockerManager::validateImage('nginx:alpine'));
    }
}
