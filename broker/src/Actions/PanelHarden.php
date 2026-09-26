<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Panel\PanelHardener;
use AzerioidPanel\Broker\Runtime;

/**
 * R1 remediation entrypoint: panel.harden.status (read-only) and
 * panel.harden.apply (transactional migration off a site-pool identity).
 */
final class PanelHarden
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $hardener = new PanelHardener($runtime, $config);

        if ($action === 'panel.harden.status') {
            return $hardener->status();
        }

        if ($action !== 'panel.harden.apply') {
            throw new BrokerException('Unknown panel harden action.', 2);
        }

        return $hardener->apply(
            (string) ($input['confirm'] ?? ''),
            (bool) ($input['lockdown_site_pools'] ?? false),
            (bool) ($input['dry_run'] ?? false),
        );
    }
}
