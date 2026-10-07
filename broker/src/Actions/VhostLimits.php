<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Php\SitePool;
use AzerioidPanel\Broker\Php\SitePoolMigrator;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\Pm2Manager;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * A80: per-vhost resource limits.
 *
 *  - PHP-FPM (inc1): per-request php_memory_limit_mb + pm.max_children, applied by
 *    re-rendering the pool.
 *  - Octane/PM2/Docker (inc2): a memory cap (MB) and CPU cap (percent of one core),
 *    enforced differently per runtime — Docker image/dockerfile caps are hard cgroup
 *    limits, PM2's memory cap restarts a worker that exceeds it, and Octane / Docker
 *    compose mode cannot be capped under the supervised model, so there the values
 *    are stored but reported as not enforced.
 */
final class VhostLimits
{
    /** @return array<string,mixed> */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));
        $pools = new SitePool($config, $runtime);
        $current = $pools->settings($domain);
        $kind = $this->runtimeOf($runtime, $config, $domain);

        if ($action === 'vhost.limits.show') {
            return $this->view($domain, $kind, $current);
        }

        // vhost.limits.set — only the keys the caller sends change; '' / 'default' clears.
        $php = array_key_exists('php_memory_limit_mb', $input)
            ? self::optInt($input['php_memory_limit_mb'], 16, 8192, 'PHP memory limit (MB)')
            : $current['php_memory_limit_mb'];
        $children = array_key_exists('max_children', $input)
            ? self::optInt($input['max_children'], 1, 200, 'max children')
            : $current['max_children'];
        $memory = array_key_exists('memory_mb', $input)
            ? self::optInt($input['memory_mb'], 16, 65536, 'memory (MB)')
            : $current['memory_mb'];
        $cpu = array_key_exists('cpu_percent', $input)
            ? self::optInt($input['cpu_percent'], 1, 3200, 'CPU (percent of one core)')
            : $current['cpu_percent'];

        // Persist the runtime caps first, then the PHP caps (SitePoolMigrator::setLimits
        // also re-renders the FPM pool when the site is on its own isolated pool).
        $pools->saveSettings($domain, ['memory_mb' => $memory, 'cpu_percent' => $cpu]);
        (new SitePoolMigrator($runtime, $config))->setLimits($domain, $php, $children);

        // Apply the runtime caps where they are enforceable.
        $applied = match ($kind) {
            AppRuntime::PM2 => (new Pm2Manager($config, $runtime))->applyLimits($domain),
            AppRuntime::DOCKER => (new DockerManager($config, $runtime))->applyLimits($domain),
            default => ['applied' => false, 'reason' => $kind === AppRuntime::OCTANE
                ? 'Octane cannot be capped per process under Supervisor; stored but not enforced.'
                : 'No runtime program to cap; PHP-FPM caps apply instead.'],
        };

        return $this->view($domain, $kind, $pools->settings($domain)) + ['runtime_applied' => $applied];
    }

    /** @return array<string,mixed> */
    private function view(string $domain, string $kind, array $s): array
    {
        return [
            'domain' => $domain,
            'runtime' => $kind,
            'php_memory_limit_mb' => $s['php_memory_limit_mb'],
            'max_children' => $s['max_children'],
            'memory_mb' => $s['memory_mb'],
            'cpu_percent' => $s['cpu_percent'],
            'default_max_children' => SitePool::MAX_CHILDREN,
            'enforcement' => self::enforcementNote($kind),
        ];
    }

    private static function enforcementNote(string $kind): string
    {
        return match ($kind) {
            AppRuntime::FPM => 'PHP-FPM: per-request memory_limit and max_children are enforced. The memory/CPU caps apply only to Octane/PM2/Docker runtimes.',
            AppRuntime::OCTANE => 'Octane: per-process memory/CPU caps are not enforceable under Supervisor — values are stored but not applied.',
            AppRuntime::PM2 => 'PM2: the memory cap restarts a worker that exceeds it (--max-memory-restart). PM2 has no CPU cap.',
            AppRuntime::DOCKER => 'Docker: memory and CPU caps are hard limits for image/dockerfile sites. Compose-mode sites must set mem_limit/cpus in the compose file.',
            default => 'Unknown runtime.',
        };
    }

    private function runtimeOf(Runtime $runtime, Config $config, string $domain): string
    {
        foreach (WebServers::for($config)->listVhosts($runtime, $config) as $vhost) {
            if (($vhost['domain'] ?? '') === $domain || in_array($domain, $vhost['domains'] ?? [], true)) {
                return AppRuntime::normalize((string) ($vhost['runtime'] ?? AppRuntime::FPM));
            }
        }

        throw new BrokerException("Vhost {$domain} was not found.", 3);
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
