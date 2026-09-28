<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

/**
 * Moves every PHP site onto a PHP-FPM pool of its own after self-update (ADR A55).
 *
 * Same trigger as A49, for the same reason: the release that ships it is deployed by the
 * previous release's updater. The broker decides whether anything is due and runs it in a
 * unit of its own. A site that stops answering after its move is put back on the shared pool
 * and never retried from here — an operator retries with
 * `azerioid vhost php-pool apply --confirm`.
 */
class ConvergeSitePhpPools extends Command
{
    protected $signature = 'azerioid:php-pools-converge';

    protected $description = 'Start the pending per-site PHP pool migration (A55)';

    public function handle(BrokerClient $broker): int
    {
        $response = $broker->call('vhost.phppool.converge', [], [], 120, false);
        if (! $response->ok) {
            $this->error('vhost.phppool.converge: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }

        $data = is_array($response->data) ? $response->data : [];
        $this->line(($data['started'] ?? false) === true
            ? 'PHP pool migration started ('.($data['unit'] ?? 'unit').').'
            : 'Nothing started: '.($data['reason'] ?? 'not due').'.');

        return self::SUCCESS;
    }
}
