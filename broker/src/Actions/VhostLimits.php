<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Php\SitePool;
use AzerioidPanel\Broker\Php\SitePoolMigrator;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * A80 (increment 1): per-vhost resource limits for PHP-FPM sites — the per-request
 * memory_limit and the pool's pm.max_children. Stored in the site's pool settings
 * and applied by re-rendering the pool. Octane/PM2 (cgroup caps) and Docker
 * (container limits) follow in later increments.
 */
final class VhostLimits
{
    /** @return array<string,mixed> */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));
        $pools = new SitePool($config, $runtime);
        $current = $pools->settings($domain);

        if ($action === 'vhost.limits.show') {
            return [
                'domain' => $domain,
                'php_memory_limit_mb' => $current['php_memory_limit_mb'],
                'max_children' => $current['max_children'],
                'default_max_children' => SitePool::MAX_CHILDREN,
            ];
        }

        // vhost.limits.set — only the keys the caller sends are changed; an empty
        // value or "default" clears a cap back to the panel default.
        $memory = array_key_exists('php_memory_limit_mb', $input)
            ? self::optInt($input['php_memory_limit_mb'], 16, 8192, 'PHP memory limit (MB)')
            : $current['php_memory_limit_mb'];
        $children = array_key_exists('max_children', $input)
            ? self::optInt($input['max_children'], 1, 200, 'max children')
            : $current['max_children'];

        return (new SitePoolMigrator($runtime, $config))->setLimits($domain, $memory, $children);
    }

    private static function optInt(mixed $value, int $min, int $max, string $label): ?int
    {
        $v = strtolower(trim((string) $value));
        if ($v === '' || $v === 'default' || $v === 'null') {
            return null;
        }
        if (preg_match('/^\d+$/', $v) !== 1) {
            throw new BrokerException("Invalid {$label}.", 2);
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            throw new BrokerException("{$label} must be between {$min} and {$max}.", 2);
        }

        return $n;
    }
}
