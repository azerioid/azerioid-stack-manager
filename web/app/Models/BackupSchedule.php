<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One backup target on its own schedule, with its own retention (B6, ADR A52).
 */
class BackupSchedule extends Model
{
    public const TYPES = ['db', 'files', 'caddy', 'vhost'];

    protected $fillable = [
        'target_type', 'target', 'engine', 'destination', 'cadence', 'hour', 'weekday',
        'retention_days', 'enabled', 'last_run_at', 'last_status', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'hour' => 'integer',
            'weekday' => 'integer',
            'retention_days' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /** Due in this hour, and not already run today. */
    public function isDue(Carbon $now): bool
    {
        if (! $this->enabled || (int) $now->format('G') !== $this->hour) {
            return false;
        }
        if ($this->cadence === 'weekly' && $now->dayOfWeek !== (int) ($this->weekday ?? 0)) {
            return false;
        }

        return $this->last_run_at === null || ! $this->last_run_at->isSameDay($now);
    }

    /** The broker action and arguments that back this target up. */
    public function brokerCall(): array
    {
        return match ($this->target_type) {
            'db' => ['backup.db', [$this->target], array_filter(['engine' => $this->engine])],
            'files' => ['backup.files', [$this->target], []],
            'caddy' => ['backup.caddy', [], []],
            'vhost' => ['backup.vhost.run', [$this->target], []],
        };
    }
}
