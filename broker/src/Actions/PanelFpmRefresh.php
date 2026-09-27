<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Panel\PanelFpmBinary;
use AzerioidPanel\Broker\Panel\PanelIdentityMigrator;
use AzerioidPanel\Broker\Runtime;

/**
 * panel.fpm.refresh — bring the EL panel master's php-fpm copy up to the distro binary (KI-3).
 *
 * Called by the panel scheduler rather than relying on self-update alone: self-update runs
 * the updater already installed, so the release that introduced the refresh could not run
 * it, and a `dnf update` between two self-updates would otherwise wait for the next one.
 * A no-op on apt and whenever the copy is current.
 */
final class PanelFpmRefresh
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $busy = (new PanelIdentityMigrator($runtime, $config))->busyReason();
        if ($busy !== null) {
            return ['refreshed' => false, 'skipped' => $busy, 'log' => []];
        }
        $log = (new PanelFpmBinary($runtime, $config))->refresh();

        return [
            'refreshed' => $log !== [] && str_contains($log[0], 'refreshed'),
            'skipped' => null,
            'log' => $log,
        ];
    }
}
