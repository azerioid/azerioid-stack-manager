<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

final class VhostDockerUxTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $totp = new TotpService();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function fakeWithDockerDeps(): FakeBroker
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = true;
        $fake->fakeInstalledComponents['docker'] = true;

        return $fake;
    }

    public function test_create_runtime_docker_enables_after_add(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithDockerDeps();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'docker-create.example.com')
            ->set('root', '/data/www/docker-create.example.com')
            ->set('type', 'static')
            ->set('createRuntime', 'docker')
            ->set('dockerMode', 'image')
            ->set('dockerImage', 'nginx:alpine')
            ->set('dockerInternalPort', '80')
            ->call('create')
            ->assertSet('error', null);

        $row = null;
        foreach ($fake->vhosts as $v) {
            if (($v['domain'] ?? '') === 'docker-create.example.com') {
                $row = $v;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame('docker', $row['runtime'] ?? null);
        $this->assertSame('nginx:alpine', $row['docker_image'] ?? null);
    }

    public function test_create_runtime_docker_surfaces_enable_failure(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithDockerDeps();

        $component = Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('domain', 'docker-gap.example.com')
            ->set('root', '/data/www/docker-gap.example.com')
            ->set('type', 'static')
            ->set('createRuntime', 'docker')
            ->set('dockerMode', 'image')
            ->set('dockerImage', 'missing/image:notfound')
            ->set('dockerInternalPort', '80')
            ->call('create');

        $this->assertNotNull($component->get('error'));
        $this->assertStringContainsString('Image not found', (string) $component->get('error'));
        $this->assertStringContainsString('vhost exists', strtolower((string) $component->get('error')));
        $this->assertContains('docker-gap.example.com', array_column($fake->vhosts, 'domain'));
    }

    public function test_container_shell_and_logs_routes_require_auth(): void
    {
        $this->get('/vhosts/node.example.com/container-shell')->assertRedirect();
        $this->get('/vhosts/node.example.com/container-logs')->assertRedirect();
    }

    public function test_container_shell_and_logs_routes_ok_for_docker_vhost(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithDockerDeps();
        $fake->handle('vhost.docker.enable', ['node.example.com'], [
            'mode' => 'image',
            'image' => 'nginx:alpine',
            'internal_port' => 80,
        ]);

        $this->get('/vhosts/node.example.com/container-shell')->assertOk();
        $this->get('/vhosts/node.example.com/container-logs')->assertOk();
        $html = $this->get('/vhosts')->assertOk()->getContent();
        $this->assertStringContainsString('/vhosts/node.example.com/container-shell', $html);
        $this->assertStringContainsString('/vhosts/node.example.com/container-logs', $html);
    }

    public function test_fake_broker_image_validate_and_search(): void
    {
        $fake = $this->fakeWithDockerDeps();
        $ok = $fake->handle('vhost.docker.image.validate', [], ['image' => 'nginx:alpine']);
        $this->assertTrue($ok->ok);
        $this->assertTrue($ok->data['exists'] ?? false);

        $missing = $fake->handle('vhost.docker.image.validate', [], ['image' => 'x:notfound']);
        $this->assertTrue($missing->ok);
        $this->assertFalse($missing->data['exists'] ?? true);

        $search = $fake->handle('vhost.docker.image.search', [], ['query' => 'nginx']);
        $this->assertTrue($search->ok);
        $this->assertNotEmpty($search->data['suggestions'] ?? []);
    }

    public function test_fake_broker_container_terminal_requires_docker_runtime(): void
    {
        $fake = $this->fakeWithDockerDeps();
        $denied = $fake->handle('terminal.session.start', ['shop.example.com'], [
            'mode' => 'container',
            'admin_user_id' => '1',
            'source_ip' => '127.0.0.1',
        ]);
        $this->assertFalse($denied->ok);

        $fake->handle('vhost.docker.enable', ['node.example.com'], [
            'mode' => 'image',
            'image' => 'nginx:alpine',
            'internal_port' => 80,
        ]);
        $ok = $fake->handle('terminal.session.start', ['node.example.com'], [
            'mode' => 'container',
            'admin_user_id' => '1',
            'source_ip' => '127.0.0.1',
        ]);
        $this->assertTrue($ok->ok, (string) $ok->error);
        $this->assertSame('container', $ok->data['kind'] ?? null);
        $this->assertSame('azerioid-supervised', $ok->data['username'] ?? null);
    }
}
