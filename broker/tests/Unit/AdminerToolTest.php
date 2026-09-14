<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Tool\AdminerTool;
use PHPUnit\Framework\TestCase;

final class AdminerToolTest extends TestCase
{
    private string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registryPath = dirname(__DIR__, 3) . '/registry/components';
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
        $rt->files['/var/lib/azerioid-panel/staging/package.lock'] = '';
        $rt->files['/run/php/azerioid-panel.sock'] = '';
        $rt->files['/usr/bin/curl'] = 'fake';
        $rt->files['/usr/bin/sha256sum'] = 'fake';
        $rt->files['/etc/caddy/Caddyfile'] = "{\n    admin 127.0.0.1:2019\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
        $rt->script(['/usr/bin/caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--address', '127.0.0.1:2019', '--force'], 0);
        $rt->script(['/usr/bin/systemctl', 'is-active', 'caddy'], 0, "active\n");
        $rt->script(['/usr/bin/ss', '-tln'], 0, "LISTEN 0 4096 127.0.0.1:3169 0.0.0.0:*\n");
        $rt->script(['/usr/bin/id', '-u', 'azerioid-adminer-tool'], 1);
        $rt->script(['/usr/sbin/useradd', '--system', '--home-dir', AdminerTool::TOOL_DIR, '--shell', '/usr/sbin/nologin', '--comment', 'AZERIOID Stack Manager Adminer tool', 'azerioid-adminer-tool'], 0);
        $rt->script(['/usr/sbin/usermod', '-a', '-G', 'www-data', 'azerioid-adminer-tool'], 0);
        $rt->script(['/usr/bin/chgrp', 'www-data', dirname(AdminerTool::TOOL_DIR)], 0);
        $rt->script(['/usr/bin/chmod', '0750', dirname(AdminerTool::TOOL_DIR)], 0);
        $rt->script(['/usr/bin/chmod', '0750', AdminerTool::TOOL_DIR], 0);
        $rt->script(['/usr/bin/chown', '-R', 'azerioid-adminer-tool:azerioid-adminer-tool', AdminerTool::TOOL_DIR], 0);
        $rt->script(['/usr/bin/chown', 'azerioid-adminer-tool:azerioid-adminer-tool', AdminerTool::TOOL_DIR], 0);
        $rt->script(['/usr/bin/chown', 'azerioid-adminer-tool:azerioid-adminer-tool', AdminerTool::ARTIFACT_PATH], 0);
        $rt->script(['/usr/bin/chmod', '0640', AdminerTool::ARTIFACT_PATH], 0);
        $rt->script(['/usr/bin/systemctl', 'reload', 'php8.4-fpm'], 0);
        $rt->script(['/usr/bin/systemctl', 'show', 'php8.4-fpm', '--property=LoadState', '--no-pager'], 0, "LoadState=loaded\n");

        $payload = '<?php // fake adminer';
        $staging = sys_get_temp_dir() . '/azerioid-adminer-test-' . getmypid() . '/adminer-6.0.2.download';
        $rt->files[$staging] = $payload;
        $registryHash = (string) json_decode((string) file_get_contents($this->registryPath . '/adminer.json'), true)['artifact']['sha256'];
        $rt->script(['/usr/bin/curl', '-fsSL', '--max-time', '120', '-o', $staging, 'https://github.com/vrana/adminer/releases/download/v6.0.2/adminer-6.0.2.php'], 0);
        $rt->script(['/usr/bin/sha256sum', $staging], 0, $registryHash . '  ' . $staging . "\n");

        return $rt;
    }

    private function kernel(FakeRuntime $rt): Kernel
    {
        $cfg = new Config();
        $cfg->registryComponentsPath = $this->registryPath;
        $cfg->stagingDir = sys_get_temp_dir() . '/azerioid-adminer-test-' . getmypid();
        $cfg->managedComponentsPath = $cfg->stagingDir . '/managed-components.json';
        $cfg->panelPort = 3169;
        $cfg->adminerCaddyRoutesPath = AdminerTool::CADDY_ROUTE_PATH;
        $cfg->terminalCaddyRoutesPath = '/var/lib/azerioid-panel/caddy-terminal-routes.conf';
        @mkdir($cfg->stagingDir . '/operations', 0750, true);
        $rt->dirs[$cfg->stagingDir] = true;
        $rt->dirs[$cfg->stagingDir . '/operations'] = true;

        return new Kernel($cfg, $rt);
    }

    public function test_install_writes_forward_auth_route_and_fpm_pool(): void
    {
        $rt = $this->ubuntuRuntime();
        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'component.install', 'adminer'], ['operation_id' => 'op-adminer-1']);
        $this->assertSame(0, $code, (string) json_encode($json));

        $routes = $rt->files[AdminerTool::CADDY_ROUTE_PATH] ?? '';
        $this->assertStringContainsString('forward_auth 127.0.0.1:3169', $routes);
        $this->assertStringContainsString('uri /internal/auth-check', $routes);
        $this->assertStringContainsString('header_up Host 127.0.0.1', $routes);
        $this->assertStringContainsString('redir /tools/adminer /tools/adminer/ 308', $routes);
        $this->assertStringContainsString('handle_path /tools/adminer/*', $routes);
        $this->assertStringContainsString('azerioid-adminer-tool.sock', $routes);
        $this->assertStringContainsString('open_basedir', $rt->files['/etc/php/8.4/fpm/pool.d/azerioid-adminer-tool.conf'] ?? '');
        $this->assertArrayHasKey(AdminerTool::ARTIFACT_PATH, $rt->files);
        $this->assertSame('<?php // fake adminer', $rt->files[AdminerTool::ARTIFACT_PATH]);
    }

    public function test_uninstall_clears_routes_and_artifact(): void
    {
        $rt = $this->ubuntuRuntime();
        $kernel = $this->kernel($rt);
        [$code] = $this->capture($kernel, ['broker', 'component.install', 'adminer'], ['operation_id' => 'op-adminer-2']);
        $this->assertSame(0, $code);

        [$code2] = $this->capture($kernel, ['broker', 'component.uninstall', 'adminer'], ['operation_id' => 'op-adminer-3']);
        $this->assertSame(0, $code2);
        $this->assertStringContainsString('Adminer not installed', $rt->files[AdminerTool::CADDY_ROUTE_PATH] ?? '');
        $this->assertArrayNotHasKey(AdminerTool::ARTIFACT_PATH, $rt->files);
        $this->assertArrayNotHasKey('/etc/php/8.4/fpm/pool.d/azerioid-adminer-tool.conf', $rt->files);
    }

    public function test_rejects_checksum_mismatch(): void
    {
        $rt = $this->ubuntuRuntime();
        $staging = sys_get_temp_dir() . '/azerioid-adminer-test-' . getmypid() . '/adminer-6.0.2.download';
        $rt->files[$staging] = 'tampered';
        $rt->script(['/usr/bin/sha256sum', $staging], 0, 'deadbeef  ' . $staging . "\n");

        [$code, $json] = $this->capture($this->kernel($rt), ['broker', 'component.install', 'adminer'], ['operation_id' => 'op-adminer-bad']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('checksum', strtolower((string) ($json['error'] ?? '')));
    }
}
