<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class ComponentCatalogTest extends TestCase
{
    private string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registryPath = dirname(__DIR__, 3) . '/registry/components';
    }

    private function kernel(FakeRuntime $rt, ?Config $cfg = null): Kernel
    {
        $cfg ??= new Config();
        $cfg->registryComponentsPath = $this->registryPath;
        return new Kernel($cfg, $rt);
    }

    /** @return array{0:int,1:array} */
    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();
        $json = json_decode(trim((string) $out), true);
        return [$code, $json];
    }

    private function ubuntuRuntime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $rt->dirs[rtrim($this->registryPath, '/')] = true;
        foreach (glob($this->registryPath . '/*.json') ?: [] as $path) {
            $rt->files[$path] = (string) file_get_contents($path);
        }
        return $rt;
    }

    public function test_component_list_returns_all_registry_entries(): void
    {
        [$code, $json] = $this->capture($this->kernel($this->ubuntuRuntime()), ['broker', 'component.list']);
        $this->assertSame(0, $code);
        $this->assertTrue($json['ok']);
        $this->assertSame('ubuntu', $json['data']['distro_key']);
        $this->assertCount(14, $json['data']['components']);
        $ids = array_column($json['data']['components'], 'id');
        $this->assertContains('redis', $ids);
        $this->assertContains('caddy', $ids);
        $this->assertContains('mongodb', $ids);
    }

    public function test_clean_host_marks_non_system_components_not_installed(): void
    {
        [$code, $json] = $this->capture($this->kernel($this->ubuntuRuntime()), ['broker', 'component.list']);
        $this->assertSame(0, $code);
        foreach ($json['data']['components'] as $row) {
            if (($row['kind'] ?? '') !== 'managed') {
                continue;
            }
            $this->assertSame('not_installed', $row['status'], $row['id']);
        }
    }

    public function test_broken_when_package_installed_without_unit(): void
    {
        $rt = $this->ubuntuRuntime();
        $rt->script(['/usr/bin/dpkg-query', '-W', '-f=${Status}', 'redis-server'], 0, 'install ok installed');
        $rt->script(
            ['/usr/bin/systemctl', 'show', 'redis-server', '--property=LoadState', '--no-pager'],
            0,
            "LoadState=not-found\n"
        );

        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'component.status', 'redis']);
        $this->assertSame(0, $code);
        $this->assertSame('broken', $json['data']['status']);
        $this->assertSame('observed', $json['data']['kind']);
    }

    public function test_observed_kind_for_foreign_install(): void
    {
        $rt = $this->ubuntuRuntime();
        $rt->script(['/usr/bin/dpkg-query', '-W', '-f=${Status}', 'redis-server'], 0, 'install ok installed');
        $rt->script(
            ['/usr/bin/systemctl', 'show', 'redis-server', '--property=LoadState', '--no-pager'],
            0,
            "LoadState=loaded\n"
        );
        $rt->script(
            [
                '/usr/bin/systemctl',
                'show',
                'redis-server',
                '--property=Id,ActiveState,SubState,MainPID,NRestarts,ActiveEnterTimestamp,UnitFileState,Description',
                '--no-pager',
            ],
            0,
            "Id=redis-server.service\nActiveState=active\nSubState=running\nMainPID=99\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription=Redis\n"
        );

        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'component.status', 'redis']);
        $this->assertSame(0, $code);
        $this->assertSame('installed', $json['data']['status']);
        $this->assertSame('observed', $json['data']['kind']);
    }

    public function test_system_components_are_marked_active_when_running(): void
    {
        $rt = $this->ubuntuRuntime();
        $rt->script(['/usr/bin/dpkg-query', '-W', '-f=${Status}', 'caddy'], 0, 'install ok installed');
        $rt->script(['/usr/bin/systemctl', 'show', 'caddy', '--property=LoadState', '--no-pager'], 0, "LoadState=loaded\n");
        $rt->script(
            [
                '/usr/bin/systemctl',
                'show',
                'caddy',
                '--property=Id,ActiveState,SubState,MainPID,NRestarts,ActiveEnterTimestamp,UnitFileState,Description',
                '--no-pager',
            ],
            0,
            "Id=caddy.service\nActiveState=active\nSubState=running\nMainPID=1\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription=Caddy\n"
        );

        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'component.status', 'caddy']);
        $this->assertSame(0, $code);
        $this->assertSame('system', $json['data']['kind']);
        $this->assertSame('active', $json['data']['status']);
        $this->assertFalse($json['data']['removable']);
    }

    public function test_unknown_component_id_rejected(): void
    {
        [$code, $json] = $this->capture($this->kernel($this->ubuntuRuntime()), ['broker', 'component.status', 'not-a-real-component']);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
    }
}
