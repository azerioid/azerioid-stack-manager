<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerClient;
use App\Support\PanelActivity;
use Illuminate\Console\Command;

/**
 * Keeps the EL panel's private php-fpm copy on the distro's patched binary (KI-3).
 *
 * The scheduler, not self-update, is what runs this: self-update runs the updater already
 * installed, so it could not refresh the copy on the very update that introduced the
 * refresh, and a `dnf update` between self-updates would otherwise wait for the next one.
 * The broker does nothing on apt or when the copy is current; when it does refresh, it
 * restarts the panel's FPM master, so this waits while operations are running.
 */
class RefreshPanelFpmBinary extends Command
{
    protected $signature = 'azerioid:panel-fpm-refresh';

    protected $description = 'Refresh the EL panel php-fpm copy from the distro binary when it has changed';

    public function handle(BrokerClient $broker): int
    {
        if (PanelActivity::busy()) {
            $this->line('Operations in progress; not now.');

            return self::SUCCESS;
        }

        $response = $broker->call('panel.fpm.refresh', [], [], 120, false);
        if (! $response->ok) {
            $this->error('panel.fpm.refresh: '.($response->error ?: 'broker call failed'));

            return self::FAILURE;
        }

        $data = is_array($response->data) ? $response->data : [];
        if (($data['skipped'] ?? null) !== null) {
            $this->line('Not now: '.$data['skipped'].'.');

            return self::SUCCESS;
        }
        foreach ((array) ($data['log'] ?? []) as $line) {
            $this->line((string) $line);
        }

        return self::SUCCESS;
    }
}
