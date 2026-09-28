<?php

namespace Tests\Feature;

use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A49: the scheduler starts the vhost isolation migration after self-update, and
 * `azerioid vhost isolation` is the operator's status check and retry.
 */
class VhostIsolationCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_starts_the_migration_while_pending(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:isolation-converge'));

        $this->assertSame(1, $fake->vhostIsolationConvergeStarts);
        $this->assertStringContainsString('started', Artisan::output());
    }

    public function test_scheduler_does_nothing_once_isolated(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->vhostsIsolated = true;

        Artisan::call('azerioid:isolation-converge');

        $this->assertSame(0, $fake->vhostIsolationConvergeStarts);
    }

    public function test_status_is_nonzero_until_isolated(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(1, Artisan::call('azerioid:vhost', ['action' => 'isolation', 'filesOp' => 'status']));
        $this->assertStringContainsString('az-vh-example-test', Artisan::output());

        $fake->vhostsIsolated = true;
        $this->assertSame(0, Artisan::call('azerioid:vhost', ['action' => 'isolation', 'filesOp' => 'status']));
    }

    public function test_apply_requires_confirm(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(2, Artisan::call('azerioid:vhost', ['action' => 'isolation', 'filesOp' => 'apply']));
        $this->assertFalse($fake->vhostsIsolated);

        $this->assertSame(0, Artisan::call('azerioid:vhost', ['action' => 'isolation', 'filesOp' => 'apply', '--confirm' => true]));
        $this->assertTrue($fake->vhostsIsolated);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:vhost', ['action' => 'isolation', 'filesOp' => 'apply', '--dry-run' => true]));

        $this->assertFalse($fake->vhostsIsolated);
        $this->assertStringContainsString('Dry run', Artisan::output());
    }
}
