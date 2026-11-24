<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

/**
 * Brings database grants made before A108 in line: MariaDB database-level grants move to
 * escaped names (an `_` in a grant is a wildcard), PostgreSQL site databases are closed to
 * PUBLIC. Idempotent; a no-op once everything is converged.
 */
class ConvergeDbGrants extends Command
{
    protected $signature = 'azerioid:db-grants-converge';

    protected $description = 'Confine database grants to their own database (A108)';

    public function handle(BrokerClient $broker): int
    {
        $response = $broker->call('db.grants.converge', [], [], 120, false);
        if (! $response->ok) {
            $this->error('db.grants.converge: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }

        $data = is_array($response->data) ? $response->data : [];
        $moved = count((array) ($data['mariadb'] ?? [])) + count((array) ($data['postgresql'] ?? []));
        $this->line($moved > 0 ? "Confined {$moved} grant(s)." : 'Nothing to change.');

        return self::SUCCESS;
    }
}
