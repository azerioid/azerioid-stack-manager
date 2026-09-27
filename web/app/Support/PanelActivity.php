<?php

namespace App\Support;

use App\Console\Commands\PanelMaintenance;
use App\Models\BackupJob;
use App\Models\ComponentOperation;
use App\Models\Operation;
use App\Models\PanelUpdateOperation;

/**
 * Whether the panel is in the middle of work that restarting its own runtime would kill.
 *
 * Scheduled jobs that restart the panel's FPM master or queue worker (identity
 * migration, php-fpm refresh) wait while this is true. Only rows touched recently count:
 * a row stranded at `running` by a dead worker must not hold them off forever.
 */
final class PanelActivity
{
    public static function busy(): bool
    {
        $since = now()->subMinutes(PanelMaintenance::STUCK_AFTER_MINUTES);

        return Operation::query()->whereIn('status', Operation::ACTIVE)->where('updated_at', '>=', $since)->exists()
            || ComponentOperation::query()->whereIn('status', ['queued', 'running'])->where('updated_at', '>=', $since)->exists()
            || PanelUpdateOperation::query()->whereIn('status', ['queued', 'running'])->where('updated_at', '>=', $since)->exists()
            || BackupJob::query()->where('status', 'running')->where('updated_at', '>=', $since)->exists();
    }
}
