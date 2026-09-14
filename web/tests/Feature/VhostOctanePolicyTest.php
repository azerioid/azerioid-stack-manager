<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class VhostOctanePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWithSupervisor(): FakeBroker
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = true;

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

    public function test_enable_switches_the_vhost_to_an_octane_worker(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithSupervisor();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askOctane', 'shop.example.com')
            ->assertSet('octaneTarget', 'shop.example.com')
            ->set('octaneMaxRequests', '250')
            ->call('enableOctane')
            ->assertSet('error', null)
            ->assertSet('octaneTarget', null);

        $vhost = $this->vhost($fake, 'shop.example.com');
        $this->assertSame('octane', $vhost['runtime']);
        $this->assertSame(250, $vhost['octane_max_requests']);
        $this->assertArrayHasKey('octane-shop-example-com', $fake->supervisorPrograms);
        $this->assertSame('shop.example.com', $fake->supervisorPrograms['octane-shop-example-com']['vhost_domain']);
    }

    public function test_enable_requires_supervisor_to_be_installed(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askOctane', 'shop.example.com')
            ->call('enableOctane')
            ->assertSet('error', 'Supervisor is not installed. Install it from Components first.');
    }

    public function test_enable_is_refused_for_a_non_laravel_php_vhost(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithSupervisor();
        foreach ($fake->vhosts as $i => $v) {
            if (($v['domain'] ?? '') === 'shop.example.com') {
                $fake->vhosts[$i]['laravel_app'] = false;
                $fake->vhosts[$i]['laravel_app_detail'] = 'No artisan entrypoint found.';
            }
        }

        $component = Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('octaneTarget', 'shop.example.com')
            ->call('enableOctane');

        $this->assertStringContainsString(
            'does not look like a Laravel application',
            (string) $component->get('error')
        );
        $this->assertSame('fpm', $this->vhost($fake, 'shop.example.com')['runtime']);
    }

    public function test_enable_is_refused_for_readonly_vhosts(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithSupervisor();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('octaneTarget', 'projob.az')
            ->call('enableOctane')
            ->assertSet('error', "projob.az is managed externally and can't be edited.");

        $this->assertSame('fpm', $this->vhost($fake, 'projob.az')['runtime']);
    }

    public function test_disable_returns_the_vhost_to_php_fpm_and_drops_the_program(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithSupervisor();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askOctane', 'shop.example.com')
            ->call('enableOctane')
            ->call('disableOctane', 'shop.example.com')
            ->assertSet('error', null);

        $this->assertSame('fpm', $this->vhost($fake, 'shop.example.com')['runtime']);
        $this->assertArrayNotHasKey('octane-shop-example-com', $fake->supervisorPrograms);
    }

    public function test_reload_reports_the_method_used(): void
    {
        $this->actingAs($this->admin());
        $this->fakeWithSupervisor();

        $component = Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askOctane', 'shop.example.com')
            ->call('enableOctane')
            ->call('reloadOctane', 'shop.example.com');

        $this->assertNull($component->get('error'));
        $this->assertStringContainsString('octane:reload', (string) $component->get('flash'));
    }

    public function test_deleting_an_octane_vhost_removes_its_worker(): void
    {
        $this->actingAs($this->admin());
        $fake = $this->fakeWithSupervisor();

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('askOctane', 'shop.example.com')
            ->call('enableOctane')
            ->call('askDelete', 'shop.example.com')
            ->call('delete')
            ->assertSet('error', null);

        $this->assertNotContains('shop.example.com', array_column($fake->vhosts, 'domain'));
        $this->assertArrayNotHasKey('octane-shop-example-com', $fake->supervisorPrograms);
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
