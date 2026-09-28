<?php

namespace App\Livewire;

use App\Models\BackupSchedule;
use App\Models\Setting;
use Livewire\Component;

/**
 * Per-target backup schedules and age-based retention (B6, ADR A52).
 */
class BackupSchedules extends Component
{
    public array $schedules = [];

    public string $targetType = 'vhost';

    public string $target = '';

    public string $engine = '';

    public string $destination = 'local';

    public string $cadence = 'daily';

    public int $hour = 3;

    public int $weekday = 0;

    public string $retentionDays = '';

    public int $defaultRetentionDays = 30;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->defaultRetentionDays = (int) Setting::get('backup.retention_days', 30);
        $this->load();
    }

    private function load(): void
    {
        $this->schedules = BackupSchedule::query()->orderBy('target_type')->orderBy('target')->get()->toArray();
    }

    public function add(): void
    {
        $this->error = null;
        $this->validate([
            'targetType' => 'required|in:'.implode(',', BackupSchedule::TYPES),
            'target' => ['required_unless:targetType,caddy', 'nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9._-]+$/'],
            'engine' => 'nullable|in:,mariadb,postgresql,mongodb',
            'destination' => 'required|in:local,spaces',
            'cadence' => 'required|in:daily,weekly',
            'hour' => 'required|integer|min:0|max:23',
            'weekday' => 'required|integer|min:0|max:6',
            'retentionDays' => 'nullable|integer|min:1|max:3650',
        ]);
        $target = $this->targetType === 'caddy' ? 'caddy' : trim($this->target);
        if (BackupSchedule::query()->where(['target_type' => $this->targetType, 'target' => $target, 'destination' => $this->destination])->exists()) {
            $this->error = 'That target already has a schedule for this destination.';

            return;
        }
        BackupSchedule::query()->create([
            'target_type' => $this->targetType,
            'target' => $target,
            'engine' => $this->targetType === 'db' && $this->engine !== '' ? $this->engine : null,
            'destination' => $this->destination,
            'cadence' => $this->cadence,
            'hour' => $this->hour,
            'weekday' => $this->cadence === 'weekly' ? $this->weekday : null,
            'retention_days' => $this->retentionDays === '' ? null : (int) $this->retentionDays,
            'enabled' => true,
        ]);
        $this->flash = "Scheduled {$this->targetType} {$target}.";
        $this->target = '';
        $this->retentionDays = '';
        $this->load();
    }

    public function toggle(int $id): void
    {
        $s = BackupSchedule::query()->findOrFail($id);
        $s->forceFill(['enabled' => ! $s->enabled])->save();
        $this->load();
    }

    public function delete(int $id): void
    {
        BackupSchedule::query()->whereKey($id)->delete();
        $this->flash = 'Schedule removed. Archives it made are kept.';
        $this->load();
    }

    public function saveRetention(): void
    {
        $this->validate(['defaultRetentionDays' => 'required|integer|min:1|max:3650']);
        Setting::put('backup.retention_days', $this->defaultRetentionDays);
        $this->flash = "Default retention: {$this->defaultRetentionDays} days (the newest copy of each target is always kept).";
    }

    public function render()
    {
        return view('livewire.backup-schedules');
    }
}
