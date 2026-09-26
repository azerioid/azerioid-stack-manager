<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A long-running operation (B5 / request #13).
 */
class Operation extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    protected $fillable = [
        'user_id',
        'kind',
        'subject_type',
        'subject_id',
        'broker_action',
        'args',
        'options',
        'status',
        'step',
        'log',
        'log_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'args' => 'array',
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * Only a queued operation can be cancelled.
     *
     * Interrupting a running one would mean killing the broker's child mid-flight,
     * and for the operations that most need cancelling — package installs — that
     * leaves dpkg or rpm half-configured, which is worse than waiting for it.
     */
    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    /** Lock key: per subject, so two vhosts can work at once but one cannot twice. */
    public function lockKey(): string
    {
        return self::lockKeyFor($this->subject_type, $this->subject_id);
    }

    public static function lockKeyFor(string $subjectType, string $subjectId): string
    {
        return 'azerioid:op:' . $subjectType . ':' . $subjectId;
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }
        $end = $this->finished_at ?? now();

        return max(0, $end->diffInSeconds($this->started_at, true));
    }
}
