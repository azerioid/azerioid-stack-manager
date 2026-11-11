<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A79 web UI: "Clone to…" on the vhost page queues a clone of a site (and
 * optionally its DBs) as an operation — it is far too slow for a web request.
 */
final class VhostCloneUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_clone_action_queues_an_operation_that_creates_the_site(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startClone', 'shop.example.com')
            ->assertSet('cloningDomain', 'shop.example.com')
            ->set('cloneTarget', 'staging.example.com')
            ->set('cloneDb', 'projob:projob_staging')
            ->call('saveClone')
            ->assertHasNoErrors()
            ->assertSet('cloningDomain', null)
            ->assertSee('queued')
            // The queue is synchronous in tests: the clone has already run.
            ->assertSee('staging.example.com');

        $op = Operation::query()->where('broker_action', 'vhost.clone')->sole();
        $this->assertSame(['shop.example.com', 'staging.example.com'], $op->args);
        $this->assertSame(Operation::STATUS_COMPLETED, $op->status);
        // The record names the cloned database but never carries its password.
        $this->assertStringContainsString('projob_staging', (string) $op->log);
        $this->assertStringNotContainsString('password', strtolower((string) $op->log));
    }

    public function test_clone_rejects_a_malformed_database_pair_before_queueing(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startClone', 'shop.example.com')
            ->set('cloneTarget', 'staging.example.com')
            ->set('cloneDb', 'projob')
            ->call('saveClone')
            ->assertHasErrors('cloneDb');
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_clone_rejects_a_bad_target_domain(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class);

        Livewire::test(\App\Livewire\VhostsPage::class)
            ->call('startClone', 'shop.example.com')
            ->set('cloneTarget', 'not a domain')
            ->call('saveClone')
            ->assertHasErrors('cloneTarget');
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_clone_refuses_an_unknown_or_client_forged_source(): void
    {
        $this->actingAs($this->admin());
        $this->app->make(FakeBroker::class);

        // A client sets cloningDomain directly to a site that isn't a cloneable vhost.
        Livewire::test(\App\Livewire\VhostsPage::class)
            ->set('cloningDomain', 'not-a-real-vhost.example.com')
            ->set('cloneTarget', 'staging.example.com')
            ->call('saveClone')
            ->assertSet('cloningDomain', null);
        $this->assertSame(0, Operation::query()->count());
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
