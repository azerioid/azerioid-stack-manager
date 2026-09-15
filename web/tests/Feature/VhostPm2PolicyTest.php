<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class VhostPm2PolicyTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWithPm2Deps(): FakeBroker
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = true;
        $fake->fakeInstalledComponents['nodejs'] = true;

        return $fake;
    }

    /** @return array<string, mixed> */
    private function vhost(FakeBroker $fake, string $domain): array
    {
        foreach ($fake->vhosts as $v) {
            if (($v['domain'] ?? '') === $domain) {
                return $v;
            }
        }

        $this->fail("Vhost {$domain} not found in fake broker.");
    }

    public function test_enable_switches_the_vhost_to_a_pm2_worker(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithPm2Deps();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askPm2', 'node.example.com')
            ->assertSet('pm2Target', 'node.example.com')
            ->set('pm2Instances', '2')
            ->set('pm2Entry', 'server.js')
            ->call('enablePm2')
            ->assertSet('error', null)
            ->assertSet('pm2Target', null);

        $vhost = $this->vhost($fake, 'node.example.com');
        $this->assertSame('pm2', $vhost['runtime']);
        $this->assertSame(2, $vhost['pm2_instances']);
        $this->assertArrayHasKey('pm2-node-example-com', $fake->supervisorPrograms);
        $this->assertSame('node.example.com', $fake->supervisorPrograms['pm2-node-example-com']['vhost_domain']);
    }

    public function test_enable_requires_supervisor_to_be_installed(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['nodejs'] = true;

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askPm2', 'node.example.com')
            ->call('enablePm2')
            ->assertSet('error', 'Supervisor is not installed. Install it from Components first.');
    }

    public function test_enable_is_refused_for_a_php_vhost(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithPm2Deps();

        $component = Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('pm2Target', 'shop.example.com')
            ->call('enablePm2');

        $this->assertStringContainsString(
            'PM2 is for Node apps',
            (string) $component->get('error')
        );
        $this->assertSame('fpm', $this->vhost($fake, 'shop.example.com')['runtime']);
    }

    public function test_enable_is_refused_for_a_non_node_vhost(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithPm2Deps();
        foreach ($fake->vhosts as $i => $v) {
            if (($v['domain'] ?? '') === 'node.example.com') {
                $fake->vhosts[$i]['node_app'] = false;
                $fake->vhosts[$i]['node_app_detail'] = 'No package.json or server.js found.';
            }
        }

        $component = Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('pm2Target', 'node.example.com')
            ->call('enablePm2');

        $this->assertStringContainsString(
            'does not look like a Node application',
            (string) $component->get('error')
        );
        $this->assertSame('fpm', $this->vhost($fake, 'node.example.com')['runtime']);
    }

    public function test_disable_returns_the_vhost_to_static_and_drops_the_program(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithPm2Deps();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askPm2', 'node.example.com')
            ->call('enablePm2')
            ->call('disablePm2', 'node.example.com')
            ->assertSet('error', null);

        $vhost = $this->vhost($fake, 'node.example.com');
        $this->assertSame('fpm', $vhost['runtime']);
        $this->assertSame('static', $vhost['type']);
        $this->assertArrayNotHasKey('pm2-node-example-com', $fake->supervisorPrograms);
    }

    public function test_reload_reports_the_method_used(): void
    {
        $this->actingAs($this->admin());
        $this->fakeWithPm2Deps();

        $component = Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askPm2', 'node.example.com')
            ->call('enablePm2')
            ->call('reloadPm2', 'node.example.com');

        $this->assertNull($component->get('error'));
        $this->assertStringContainsString('pm2-reload', (string) $component->get('flash'));
    }

    public function test_deleting_a_pm2_vhost_removes_its_worker(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithPm2Deps();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askPm2', 'node.example.com')
            ->call('enablePm2')
            ->call('askDelete', 'node.example.com')
            ->call('delete')
            ->assertSet('error', null);

        $this->assertNotContains('node.example.com', array_column($fake->vhosts, 'domain'));
        $this->assertArrayNotHasKey('pm2-node-example-com', $fake->supervisorPrograms);
    }

    private function admin(): User
    {
        $totp = new TotpService();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
