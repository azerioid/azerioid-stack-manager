<?php

namespace Tests\Feature;

use App\Services\Broker\FakeBroker;
use Tests\TestCase;

final class VhostDockerPolicyTest extends TestCase
{
    private function fakeWithDockerDeps(): FakeBroker
    {
        $fake = new FakeBroker();
        $fake->fakeInstalledComponents['supervisor'] = true;
        $fake->fakeInstalledComponents['docker'] = true;

        return $fake;
    }

    public function test_fake_broker_docker_enable_disable_cycle(): void
    {
        $fake = $this->fakeWithDockerDeps();

        $res = $fake->handle('vhost.docker.enable', ['node.example.com'], [
            'mode' => 'image',
            'image' => 'nginx:alpine',
            'internal_port' => 80,
        ]);
        $this->assertTrue($res->ok, (string) $res->error);
        $this->assertIsArray($res->data);
        $this->assertSame('docker', $res->data['runtime'] ?? null);
        $this->assertGreaterThanOrEqual(37000, (int) ($res->data['docker_port'] ?? 0));
        $this->assertLessThanOrEqual(37999, (int) ($res->data['docker_port'] ?? 0));

        $status = $fake->handle('vhost.docker.status', ['node.example.com'], []);
        $this->assertTrue($status->ok);
        $this->assertSame('docker', $status->data['runtime'] ?? null);

        $disable = $fake->handle('vhost.docker.disable', ['node.example.com'], []);
        $this->assertTrue($disable->ok, (string) $disable->error);
        $this->assertSame('fpm', $disable->data['runtime'] ?? null);
    }

    public function test_fake_broker_refuses_docker_uninstall_while_runtime_active(): void
    {
        $fake = $this->fakeWithDockerDeps();
        $enable = $fake->handle('vhost.docker.enable', ['node.example.com'], [
            'mode' => 'image',
            'image' => 'nginx:alpine',
            'internal_port' => 80,
        ]);
        $this->assertTrue($enable->ok, (string) $enable->error);

        $uninstall = $fake->handle('component.uninstall', ['docker'], ['operation_id' => 'op-1']);
        $this->assertFalse($uninstall->ok);
        $this->assertStringContainsString('runtime=docker', (string) $uninstall->error);
        $this->assertSame(3, $uninstall->code);
    }

    public function test_fake_broker_docker_logs_and_build(): void
    {
        $fake = $this->fakeWithDockerDeps();
        $fake->handle('vhost.docker.enable', ['node.example.com'], [
            'mode' => 'image',
            'image' => 'nginx:alpine',
            'internal_port' => 80,
        ]);

        $build = $fake->handle('vhost.docker.build', ['node.example.com'], []);
        $this->assertTrue($build->ok, (string) $build->error);

        $logs = $fake->handle('vhost.docker.logs', ['node.example.com'], ['lines' => 20]);
        $this->assertTrue($logs->ok, (string) $logs->error);
        $this->assertSame(20, $logs->data['lines'] ?? null);
    }
}
