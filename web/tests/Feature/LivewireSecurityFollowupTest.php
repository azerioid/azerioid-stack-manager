<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class LivewireSecurityFollowupTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_cron_requires_typed_confirm(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(\App\Livewire\SecurityPage::class)
            ->set('crontab_text', "0 3 * * * /usr/bin/true\n")
            ->set('confirm', '')
            ->call('saveCron')
            ->assertSet('error', 'Type UPDATE-ROOT-CRON to confirm replacing the root crontab.');

        Livewire::test(\App\Livewire\SecurityPage::class)
            ->set('crontab_text', "0 3 * * * /usr/bin/true\n")
            ->set('confirm', 'UPDATE-ROOT-CRON')
            ->call('saveCron')
            ->assertSet('error', null)
            ->assertSet('flash', 'Root crontab updated.');
    }

    public function test_dashboard_restart_refuses_unit_not_in_fresh_broker_allowlist(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\Dashboard::class)
            ->set('status', [
                'controlled' => [
                    ['unit' => 'sshd', 'controllable' => true],
                ],
            ])
            ->call('restartService', 'sshd')
            ->assertSet('error', 'Service is not in the panel control allowlist.');
    }

    public function test_services_run_refuses_unit_outside_broker_allowlist(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(\App\Livewire\ServicesPage::class)
            ->set('pending', 'sshd')
            ->set('pendingAction', 'restart')
            ->call('run')
            ->assertSet('error', 'Service is not in the AZERIOID control allowlist.');
    }

    private function admin(): User
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
