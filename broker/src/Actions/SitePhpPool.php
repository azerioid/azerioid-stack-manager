<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Php\SitePoolMigrator;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * ADR A55 entrypoint.
 *
 *  vhost.phppool.status            read-only
 *  vhost.phppool.apply             operator: typed confirm, runs inline, retries sites put back
 *  vhost.phppool.converge          scheduler: starts the migration in its own unit when due
 *  vhost.phppool.converge now      what that unit runs
 *  vhost.phppool.set <domain>      per-site switches (open_basedir)
 */
final class SitePhpPool
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $migrator = new SitePoolMigrator($runtime, $config);

        return match ($action) {
            'vhost.phppool.status' => $migrator->status(),
            'vhost.phppool.apply' => $migrator->apply(
                (string) ($input['confirm'] ?? ''),
                isset($input['domain']) && $input['domain'] !== '' ? Validator::domain((string) $input['domain']) : null,
            ),
            'vhost.phppool.converge' => $migrator->converge(($args[0] ?? '') === 'now'),
            'vhost.phppool.set' => $migrator->setOpenBasedir(
                Validator::domain($args[0] ?? ($input['domain'] ?? '')),
                self::bool($input['open_basedir'] ?? null),
            ),
            default => throw new BrokerException('Unknown PHP pool action.', 2),
        };
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string) $value));
        if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new BrokerException('open_basedir must be on or off.', 2);
    }
}
