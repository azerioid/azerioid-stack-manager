<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\BackupJob;
use App\Models\ComponentOperation;
use App\Models\PanelUpdateOperation;
use Illuminate\Console\Command;

/**
 * Housekeeping the panel had no owner for (A1 / R5a + G13).
 *
 * Two jobs, both of which caused real problems:
 *
 *  1. **Reap stuck operations.** A worker killed mid-operation — a deploy, an OOM
 *     on a small host — left its row at `running` forever. Because both the job
 *     and the Components page gate on queued/running rows, that permanently wedged
 *     component installs until someone edited SQLite by hand. Rows past the
 *     timeout are now marked failed with a clear reason.
 *
 *  2. **Prune history.** Only metric_samples was ever pruned. audit_logs and the
 *     three operation tables grew without bound in a SQLite file.
 *
 * Both are idempotent and safe to run on a schedule.
 */
class PanelMaintenance extends Command
{
    protected $signature = 'azerioid:maintenance
        {--dry-run : Report what would change without touching anything}';

    protected $description = 'Reap stuck operations and prune historical records';

    /**
     * Twice the 900s job timeout, so a slow-but-alive operation is never reaped.
     * The longest legitimate operations are a docker build or a package install.
     */
    public const STUCK_AFTER_MINUTES = 30;

    /** Audit records are what an incident investigation reads (A29 keeps them past uninstall). */
    public const AUDIT_RETENTION_DAYS = 90;

    /** Operation history is small and useful for troubleshooting. */
    public const OPERATION_RETENTION_DAYS = 180;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stuckBefore = now()->subMinutes(self::STUCK_AFTER_MINUTES);

        $reaped = [
            'component_operations' => $this->reap(ComponentOperation::query(), $stuckBefore, $dryRun),
            'panel_update_operations' => $this->reap(PanelUpdateOperation::query(), $stuckBefore, $dryRun),
        ];

        $pruned = [
            'audit_logs' => $this->prune(
                AuditLog::query(),
                'created_at',
                now()->subDays(self::AUDIT_RETENTION_DAYS),
                $dryRun
            ),
            'component_operations' => $this->prune(
                ComponentOperation::query()->whereNotIn('status', ['queued', 'running']),
                'created_at',
                now()->subDays(self::OPERATION_RETENTION_DAYS),
                $dryRun
            ),
            'panel_update_operations' => $this->prune(
                PanelUpdateOperation::query()->whereNotIn('status', ['queued', 'running']),
                'created_at',
                now()->subDays(self::OPERATION_RETENTION_DAYS),
                $dryRun
            ),
            'backup_jobs' => $this->prune(
                BackupJob::query()->where('status', '!=', 'running'),
                'created_at',
                now()->subDays(self::OPERATION_RETENTION_DAYS),
                $dryRun
            ),
        ];

        $swept = $this->sweepZipHandovers($dryRun);
        if ($swept > 0) {
            $this->line(($dryRun ? '[dry-run] ' : '') . "removed {$swept} File Manager zip(s) left behind by an abandoned download");
        }

                foreach ($reaped as $table => $count) {
            if ($count > 0) {
                $this->warn(($dryRun ? '[dry-run] ' : '') . "reaped {$count} stuck row(s) in {$table}");
            }
        }
        foreach ($pruned as $table => $count) {
            if ($count > 0) {
                $this->line(($dryRun ? '[dry-run] ' : '') . "pruned {$count} row(s) from {$table}");
            }
        }
        if (array_sum($reaped) === 0 && array_sum($pruned) === 0 && $swept === 0) {
            $this->line('nothing to do');
        }

        return self::SUCCESS;
    }

    /**
     * File Manager zips the broker handed over (VhostFilesAction::HANDOVER_PREFIX) that a
     * download never finished streaming. Each is a copy of a site; an hour is far longer than
     * any real download.
     */
    private function sweepZipHandovers(bool $dryRun): int
    {
        $cutoff = time() - 3600;
        $count = 0;
        foreach (glob(storage_path('framework/tmp/'.\AzerioidPanel\Broker\Actions\VhostFilesAction::HANDOVER_PREFIX.'*.zip')) ?: [] as $file) {
            if (is_file($file) && ! is_link($file) && filemtime($file) < $cutoff) {
                $count++;
                if (! $dryRun) {
                    @unlink($file);
                }
            }
        }

        return $count;
    }

    /**
     * Mark operations that claimed `running` but never reported a result.
     *
     * Only rows with a started_at older than the cutoff are touched; a row still
     * sitting at `queued` is waiting legitimately and must be left alone.
     */
    private function reap(\Illuminate\Database\Eloquent\Builder $query, \DateTimeInterface $before, bool $dryRun): int
    {
        $stuck = (clone $query)
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->where('started_at', '<', $before);

        if ($dryRun) {
            return $stuck->count();
        }

        return $stuck->update([
            'status' => 'failed',
            'error' => 'Operation did not report a result within '
                . self::STUCK_AFTER_MINUTES . ' minutes and was marked failed by maintenance. '
                . 'The worker was most likely restarted or killed while it was running.',
            'finished_at' => now(),
        ]);
    }

    private function prune(
        \Illuminate\Database\Eloquent\Builder $query,
        string $column,
        \DateTimeInterface $before,
        bool $dryRun,
    ): int {
        $old = $query->where($column, '<', $before);

        return $dryRun ? $old->count() : $old->delete();
    }
}
