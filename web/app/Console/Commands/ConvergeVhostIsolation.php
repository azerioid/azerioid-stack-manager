<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

/**
 * Moves every vhost identity onto a group of its own after self-update (ADR A49).
 *
 * Same trigger as the A39 identity migration, for the same reason: the release that
 * ships the migration is deployed by the previous release's updater, which cannot call
 * it. The broker decides whether anything is due and runs it in a unit of its own,
 * because it restarts the web server this panel is served through. A failed attempt is
 * rolled back and never retried from here — an operator retries with
 * `azerioid vhost isolation apply --confirm`.
 */
class ConvergeVhostIsolation extends Command
{
    protected $signature = 'azerioid:isolation-converge';

    protected $description = 'Start the pending vhost isolation migration (A49)';

    public function handle(BrokerClient $broker): int
    {
        $response = $broker->call('vhost.isolation.converge', [], [], 120, false);
        if (! $response->ok) {
            $this->error('vhost.isolation.converge: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }

        $data = is_array($response->data) ? $response->data : [];
        $this->line(($data['started'] ?? false) === true
            ? 'Vhost isolation migration started ('.($data['unit'] ?? 'unit').').'
            : 'Nothing started: '.($data['reason'] ?? 'not due').'.');

        return self::SUCCESS;
    }
}
