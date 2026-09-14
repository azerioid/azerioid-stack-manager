<?php

namespace Tests\Feature;

use App\Livewire\ProcessesPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProcessesPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    public function test_processes_page_lists_programs_when_supervisor_installed(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = true;
        $fake->supervisorPrograms['demo'] = [
            'command' => 'node app.js',
            'directory' => '/data/www/demo.example.com',
            'user' => 'azerioid-supervised',
            'autostart' => true,
            'autorestart' => true,
            'vhost_domain' => null,
            'state' => 'running',
        ];

        Livewire::test(ProcessesPage::class)
            ->assertSee('demo')
            ->assertSee('New freeform process');
    }

    public function test_fake_broker_rejects_root_user_on_create(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = true;
        $res = $fake->handle('supervisor.program.create', [], [
            'name' => 'evil',
            'command' => '/bin/true',
            'directory' => '/var/lib/azerioid-supervised/apps',
            'user' => 'root',
        ]);
        $this->assertFalse($res->ok);
        $this->assertStringContainsString('Refusing privileged', (string) $res->error);
    }

    public function test_create_rejects_readonly_vhost_domain_injection(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = true;

        Livewire::test(ProcessesPage::class)
            ->call('openVhostTied')
            ->set('name', 'evil-ro')
            ->set('command', 'node app.js')
            ->set('directory', '/data/www/projob.az')
            ->set('vhostDomain', 'projob.az')
            ->set('upstreamPort', '3000')
            ->call('create')
            ->assertSet('error', 'projob.az is not an editable panel vhost.');

        $this->assertArrayNotHasKey('evil-ro', $fake->supervisorPrograms);
    }
}
