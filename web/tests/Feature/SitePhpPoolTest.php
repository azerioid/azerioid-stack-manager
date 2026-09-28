<?php

namespace Tests\Feature;

use App\Livewire\VhostsPage;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A55: the scheduler moves PHP sites onto pools of their own; `azerioid vhost php-pool` is the
 * status check, the operator retry and the open_basedir switch, which the vhost editor also has.
 */
class SitePhpPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_starts_the_migration_while_sites_are_pending(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:php-pools-converge'));
        $this->assertSame(1, $fake->phpPoolConvergeStarts);

        $fake->phpPoolsMigrated = true;
        Artisan::call('azerioid:php-pools-converge');
        $this->assertSame(1, $fake->phpPoolConvergeStarts);
    }

    public function test_status_is_nonzero_until_every_site_has_its_pool(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(1, Artisan::call('azerioid:vhost', ['action' => 'php-pool']));
        $this->assertStringContainsString('shop.example.com', Artisan::output());

        $fake->phpPoolsMigrated = true;
        $this->assertSame(0, Artisan::call('azerioid:vhost', ['action' => 'php-pool', 'filesOp' => 'status']));
    }

    public function test_apply_requires_confirm(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(2, Artisan::call('azerioid:vhost', ['action' => 'php-pool', 'filesOp' => 'apply']));
        $this->assertFalse($fake->phpPoolsMigrated);

        $this->assertSame(0, Artisan::call('azerioid:vhost', ['action' => 'php-pool', 'filesOp' => 'apply', '--confirm' => true]));
        $this->assertTrue($fake->phpPoolsMigrated);
        $this->assertSame('ISOLATE-PHP', $fake->stdinLog['vhost.phppool.apply']['confirm']);
    }

    public function test_open_basedir_switch_from_the_cli(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:vhost', [
            'action' => 'php-pool', 'filesOp' => 'set', '--domain' => 'shop.example.com', '--open-basedir' => 'off',
        ]));

        $this->assertFalse($fake->phpPoolOpenBasedir['shop.example.com']);
        $this->assertSame(2, Artisan::call('azerioid:vhost', ['action' => 'php-pool', 'filesOp' => 'set', '--domain' => 'shop.example.com']));
    }

    public function test_the_vhost_editor_switches_open_basedir_only_when_changed(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => Crypt::encryptString((new TotpService())->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]));
        $fake = $this->app->make(FakeBroker::class);

        $page = Livewire::test(VhostsPage::class)->call('startEdit', 'shop.example.com');
        $this->assertTrue($page->get('editOpenBasedir'));
        $page->call('saveEdit');
        $this->assertNotContains('vhost.phppool.set', $fake->callLog);

        $page->call('startEdit', 'shop.example.com')->set('editOpenBasedir', false)->call('saveEdit');
        $this->assertContains('vhost.phppool.set', $fake->callLog);
        $this->assertFalse($fake->phpPoolOpenBasedir['shop.example.com']);
    }
}
