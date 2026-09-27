<?php

namespace Tests\Feature;

use App\Models\BackupJob;
use App\Models\PanelUpdateOperation;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A39 Part A: the scheduler is what starts the identity migration after a
 * self-update, so it has to hold off while work is in flight (the migration
 * restarts the queue worker) and must not be held off forever by a dead row.
 */
class ConvergePanelIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_starts_the_migration_when_idle(): void
    {
        $fake = $this->app->make(FakeBroker::class);

        $this->assertSame(0, Artisan::call('azerioid:identity-converge'));

        $this->assertSame(1, $fake->panelIdentityConvergeStarts);
        $this->assertStringContainsString('started', Artisan::output());
    }

    public function test_waits_while_a_self_update_is_running(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        PanelUpdateOperation::query()->create(['status' => 'running']);

        $this->assertSame(0, Artisan::call('azerioid:identity-converge'));

        $this->assertSame(0, $fake->panelIdentityConvergeStarts);
        $this->assertNotContains('panel.identity.converge', $fake->callLog);
    }

    public function test_a_stranded_backup_row_does_not_block_it_forever(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $row = BackupJob::query()->create(['kind' => 'db', 'name' => 'nightly', 'status' => 'running']);
        $row->forceFill(['updated_at' => now()->subHours(3)])->saveQuietly();

        Artisan::call('azerioid:identity-converge');

        $this->assertSame(1, $fake->panelIdentityConvergeStarts);
    }

    public function test_never_retries_a_failed_attempt(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $fake->panelIdentityLastResult = 'failed';

        Artisan::call('azerioid:identity-converge');

        $this->assertSame(0, $fake->panelIdentityConvergeStarts);
        $this->assertStringContainsString('Nothing started', Artisan::output());
    }
}
