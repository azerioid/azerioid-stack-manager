<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Vhost\VhostIsolationMigrator;

/**
 * ADR A49 entrypoint.
 *
 *  vhost.isolation.status          read-only
 *  vhost.isolation.apply           operator: typed confirm, runs inline, may retry a failure
 *  vhost.isolation.converge        scheduler: starts the migration in its own unit when due
 *  vhost.isolation.converge now    what that unit runs
 */
final class VhostIsolation
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $migrator = new VhostIsolationMigrator($runtime, $config);

        return match ($action) {
            'vhost.isolation.status' => $migrator->status(),
            'vhost.isolation.apply' => $migrator->apply(
                (string) ($input['confirm'] ?? ''),
                (bool) ($input['dry_run'] ?? false),
            ),
            'vhost.isolation.converge' => $migrator->converge(($args[0] ?? '') === 'now'),
            default => throw new BrokerException('Unknown vhost isolation action.', 2),
        };
    }
}
