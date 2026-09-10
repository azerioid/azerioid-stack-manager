<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelUpdateOperation extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'log',
        'error',
        'from_commit',
        'to_commit',
        'rolled_back',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rolled_back' => 'boolean',
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
        return in_array($this->status, ['queued', 'running'], true);
    }
}
