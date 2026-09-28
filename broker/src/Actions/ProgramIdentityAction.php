<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\ProgramIdentityMigrator;
use AzerioidPanel\Broker\Validator;

/**
 * ADR A56 entrypoint.
 *
 *  program.identity.status          read-only
 *  program.identity.apply           operator: typed confirm, runs inline, retries put-back sites
 *  program.identity.converge        scheduler: starts the migration in its own unit when due
 *  program.identity.converge now    what that unit runs
 */
final class ProgramIdentityAction
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $migrator = new ProgramIdentityMigrator($runtime, $config);

        return match ($action) {
            'program.identity.status' => $migrator->status(),
            'program.identity.apply' => $migrator->apply(
                (string) ($input['confirm'] ?? ''),
                isset($input['domain']) && $input['domain'] !== '' ? Validator::domain((string) $input['domain']) : null,
            ),
            'program.identity.converge' => $migrator->converge(($args[0] ?? '') === 'now'),
            default => throw new BrokerException('Unknown program identity action.', 2),
        };
    }
}
