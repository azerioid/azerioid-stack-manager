<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class PhpFpmServiceTest extends TestCase
{
    public function test_debian_style_name_without_runtime(): void
    {
        $this->assertSame('php8.4-fpm', (new Config())->phpFpmService('8.4'));
        $this->assertSame('php8.3-fpm', (new Config())->phpFpmService('8.3'));
    }

    public function test_el_status_all_uses_remi_unit_names_not_debian_phantoms(): void
    {
        $rt = new FakeRuntime();
        $rt->installedPhp = ['8.3', '8.4'];
        $rt->files['/etc/os-release'] = "ID=almalinux\nVERSION_ID=\"9.8\"\n";
        $rt->files['/usr/local/lib/azerioid-panel/registry/components/php-8.3.json'] = json_encode([
            'id' => 'php-8.3',
            'distros' => ['el' => ['unit_name' => 'php83-php-fpm']],
        ], JSON_THROW_ON_ERROR);
        $rt->files['/usr/local/lib/azerioid-panel/registry/components/php-8.4.json'] = json_encode([
            'id' => 'php-8.4',
            'distros' => ['el' => ['unit_name' => 'php-fpm']],
        ], JSON_THROW_ON_ERROR);

        foreach (['php83-php-fpm', 'php-fpm', 'caddy', 'mariadb'] as $unit) {
            $this->scriptLoad($rt, $unit, 'loaded');
            $this->scriptShow($rt, $unit, 'active', 'running', 10);
        }
        foreach (['php8.3-fpm', 'php8.4-fpm', 'php84-php-fpm'] as $unit) {
            $this->scriptLoad($rt, $unit, 'not-found');
            $this->scriptShow($rt, $unit, 'inactive', 'dead', 0);
        }

        $cfg = new Config();
        $this->assertSame('php83-php-fpm', $cfg->phpFpmService('8.3', $rt));
        $this->assertSame('php-fpm', $cfg->phpFpmService('8.4', $rt));

        ob_start();
        $code = (new Kernel($cfg, $rt))->run(['broker', 'status.all']);
        $json = json_decode(trim((string) ob_get_clean()), true);
        $this->assertSame(0, $code);
        $units = array_column($json['data']['controlled'] ?? [], 'unit');
        $this->assertContains('php83-php-fpm', $units);
        $this->assertContains('php-fpm', $units);
        $this->assertNotContains('php8.3-fpm', $units);
        $this->assertNotContains('php8.4-fpm', $units);
    }

    private function scriptLoad(FakeRuntime $rt, string $unit, string $state): void
    {
        $rt->script(
            ['/usr/bin/systemctl', 'show', $unit, '--property=LoadState', '--no-pager'],
            0,
            "LoadState={$state}\n"
        );
    }

    private function scriptShow(FakeRuntime $rt, string $unit, string $active, string $sub, int $pid): void
    {
        $rt->script(
            [
                '/usr/bin/systemctl',
                'show',
                $unit,
                '--property=Id,ActiveState,SubState,MainPID,NRestarts,ActiveEnterTimestamp,UnitFileState,Description',
                '--no-pager',
            ],
            0,
            "Id={$unit}.service\nActiveState={$active}\nSubState={$sub}\nMainPID={$pid}\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription={$unit}\n"
        );
    }
}
