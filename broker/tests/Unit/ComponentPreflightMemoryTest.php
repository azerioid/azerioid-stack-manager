<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Component\ComponentPreflight;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

final class ComponentPreflightMemoryTest extends TestCase
{
    public function test_warns_when_physical_low_but_combined_ok(): void
    {
        $rt = $this->runtime(
            "MemAvailable: 409600 kB\nSwapFree: 1048576 kB\n"
        );
        $result = $this->checkMariadb($rt);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['issues']);
        $this->assertNotSame([], $result['warnings']);
        $this->assertStringContainsString('Physical MemAvailable', $result['warnings'][0]);
        $this->assertStringContainsString('SwapFree', $result['warnings'][0]);
        $this->assertSame(400, $result['mem_available_mb']);
        $this->assertSame(1024, $result['swap_free_mb']);
        $this->assertSame(1424, $result['ram_mb_available']);
    }

    public function test_hard_blocks_when_combined_below_threshold(): void
    {
        $rt = $this->runtime(
            "MemAvailable: 204800 kB\nSwapFree: 204800 kB\n"
        );
        $result = $this->checkMariadb($rt);

        $this->assertFalse($result['ok']);
        $this->assertNotSame([], $result['issues']);
        $this->assertStringContainsString('combined headroom', $result['issues'][0]);
        $this->assertSame([], $result['warnings']);
    }

    public function test_ok_without_warning_when_physical_enough(): void
    {
        $rt = $this->runtime(
            "MemAvailable: 2097152 kB\nSwapFree: 0 kB\n"
        );
        $result = $this->checkMariadb($rt);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['warnings']);
    }

    private function runtime(string $meminfo): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $rt->files['/proc/meminfo'] = $meminfo;
        $rt->script(
            ['/bin/df', '-B1', '-P', '/var'],
            0,
            "Filesystem 1B-blocks Used Available Capacity Mounted on\n/dev/sda1 10000000000 1000000000 9000000000 10% /var\n"
        );

        return $rt;
    }

    /** @return array<string, mixed> */
    private function checkMariadb(FakeRuntime $rt): array
    {
        $cfg = new Config();
        $cfg->registryComponentsPath = dirname(__DIR__, 3) . '/registry/components';
        foreach (glob($cfg->registryComponentsPath . '/*.json') ?: [] as $path) {
            $rt->files[$path] = (string) file_get_contents($path);
        }
        $os = OsRelease::detect($rt);
        $definition = json_decode($rt->files[$cfg->registryComponentsPath . '/mariadb.json'], true);

        return (new ComponentPreflight($cfg, $rt, $os))->check($definition);
    }
}
