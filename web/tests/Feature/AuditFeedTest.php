<?php

namespace Tests\Feature;

use App\Livewire\AuditLogPage;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A81: the audit feed filters by who / what / when / result.
 */
class AuditFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_narrow_the_feed_by_action_result_and_user(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();

        // Distinct tokens that do not appear anywhere in the view chrome (the Action
        // filter placeholder is "vhost.add", so avoid those).
        AuditLog::create(['user_id' => $admin->id, 'action' => 'alpha.create', 'ok' => true, 'code' => 0, 'ip' => '127.0.0.1']);
        AuditLog::create(['user_id' => $admin->id, 'action' => 'beta.wipe', 'ok' => false, 'code' => 3, 'error' => 'nope', 'ip' => '127.0.0.1']);
        AuditLog::create(['user_id' => $other->id, 'action' => 'gamma.remove', 'ok' => true, 'code' => 0, 'ip' => '10.0.0.9']);

        $this->actingAs($admin);

        // Action filter (substring).
        Livewire::test(AuditLogPage::class)
            ->set('action', 'alpha')
            ->assertSee('alpha.create')->assertDontSee('beta.wipe')->assertDontSee('gamma.remove');

        // Result filter.
        Livewire::test(AuditLogPage::class)
            ->set('result', 'failed')
            ->assertSee('beta.wipe')->assertDontSee('alpha.create')->assertDontSee('gamma.remove');

        // User filter.
        Livewire::test(AuditLogPage::class)
            ->set('user', (string) $other->id)
            ->assertSee('gamma.remove')->assertDontSee('alpha.create');

        // Clear restores everything.
        Livewire::test(AuditLogPage::class)
            ->set('action', 'nomatch')
            ->assertDontSee('alpha.create')
            ->call('clearFilters')
            ->assertSee('alpha.create')->assertSee('beta.wipe')->assertSee('gamma.remove');
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
