<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\ComponentInstaller;
use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

final class DockerUninstallRefusalTest extends TestCase
{
    public function test_uninstall_refuses_when_vhost_uses_docker_runtime(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n";
        $rt->dirs['/var/lib/azerioid-panel'] = true;
        $rt->dirs['/var/lib/azerioid-panel/staging'] = true;
        $rt->dirs['/var/lib/azerioid-panel/staging/operations'] = true;
        $registry = dirname(__DIR__, 3) . '/registry/components';
        $rt->dirs[rtrim($registry, '/')] = true;
        foreach (glob($registry . '/*.json') ?: [] as $path) {
            $rt->files[$path] = (string) file_get_contents($path);
        }
        $cfg = new Config();
        $cfg->registryComponentsPath = $registry;
        $cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $cfg->stagingDir = '/var/lib/azerioid-panel/staging';
        $cfg->caddyConfD = '/etc/caddy/conf.d';

        ManagedManifest::record($rt, $cfg->managedComponentsPath, 'docker', [
            'unit' => '',
            'packages' => ['docker-ce'],
            'installed_at' => $rt->now(),
        ]);
        $rt->files['/etc/caddy/conf.d/app.test.conf'] = <<<'CADDY'
# azerioid-managed engine=caddy type=proxy root=/data/www/app.test runtime=docker docker_port=37000 docker_internal_port=80 docker_mode=image docker_image=nginx:alpine
app.test {
    reverse_proxy 127.0.0.1:37000 {
        header_up Host {http.request.host}
    }
}
CADDY;

        $installer = new ComponentInstaller($cfg, $rt);
        try {
            $installer->uninstall('docker', 'op-test');
            $this->fail('Expected BrokerException');
        } catch (BrokerException $e) {
            $this->assertSame(3, $e->errorCode);
            $this->assertStringContainsString('runtime=docker', $e->getMessage());
            $this->assertStringContainsString('app.test', $e->getMessage());
        }
    }
}
