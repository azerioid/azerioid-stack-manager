<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Panel\PanelIdentityMigrator;
use AzerioidPanel\Broker\Runtime;

/**
 * ADR A39 Part A entrypoint.
 *
 *  panel.identity.status           read-only
 *  panel.identity.apply            operator: typed confirm, runs inline, may retry a failure
 *  panel.identity.converge         scheduler: starts the migration in its own unit when due
 *  panel.identity.converge now     what that unit runs
 */
final class PanelIdentity
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $migrator = new PanelIdentityMigrator($runtime, $config);

        return match ($action) {
            'panel.identity.status' => $migrator->status(),
            'panel.identity.apply' => $migrator->apply(
                (string) ($input['confirm'] ?? ''),
                (bool) ($input['dry_run'] ?? false),
            ),
            'panel.identity.converge' => $migrator->converge(($args[0] ?? '') === 'now'),
            default => throw new BrokerException('Unknown panel action.', 2),
        };
    }
}
