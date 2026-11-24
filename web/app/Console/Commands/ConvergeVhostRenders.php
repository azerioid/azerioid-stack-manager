<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

/**
 * Brings vhosts written by older renderers in line with the current ones (A115, A116): repository
 * and Composer state hidden in every engine, and no handler overrides in Apache .htaccess.
 * Idempotent; a no-op once converged.
 */
class ConvergeVhostRenders extends Command
{
    protected $signature = 'azerioid:vhost-render-converge';

    protected $description = 'Apply the current vhost hardening to existing vhosts (A115, A116)';

    public function handle(BrokerClient $broker): int
    {
        $response = $broker->call('vhost.render.converge', [], [], 120, false);
        if (! $response->ok) {
            $this->error('vhost.render.converge: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }

        $data = is_array($response->data) ? $response->data : [];
        $changed = count((array) ($data['backends'] ?? [])) + count((array) ($data['caddy'] ?? [])) + count((array) ($data['docker_env'] ?? []));
        $this->line($changed > 0 ? "Updated {$changed} vhost(s)." : 'Nothing to change.');

        return self::SUCCESS;
    }
}
