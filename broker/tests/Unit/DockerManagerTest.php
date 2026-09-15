<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\DockerManager;
use PHPUnit\Framework\TestCase;

final class DockerManagerTest extends TestCase
{
    private FakeRuntime $rt;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
    }

    public function test_validate_port_accepts_docker_range(): void
    {
        $this->assertSame(37000, DockerManager::validatePort(37000));
        $this->assertSame(37999, DockerManager::validatePort('37999'));
    }

    public function test_validate_port_rejects_outside_range(): void
    {
        $this->expectException(BrokerException::class);
        DockerManager::validatePort(36999);
    }

    public function test_validate_port_rejects_high_port(): void
    {
        $this->expectException(BrokerException::class);
        DockerManager::validatePort(38000);
    }

    public function test_validate_internal_port_bounds(): void
    {
        $this->assertSame(80, DockerManager::validateInternalPort(80));
        $this->assertSame(65535, DockerManager::validateInternalPort('65535'));
    }

    public function test_validate_internal_port_rejects_zero(): void
    {
        $this->expectException(BrokerException::class);
        DockerManager::validateInternalPort(0);
    }

    public function test_validate_mode(): void
    {
        $this->assertSame('image', DockerManager::validateMode('image'));
        $this->assertSame('compose', DockerManager::validateMode('COMPOSE'));
        $this->assertSame('dockerfile', DockerManager::validateMode('dockerfile'));
    }

    public function test_validate_mode_rejects_unknown(): void
    {
        $this->expectException(BrokerException::class);
        DockerManager::validateMode('swarm');
    }

    public function test_program_name_prefixes_domain(): void
    {
        $name = DockerManager::programName('app.example.com');
        $this->assertStringStartsWith(DockerManager::PROGRAM_PREFIX, $name);
        $this->assertStringContainsString('app-example-com', $name);
    }

    public function test_detect_docker_app_from_dockerfile(): void
    {
        $root = '/data/www/container.test';
        $this->rt->dirs[$root] = true;
        $this->rt->files[$root . '/Dockerfile'] = "FROM nginx:alpine\n";

        $detected = DockerManager::detectDockerApp($this->rt, $root);
        $this->assertTrue($detected['docker']);
        $this->assertSame('Dockerfile', $detected['dockerfile']);
    }

    public function test_detect_docker_app_from_compose(): void
    {
        $root = '/data/www/compose.test';
        $this->rt->dirs[$root] = true;
        $this->rt->files[$root . '/docker-compose.yml'] = "services:\n  web:\n    image: nginx\n";

        $detected = DockerManager::detectDockerApp($this->rt, $root);
        $this->assertTrue($detected['docker']);
        $this->assertSame('docker-compose.yml', $detected['compose']);
    }

    public function test_detect_docker_app_refuses_empty_root(): void
    {
        $detected = DockerManager::detectDockerApp($this->rt, '');
        $this->assertFalse($detected['docker']);
        $this->assertStringContainsString('no document root', strtolower($detected['detail']));
    }

    public function test_first_compose_service(): void
    {
        $yaml = "version: '3'\nservices:\n  api:\n    image: app\n  db:\n    image: postgres\n";
        $this->assertSame('api', DockerManager::firstComposeService($yaml));
    }

    public function test_app_runtime_normalize_docker_aliases(): void
    {
        $this->assertSame(AppRuntime::DOCKER, AppRuntime::normalize('docker'));
        $this->assertSame(AppRuntime::DOCKER, AppRuntime::normalize('rootless-docker'));
        $this->assertSame(AppRuntime::DOCKER, AppRuntime::normalize('container'));
        $this->assertSame(AppRuntime::FPM, AppRuntime::normalize(''));
        $this->assertSame(AppRuntime::PM2, AppRuntime::normalize('pm2'));
    }

    public function test_app_runtime_rejects_unknown(): void
    {
        $this->expectException(BrokerException::class);
        AppRuntime::normalize('kubernetes');
    }
}
