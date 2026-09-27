<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Models\ComponentOperation;
use App\Models\Operation;
use App\Models\PanelUpdateOperation;
use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

/**
 * Moves the panel onto its dedicated account after self-update (ADR A39 Part A).
 *
 * This is how "the migration runs automatically during self-update" actually
 * happens. The release that first ships the migration is deployed by the
 * previous release's updater, which has no idea it exists, so the updater cannot
 * be the trigger. The scheduler can: it runs this release's code a minute after
 * the deploy lands.
 *
 * The broker decides whether anything is due and runs the migration in a unit
 * of its own; this command only picks a quiet moment. The migration restarts
 * the queue worker, so it waits while any operation is queued or running rather
 * than kill one halfway. A failed attempt is rolled back and is never retried
 * from here — an operator retries with `azerioid panel identity apply --confirm`.
 */
class ConvergePanelIdentity extends Command
{
    protected $signature = 'azerioid:identity-converge';

    protected $description = 'Start the pending panel identity migration (A39) when the panel is idle';

    public function handle(BrokerClient $broker): int
    {
        if ($this->busy()) {
            $this->line('Operations in progress; not now.');

            return self::SUCCESS;
        }

        $response = $broker->call('panel.identity.converge', [], [], 60, false);
        if (! $response->ok) {
            $this->error('panel.identity.converge: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }

        $data = is_array($response->data) ? $response->data : [];
        $this->line(($data['started'] ?? false) === true
            ? 'Panel identity migration started ('.($data['unit'] ?? 'unit').').'
            : 'Nothing started: '.($data['reason'] ?? 'not due').'.');

        return self::SUCCESS;
    }

    /**
     * Only rows touched recently count: a row stranded at `running` by a dead
     * worker (BackupJob has no reaper yet) must not hold the migration off forever.
     */
    private function busy(): bool
    {
        $since = now()->subMinutes(PanelMaintenance::STUCK_AFTER_MINUTES);

        return Operation::query()->whereIn('status', Operation::ACTIVE)->where('updated_at', '>=', $since)->exists()
            || ComponentOperation::query()->whereIn('status', ['queued', 'running'])->where('updated_at', '>=', $since)->exists()
            || PanelUpdateOperation::query()->whereIn('status', ['queued', 'running'])->where('updated_at', '>=', $since)->exists()
            || BackupJob::query()->where('status', 'running')->where('updated_at', '>=', $since)->exists();
    }
}
