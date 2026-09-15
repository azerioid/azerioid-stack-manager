<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\Pm2Manager;
use PHPUnit\Framework\TestCase;

final class Pm2ManagerTest extends TestCase
{
    private FakeRuntime $rt;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
    }

    public function test_validate_port_accepts_pm2_range(): void
    {
        $this->assertSame(36000, Pm2Manager::validatePort(36000));
        $this->assertSame(36999, Pm2Manager::validatePort('36999'));
    }

    public function test_validate_port_rejects_outside_range(): void
    {
        $this->expectException(BrokerException::class);
        Pm2Manager::validatePort(35999);
    }

    public function test_validate_port_rejects_high_port(): void
    {
        $this->expectException(BrokerException::class);
        Pm2Manager::validatePort(37000);
    }

    public function test_validate_instances_accepts_bounds_and_max(): void
    {
        $this->assertSame(1, Pm2Manager::validateInstances(1));
        $this->assertSame(32, Pm2Manager::validateInstances('32'));
        $this->assertSame(2, Pm2Manager::validateInstances('max'));
    }

    public function test_validate_instances_rejects_invalid(): void
    {
        $this->expectException(BrokerException::class);
        Pm2Manager::validateInstances(0);
    }

    public function test_program_name_prefixes_domain(): void
    {
        $name = Pm2Manager::programName('node-app.example.com');
        $this->assertStringStartsWith(Pm2Manager::PROGRAM_PREFIX, $name);
        $this->assertStringContainsString('node-app-example-com', $name);
    }

    public function test_detect_node_app_from_package_json_start(): void
    {
        $root = '/data/www/node.test';
        $this->rt->dirs[$root] = true;
        $this->rt->files[$root . '/package.json'] = json_encode([
            'scripts' => ['start' => 'node server.js'],
        ], JSON_THROW_ON_ERROR);
        $this->rt->files[$root . '/server.js'] = 'module.exports = {}';

        $detected = Pm2Manager::detectNodeApp($this->rt, $root);
        $this->assertTrue($detected['node']);
        $this->assertSame('npm:start', $detected['entry']);
    }

    public function test_detect_node_app_from_server_js(): void
    {
        $root = '/data/www/app.test';
        $this->rt->dirs[$root] = true;
        $this->rt->files[$root . '/server.js'] = 'require("http")';

        $detected = Pm2Manager::detectNodeApp($this->rt, $root);
        $this->assertTrue($detected['node']);
        $this->assertSame('server.js', $detected['entry']);
    }

    public function test_detect_node_app_refuses_empty_root(): void
    {
        $detected = Pm2Manager::detectNodeApp($this->rt, '');
        $this->assertFalse($detected['node']);
        $this->assertStringContainsString('no document root', strtolower($detected['detail']));
    }

    public function test_app_runtime_normalize_pm2_aliases(): void
    {
        $this->assertSame(AppRuntime::PM2, AppRuntime::normalize('pm2'));
        $this->assertSame(AppRuntime::PM2, AppRuntime::normalize('pm2-runtime'));
        $this->assertSame(AppRuntime::FPM, AppRuntime::normalize(''));
        $this->assertSame(AppRuntime::OCTANE, AppRuntime::normalize('octane'));
        $this->assertSame(AppRuntime::DOCKER, AppRuntime::normalize('docker'));
    }
}
