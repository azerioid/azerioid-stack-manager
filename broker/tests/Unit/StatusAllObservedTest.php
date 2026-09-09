<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class StatusAllObservedTest extends TestCase
{
    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();
        $json = json_decode(trim($out), true);
        return [$code, $json];
    }

    private function showCmd(string $unit): array
    {
        return [
            '/usr/bin/systemctl',
            'show',
            $unit,
            '--property=Id,ActiveState,SubState,MainPID,NRestarts,ActiveEnterTimestamp,UnitFileState,Description',
            '--no-pager',
        ];
    }

    private function loadStateCmd(string $unit): array
    {
        return ['/usr/bin/systemctl', 'show', $unit, '--property=LoadState', '--no-pager'];
    }

    public function test_clean_runtime_has_empty_observed(): void
    {
        [$code, $json] = $this->capture(new Kernel(new Config(), new FakeRuntime()), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $this->assertSame([], $json['data']['observed']);
        foreach ($json['data']['controlled'] as $row) {
            $this->assertTrue($row['controllable']);
        }
    }

    public function test_redis_is_observed_only_when_loaded_and_active(): void
    {
        $rt = new FakeRuntime();
        $rt->script($this->loadStateCmd('redis-server'), 0, "LoadState=loaded\n");
        $rt->script($this->showCmd('redis-server'), 0, "Id=redis-server.service\nActiveState=active\nSubState=running\nMainPID=42\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription=Advanced key-value store\n");

        [$code, $json] = $this->capture(new Kernel(new Config(), $rt), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $this->assertCount(1, $json['data']['observed']);
        $this->assertSame('redis-server', $json['data']['observed'][0]['unit']);
        $this->assertSame('Advanced key-value store', $json['data']['observed'][0]['description']);
        $this->assertFalse($json['data']['observed'][0]['controllable']);
    }

    public function test_redis_alias_is_not_listed_twice(): void
    {
        $rt = new FakeRuntime();
        foreach (['redis-server', 'redis'] as $unit) {
            $rt->script($this->loadStateCmd($unit), 0, "LoadState=loaded\n");
            $rt->script($this->showCmd($unit), 0, "Id={$unit}.service\nActiveState=active\nSubState=running\nMainPID=1\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription=Advanced key-value store\n");
        }

        [$code, $json] = $this->capture(new Kernel(new Config(), $rt), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $this->assertCount(1, $json['data']['observed']);
        $this->assertSame('redis-server', $json['data']['observed'][0]['unit']);
    }

    public function test_loaded_but_inactive_redis_is_not_auto_observed(): void
    {
        $rt = new FakeRuntime();
        $rt->script($this->loadStateCmd('redis-server'), 0, "LoadState=loaded\n");
        $rt->script($this->showCmd('redis-server'), 0, "Id=redis-server.service\nActiveState=inactive\nSubState=dead\nMainPID=0\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=disabled\nDescription=Advanced key-value store\n");

        [$code, $json] = $this->capture(new Kernel(new Config(), $rt), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $this->assertSame([], $json['data']['observed']);
    }

    public function test_reverse_proxy_upstream_is_labeled_from_the_vhost(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/conf.d/app.example.conf'] = "app.example {\n    reverse_proxy 127.0.0.1:9001\n}\n";
        $rt->files['/etc/caddy/conf.d/default.conf'] = "http:// {\n    reverse_proxy 127.0.0.1:9002\n}\n";
        $rt->files['/etc/caddy/conf.d/azerioid-panel.conf'] = "127.0.0.1:3169 {\n    reverse_proxy 127.0.0.1:9003\n}\n";
        $rt->files['/proc/net/tcp'] = "  sl  local_address rem_address   st\n   0: 0100007F:2329 00000000:0000 0A\n";

        [$code, $json] = $this->capture(new Kernel(new Config(), $rt), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $this->assertCount(1, $json['data']['observed']);
        $row = $json['data']['observed'][0];
        $this->assertSame('127.0.0.1:9001', $row['bind_hint']);
        $this->assertSame('app.example reverse-proxy upstream 127.0.0.1:9001', $row['description']);
        $this->assertFalse($row['controllable']);
        $this->assertTrue($row['running']);
        $this->assertStringNotContainsString('RoadRunner', $row['description']);
    }

    public function test_silent_upstream_is_not_listed(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/caddy/conf.d/app.example.conf'] = "app.example {\n    reverse_proxy 127.0.0.1:9001\n}\n";
        $rt->files['/proc/net/tcp'] = "  sl  local_address rem_address   st\n";

        [$code, $json] = $this->capture(new Kernel(new Config(), $rt), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $this->assertSame([], $json['data']['observed']);
    }

    public function test_config_observed_unit_and_bind(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/azerioid-panel/broker.json'] = json_encode([
            'observed_services' => ['worker-unit', '127.0.0.1:6379', ''],
        ], JSON_THROW_ON_ERROR);
        $cfg = Config::load('/etc/azerioid-panel/broker.json', $rt);
        $this->assertSame(['worker-unit', '127.0.0.1:6379'], $cfg->observedServices);

        $rt->script($this->loadStateCmd('worker-unit'), 0, "LoadState=loaded\n");
        $rt->script($this->showCmd('worker-unit'), 0, "Id=worker-unit.service\nActiveState=inactive\nSubState=dead\nMainPID=0\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription=Operator worker\n");
        $rt->files['/proc/net/tcp'] = "  sl  local_address rem_address   st\n   0: 0100007F:18EB 00000000:0000 0A\n";

        [$code, $json] = $this->capture(new Kernel($cfg, $rt), ['broker', 'status.all']);
        $this->assertSame(0, $code);
        $units = array_column($json['data']['observed'], 'unit');
        $this->assertContains('worker-unit', $units);
        $this->assertContains('upstream-127.0.0.1-6379', $units);
        foreach ($json['data']['observed'] as $row) {
            $this->assertFalse($row['controllable']);
        }
    }

    public function test_service_control_rejects_observed_units(): void
    {
        $rt = new FakeRuntime();
        $rt->script($this->loadStateCmd('redis-server'), 0, "LoadState=loaded\n");
        $rt->script($this->showCmd('redis-server'), 0, "Id=redis-server.service\nActiveState=active\nSubState=running\nMainPID=1\nNRestarts=0\nActiveEnterTimestamp=\nUnitFileState=enabled\nDescription=Redis\n");
        $rt->script(['/usr/bin/systemctl', 'start', 'redis-server'], 0);

        [$code, $json] = $this->capture(new Kernel(new Config(), $rt), ['broker', 'service.start', 'redis-server']);
        $this->assertNotSame(0, $code);
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString('allowlist', $json['error']);
        foreach ($rt->execLog as $entry) {
            $this->assertNotSame(['/usr/bin/systemctl', 'start', 'redis-server'], $entry['command']);
        }
        $audit = $rt->files['/var/log/azerioid-panel/broker-audit.log'] ?? '';
        $this->assertStringContainsString('service.start', $audit);
        $this->assertStringContainsString('redis-server', $audit);
    }
}
