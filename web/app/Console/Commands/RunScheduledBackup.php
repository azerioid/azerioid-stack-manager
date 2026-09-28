<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Models\BackupSchedule;
use App\Models\Setting;
use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

class RunScheduledBackup extends Command
{
    protected $signature = 'azerioid:backup-scheduled';

    protected $description = 'Run the configured backup job if due';

    public function handle(BrokerClient $broker): int
    {
        $legacy = $this->runGlobalSchedule($broker);
        $targets = $this->runTargetSchedules($broker);

        return $legacy === self::SUCCESS && $targets === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Per-target schedules (B6, ADR A52): each due target is backed up to its destination,
     * then its own retention applies — the schedule's days, or the global default.
     */
    private function runTargetSchedules(BrokerClient $broker): int
    {
        $due = BackupSchedule::query()->where('enabled', true)->get()->filter(fn (BackupSchedule $s) => $s->isDue(now()));
        if ($due->isEmpty()) {
            return self::SUCCESS;
        }
        $pass = Setting::getSecret('backup.passphrase');
        if ($pass === null) {
            $this->error('backup passphrase is not configured');

            return self::FAILURE;
        }
        $defaultDays = (int) Setting::get('backup.retention_days', 30);
        $status = self::SUCCESS;
        foreach ($due as $schedule) {
            $stdin = ['passphrase' => $pass, 'destination' => $schedule->destination];
            if ($schedule->destination === 'spaces') {
                $spaces = self::spacesStdin();
                if ($spaces === null) {
                    $schedule->forceFill(['last_run_at' => now(), 'last_status' => 'failed', 'last_error' => 'Spaces credentials are incomplete.'])->save();
                    $status = self::FAILURE;

                    continue;
                }
                $stdin['spaces'] = $spaces;
            }
            [$action, $args, $extra] = $schedule->brokerCall();
            $job = $this->runOne($broker, $action, $args, $stdin + $extra, $schedule->target_type, $schedule->target, $schedule->id);
            $schedule->forceFill([
                'last_run_at' => now(),
                'last_status' => $job->status,
                'last_error' => $job->error,
            ])->save();
            if ($job->status !== 'ok') {
                $status = self::FAILURE;

                continue;
            }
            $prune = $broker->call('backup.prune.age', [], $stdin + [
                'kind' => $schedule->target_type,
                'name' => $schedule->target_type === 'caddy' ? null : $schedule->target,
                'days' => $schedule->retention_days ?? $defaultDays,
                'min_keep' => 1,
            ], 300, true);
            if (! $prune->ok) {
                $this->warn("retention for {$schedule->target_type}/{$schedule->target}: ".$prune->error);
            }
        }

        return $status;
    }

    private function runGlobalSchedule(BrokerClient $broker): int
    {
        $cfg = Setting::get('backup.schedule', []);
        if (! is_array($cfg) || ! ($cfg['enabled'] ?? false)) {
            return self::SUCCESS;
        }
        $hour = (int) ($cfg['hour'] ?? 3);
        if ((int) now()->format('G') !== $hour) {
            return self::SUCCESS;
        }
        $cadence = (string) ($cfg['cadence'] ?? 'daily');
        if ($cadence === 'weekly' && now()->dayOfWeek !== (int) ($cfg['weekday'] ?? 0)) {
            return self::SUCCESS;
        }
        $last = BackupJob::query()->whereNull('schedule_id')->where('status', 'ok')->latest()->first();
        if ($last && $last->created_at->isToday()) {
            return self::SUCCESS;
        }
        $pass = Setting::getSecret('backup.passphrase');
        if ($pass === null) {
            $this->error('backup passphrase is not configured');

            return self::FAILURE;
        }

        // Scheduled backups used to require Spaces and bail out without it, so a
        // local-only schedule was impossible even though manual local backups
        // worked. The destination now follows the saved schedule (A2.5).
        $destination = strtolower((string) ($cfg['destination'] ?? 'spaces'));
        if (! in_array($destination, ['spaces', 'local'], true)) {
            $this->error("unknown backup destination: {$destination}");

            return self::FAILURE;
        }

        $stdin = ['passphrase' => $pass, 'destination' => $destination];
        if ($destination === 'spaces') {
            $spaces = self::spacesStdin();
            if ($spaces === null) {
                $this->error('Spaces credentials are incomplete; configure them or switch the schedule to local.');

                return self::FAILURE;
            }
            $stdin['spaces'] = $spaces;
        }
        if (isset($cfg['kdf']) && $cfg['kdf'] !== '') {
            $stdin['kdf'] = (string) $cfg['kdf'];
        }
        if (($cfg['include_caddy'] ?? true) !== false) {
            $this->runOne($broker, 'backup.caddy', [], $stdin, 'caddy', 'caddy');
        }
        $targets = $cfg['databases'] ?? ['all'];
        foreach ($targets as $db) {
            $this->runOne($broker, 'backup.db', [(string) $db], $stdin, 'db', (string) $db);
        }
        $sites = $cfg['sites'] ?? [];
        foreach ($sites as $site) {
            $this->runOne($broker, 'backup.files', [(string) $site], $stdin, 'files', (string) $site);
        }
        $keep = (int) ($cfg['keep'] ?? 14);
        $broker->call('backup.prune', [], $stdin + ['keep' => $keep], 120, true);
        return self::SUCCESS;
    }

    /** @param array<int,string> $args */
    private function runOne(BrokerClient $broker, string $action, array $args, array $stdin, string $kind, string $name, ?int $scheduleId = null): BackupJob
    {
        $job = BackupJob::query()->create(['schedule_id' => $scheduleId, 'kind' => $kind, 'name' => $name, 'status' => 'running']);
        $start = microtime(true);
        // A bundle holds a whole site and its databases; one part alone can take minutes.
        $res = $broker->call($action, $args, $stdin, $action === 'backup.vhost.run' ? 3600 : 900, true);
        $job->forceFill([
            'status' => $res->ok ? 'ok' : 'failed',
            'object_key' => $res->ok ? ($res->data['key'] ?? $res->data['bundle'] ?? null) : null,
            'size' => $res->ok ? ($res->data['size'] ?? null) : null,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            'error' => $res->ok ? null : $res->error,
        ])->save();

        return $job;
    }

    /** @return array<string,string>|null */
    public static function spacesStdin(): ?array
    {
        $key = Setting::getSecret('spaces.access_key');
        $secret = Setting::getSecret('spaces.secret');
        $endpoint = (string) Setting::get('spaces.endpoint', '');
        $region = (string) Setting::get('spaces.region', '');
        $bucket = (string) Setting::get('spaces.bucket', '');
        if ($key === null || $secret === null || $endpoint === '' || $region === '' || $bucket === '') {
            return null;
        }
        return [
            'endpoint' => $endpoint,
            'region' => $region,
            'bucket' => $bucket,
            'access_key' => $key,
            'secret' => $secret,
        ];
    }
}
