<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Component\ComponentPreflight;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

final class ComponentPreflightConflictTest extends TestCase
{
    public function test_postgresql_preflight_ok_when_mariadb_managed(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $rt->files['/proc/meminfo'] = "MemAvailable: 2097152 kB\n";
        $rt->files['/var/lib/azerioid-panel/managed-components.json'] = json_encode([
            'components' => ['mariadb' => ['unit' => 'mariadb', 'installed_at' => '2026-01-01']],
        ]);
        $rt->script(['/bin/df', '-B1', '-P', '/var'], 0, "Filesystem 1B-blocks Used Available Capacity Mounted on\n/dev/sda1 10000000000 1000000000 9000000000 10% /var\n");

        $cfg = new Config();
        $cfg->registryComponentsPath = dirname(__DIR__, 3) . '/registry/components';
        foreach (glob($cfg->registryComponentsPath . '/*.json') ?: [] as $path) {
            $rt->files[$path] = (string) file_get_contents($path);
        }

        $os = OsRelease::detect($rt);
        $definition = json_decode($rt->files[$cfg->registryComponentsPath . '/postgresql.json'], true);
        $result = (new ComponentPreflight($cfg, $rt, $os))->check($definition);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['issues']);
    }

    public function test_nginx_preflight_ok_when_caddy_owns_80(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $rt->files['/proc/meminfo'] = "MemAvailable: 2097152 kB\n";
        $rt->script(['/bin/df', '-B1', '-P', '/var'], 0, "Filesystem 1B-blocks Used Available Capacity Mounted on\n/dev/sda1 10000000000 1000000000 9000000000 10% /var\n");
        $rt->script(['/usr/bin/ss', '-ltn', 'sport', '=', '80'], 0, "State Recv-Q Send-Q Local Address:Port Peer Address:Port\nLISTEN 0 4096 *:80 *:*\n");
        $rt->script(['/usr/bin/ss', '-ltnp', 'sport', '=', '80'], 0, "LISTEN 0 4096 *:80 users:((\"caddy\",pid=1,fd=1))\n");

        $cfg = new Config();
        $cfg->siteWebServer = 'caddy';
        $cfg->registryComponentsPath = dirname(__DIR__, 3) . '/registry/components';
        foreach (glob($cfg->registryComponentsPath . '/*.json') ?: [] as $path) {
            $rt->files[$path] = (string) file_get_contents($path);
        }

        $os = OsRelease::detect($rt);
        $definition = json_decode($rt->files[$cfg->registryComponentsPath . '/nginx.json'], true);
        $result = (new ComponentPreflight($cfg, $rt, $os))->check($definition);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['remediations']);
    }
}
