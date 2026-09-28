<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

/**
 * Moves every site-bound Supervisor program from azerioid-supervised to its site's own account
 * after self-update (ADR A56). Same trigger as A49/A55. A program that stops working as its site
 * is put back and never retried from here — an operator retries with
 * `azerioid process identity apply --confirm`.
 */
class ConvergeProgramIdentity extends Command
{
    protected $signature = 'azerioid:program-identity-converge';

    protected $description = 'Start the pending site-program identity migration (A56)';

    public function handle(BrokerClient $broker): int
    {
        $response = $broker->call('program.identity.converge', [], [], 120, false);
        if (! $response->ok) {
            $this->error('program.identity.converge: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }

        $data = is_array($response->data) ? $response->data : [];
        $this->line(($data['started'] ?? false) === true
            ? 'Program identity migration started ('.($data['unit'] ?? 'unit').').'
            : 'Nothing started: '.($data['reason'] ?? 'not due').'.');

        return self::SUCCESS;
    }
}
