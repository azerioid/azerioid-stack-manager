<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class ComponentInstallTest extends TestCase
{
    private string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registryPath = dirname(__DIR__, 3) . '/registry/components';
    }

    private function kernel(FakeRuntime $rt): Kernel
    {
        $cfg = new Config();
        $cfg->registryComponentsPath = $this->registryPath;
        $cfg->stagingDir = '/var/lib/azerioid-panel/staging';
        $cfg->managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';
        $rt->dirs['/var/lib/azerioid-panel/staging'] = true;
        $rt->dirs['/var/lib/azerioid-panel/staging/operations'] = true;
        return new Kernel($cfg, $rt);
    }

    /** @return array{0:int,1:array} */
    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();
        return [$code, json_decode(trim((string) $out), true)];
    }

    private function ubuntuRuntime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $rt->files['/proc/meminfo'] = "MemAvailable: 2097152 kB\n";
        $rt->dirs[rtrim($this->registryPath, '/')] = true;
        foreach (glob($this->registryPath . '/*.json') ?: [] as $path) {
            $rt->files[$path] = (string) file_get_contents($path);
        }
        $rt->script(['/bin/df', '-B1', '-P', '/var'], 0, "Filesystem 1B-blocks Used Available Capacity Mounted on\n/dev/sda1 10000000000 1000000000 9000000000 10% /var\n");
        return $rt;
    }

    public function test_rejects_non_installable_component(): void
    {
        [$code, $json] = $this->capture(
            $this->kernel($this->ubuntuRuntime()),
            ['broker', 'component.install', 'caddy'],
            ['operation_id' => 'op-1']
        );
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('install', strtolower((string) $json['error']));
    }

    public function test_rejects_unknown_component(): void
    {
        [$code] = $this->capture(
            $this->kernel($this->ubuntuRuntime()),
            ['broker', 'component.install', 'not-real'],
            ['operation_id' => 'op-1']
        );
        $this->assertNotSame(0, $code);
    }

    public function test_preflight_ok_for_redis(): void
    {
        [$code, $json] = $this->capture(
            $this->kernel($this->ubuntuRuntime()),
            ['broker', 'component.preflight', 'redis']
        );
        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['ok']);
    }

    public function test_apache_install_masks_unit_before_packages_and_binds_loopback(): void
    {
        $rt = $this->ubuntuRuntime();
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->dirs['/etc/caddy'] = true;
        $rt->dirs['/etc/caddy/conf.d'] = true;
        $rt->dirs['/etc/apache2'] = true;
        $rt->dirs['/etc/apache2/sites-available'] = true;
        $rt->dirs['/etc/apache2/sites-enabled'] = true;
        $rt->dirs['/etc/apache2/conf-available'] = true;
        $rt->dirs['/etc/apache2/conf-enabled'] = true;
        $rt->files['/etc/apache2/ports.conf'] = "Listen 80\n";
        $rt->files['/usr/sbin/apache2ctl'] = '';
        $rt->files['/usr/sbin/a2enmod'] = '';
        $rt->files['/usr/sbin/a2enconf'] = '';
        $rt->files['/usr/sbin/a2dissite'] = '';
        $rt->script(['/usr/sbin/apache2ctl', '-t'], 0, 'Syntax OK');
        $rt->script(['/usr/bin/systemctl', 'reload', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'restart', 'apache2'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'apache2'], 0, "active\n");
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');

        $cfg = new Config();
        $cfg->registryComponentsPath = $this->registryPath;
        $cfg->stagingDir = sys_get_temp_dir() . '/azerioid-apache-install-' . getmypid();
        $cfg->managedComponentsPath = $cfg->stagingDir . '/managed-components.json';
        @mkdir($cfg->stagingDir . '/operations', 0750, true);
        $rt->dirs[$cfg->stagingDir] = true;
        $rt->dirs[$cfg->stagingDir . '/operations'] = true;

        $kernel = new Kernel($cfg, $rt);
        [$code, $json] = $this->capture($kernel, ['broker', 'component.install', 'apache'], ['operation_id' => 'op-apache-1']);
        $this->assertSame(0, $code, (string) json_encode($json));

        $cmds = array_map(static fn (array $row): string => implode(' ', $row['command']), $rt->execLog);
        $maskAt = null;
        $aptAt = null;
        $unmaskAt = null;
        foreach ($cmds as $i => $cmd) {
            if ($maskAt === null && str_contains($cmd, 'systemctl mask apache2')) {
                $maskAt = $i;
            }
            if ($aptAt === null && str_contains($cmd, 'apt-get') && str_contains($cmd, 'install')) {
                $aptAt = $i;
            }
            if (str_contains($cmd, 'systemctl unmask apache2') && $unmaskAt === null) {
                $unmaskAt = $i;
            }
        }
        $this->assertNotNull($maskAt);
        $this->assertNotNull($aptAt);
        $this->assertNotNull($unmaskAt);
        $this->assertLessThan($aptAt, $maskAt, 'apache2 must be masked before apt so postinst cannot bind :80');
        $this->assertGreaterThan($aptAt, $unmaskAt);
        $this->assertStringContainsString('Listen 127.0.0.1:8081', $rt->files['/etc/apache2/ports.conf']);
        $this->assertStringNotContainsString("\nListen 80", "\n" . $rt->files['/etc/apache2/ports.conf']);
    }
}
