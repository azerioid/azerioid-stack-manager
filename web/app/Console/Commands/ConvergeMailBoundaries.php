<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use Illuminate\Console\Command;

/**
 * Brings a mail stack provisioned before A123–A125 in line: submission checks the sender
 * against the login, the signer sees authenticated submissions only, over a unix socket.
 * Idempotent; a no-op once converged or when mail is not installed.
 */
class ConvergeMailBoundaries extends Command
{
    protected $signature = 'azerioid:mail-boundaries-converge';

    protected $description = 'Confine mail sending and DKIM signing to authenticated submission (A123-A125)';

    public function handle(BrokerClient $broker): int
    {
        $response = $broker->call('mail.boundaries.converge', [], [], 300, false);
        if (! $response->ok) {
            $this->error('mail.boundaries.converge: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }
        $data = is_array($response->data) ? $response->data : [];
        $this->line(($data['applied'] ?? false) ? 'Mail boundaries converged.' : 'Nothing to change ('.($data['reason'] ?? 'converged').').');

        return self::SUCCESS;
    }
}
