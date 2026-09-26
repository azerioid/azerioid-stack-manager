<?php

namespace Tests\Feature;

use App\Console\Commands\PanelMaintenance;
use App\Jobs\RunComponentOperationJob;
use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\ComponentOperation;
use App\Models\PanelUpdateOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A1 — R5a (stuck operations wedged the Components page forever) and G13 (only
 * metric_samples was ever pruned).
 */
class PanelMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function operation(array $attrs = []): ComponentOperation
    {
        return ComponentOperation::query()->create(array_merge([
            'component_id' => 'redis',
            'action' => 'install',
            'status' => 'queued',
        ], $attrs));
    }

    // ------------------------------------------------------------------- reaper

    public function test_reaps_an_operation_whose_worker_died_mid_run(): void
    {
        $stuck = $this->operation([
            'status' => 'running',
            'started_at' => now()->subMinutes(PanelMaintenance::STUCK_AFTER_MINUTES + 5),
        ]);

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $stuck->refresh();
        $this->assertSame('failed', $stuck->status);
        $this->assertNotNull($stuck->finished_at);
        $this->assertStringContainsString('did not report a result', (string) $stuck->error);
    }

    /** The whole point: a wedged row must stop blocking new operations. */
    public function test_reaping_unblocks_the_components_page_gate(): void
    {
        $this->operation([
            'status' => 'running',
            'started_at' => now()->subHours(3),
        ]);
        $this->assertTrue(
            ComponentOperation::query()->whereIn('status', ['queued', 'running'])->exists(),
            'precondition: the gate is closed'
        );

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertFalse(
            ComponentOperation::query()->whereIn('status', ['queued', 'running'])->exists(),
            'the gate must be open again'
        );
    }

    public function test_leaves_a_recently_started_operation_alone(): void
    {
        $fresh = $this->operation([
            'status' => 'running',
            'started_at' => now()->subMinutes(2),
        ]);

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertSame('running', $fresh->refresh()->status, 'a slow but alive operation must survive');
    }

    /** A queued row is waiting legitimately; only `running` means a claim was made. */
    public function test_leaves_a_queued_operation_alone_however_old(): void
    {
        $queued = $this->operation(['status' => 'queued', 'created_at' => now()->subDays(1)]);

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertSame('queued', $queued->refresh()->status);
    }

    public function test_reaps_panel_update_operations_too(): void
    {
        $op = PanelUpdateOperation::query()->create([
            'status' => 'running',
            'started_at' => now()->subHour(),
        ]);

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertSame('failed', $op->refresh()->status);
    }

    // -------------------------------------------------------------------- prune

    public function test_prunes_audit_records_past_the_retention_window(): void
    {
        $old = AuditLog::query()->create(['action' => 'old.action', 'ok' => true, 'code' => 0]);
        $old->forceFill(['created_at' => now()->subDays(PanelMaintenance::AUDIT_RETENTION_DAYS + 1)])->save();
        $recent = AuditLog::query()->create(['action' => 'recent.action', 'ok' => true, 'code' => 0]);

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertNull(AuditLog::query()->find($old->id));
        $this->assertNotNull(AuditLog::query()->find($recent->id));
    }

    public function test_prunes_old_finished_operations_and_backup_jobs(): void
    {
        $oldOp = $this->operation(['status' => 'completed']);
        $oldOp->forceFill(['created_at' => now()->subDays(PanelMaintenance::OPERATION_RETENTION_DAYS + 1)])->save();

        $oldBackup = BackupJob::query()->create(['kind' => 'db', 'name' => 'all', 'status' => 'ok']);
        $oldBackup->forceFill(['created_at' => now()->subDays(PanelMaintenance::OPERATION_RETENTION_DAYS + 1)])->save();

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertNull(ComponentOperation::query()->find($oldOp->id));
        $this->assertNull(BackupJob::query()->find($oldBackup->id));
    }

    /** Pruning must never delete work that is still in flight, however old the row. */
    public function test_never_prunes_an_in_flight_operation(): void
    {
        $inFlight = $this->operation(['status' => 'queued']);
        $inFlight->forceFill(['created_at' => now()->subYears(2)])->save();

        $running = BackupJob::query()->create(['kind' => 'db', 'name' => 'all', 'status' => 'running']);
        $running->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertNotNull(ComponentOperation::query()->find($inFlight->id));
        $this->assertNotNull(BackupJob::query()->find($running->id));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $stuck = $this->operation([
            'status' => 'running',
            'started_at' => now()->subHours(2),
        ]);
        $old = AuditLog::query()->create(['action' => 'old.action', 'ok' => true, 'code' => 0]);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();

        $this->artisan('azerioid:maintenance', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('running', $stuck->refresh()->status);
        $this->assertNotNull(AuditLog::query()->find($old->id));
    }

    public function test_is_idempotent(): void
    {
        $this->operation(['status' => 'running', 'started_at' => now()->subHours(2)]);

        $this->artisan('azerioid:maintenance')->assertSuccessful();
        $this->artisan('azerioid:maintenance')->assertSuccessful();

        $this->assertSame(1, ComponentOperation::query()->where('status', 'failed')->count());
    }

    // ------------------------------------------------------- job contention fix

    /**
     * `tries = 1` plus release() destroyed the queued-behind operation: the worker
     * failed it as MaxAttemptsExceeded before handle() ran, so its row was never
     * updated and the UI showed "pending" forever.
     */
    public function test_job_is_bounded_by_deadline_not_attempt_count(): void
    {
        $job = new RunComponentOperationJob(1);

        $this->assertSame(0, $job->tries, 'attempt count must not cap a job that releases for a lock');
        $this->assertGreaterThan(now()->addMinutes(25), $job->retryUntil());
    }

    /** Whatever kills the job, the row must not be left in a gating state. */
    public function test_job_failure_marks_the_row_failed(): void
    {
        $op = $this->operation(['status' => 'running', 'started_at' => now()]);

        (new RunComponentOperationJob($op->id))->failed(new \RuntimeException('worker exploded'));

        $op->refresh();
        $this->assertSame('failed', $op->status);
        $this->assertStringContainsString('worker exploded', (string) $op->error);
    }

    public function test_job_failure_does_not_overwrite_a_finished_row(): void
    {
        $op = $this->operation(['status' => 'completed']);

        (new RunComponentOperationJob($op->id))->failed(new \RuntimeException('late failure callback'));

        $this->assertSame('completed', $op->refresh()->status);
    }
}
