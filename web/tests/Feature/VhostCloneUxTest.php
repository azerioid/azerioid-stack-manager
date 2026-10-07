<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A79 web UI: "Clone to…" on the vhost page clones a site (and optionally its DBs)
 * and shows the new one-time database credentials once.
 */
final class VhostCloneUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_clone_action_creates_the_site_and_shows_db_credentials_once(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startClone', 'shop.example.com')
            ->assertSet('cloningDomain', 'shop.example.com')
            ->set('cloneTarget', 'staging.example.com')
            ->set('cloneDb', 'projob:projob_staging')
            ->call('saveClone')
            ->assertSet('cloneResult.domain', 'staging.example.com')
            // The cloned DB and its one-time password are surfaced in the result.
            ->assertSeeHtml('projob_staging');
    }

    public function test_clone_rejects_a_bad_target_domain(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startClone', 'shop.example.com')
            ->set('cloneTarget', 'not a domain')
            ->call('saveClone')
            ->assertHasErrors('cloneTarget')
            ->assertSet('cloneResult', []);
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
