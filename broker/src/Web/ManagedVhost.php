<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

/**
 * Generic read-only rules for stack-managed sites (no per-customer hostnames).
 */
final class ManagedVhost
{
    /** Distro default / panel vhost filenames (not customer domains). */
    private const MANAGED_BASENAMES = [
        'default',
        '000-default',
        'default-ssl',
        'localhost',
        'azerioid-panel',
    ];

    /** Document roots that ship with Apache/default, not panel-created sites. */
    private const DEFAULT_DOCROOTS = [
        '/var/www/html',
        '/var/www',
        '/usr/share/apache2/default-site/html',
        '/data/www/default',
    ];

    /**
     * @param  list<string>  $domains
     * @param  list<string>  $readonlyVhosts
     */
    public static function isReadonly(
        string $path,
        array $domains,
        ?string $root,
        string $type,
        array $readonlyVhosts,
    ): bool {
        if ($type === 'proxy') {
            return true;
        }

        $basename = strtolower(basename($path, '.conf'));
        if (in_array($basename, self::MANAGED_BASENAMES, true)) {
            return true;
        }

        foreach ($domains as $domain) {
            $domain = strtolower((string) $domain);
            if ($domain === '') {
                continue;
            }
            if (in_array($domain, $readonlyVhosts, true)) {
                return true;
            }
            if (str_starts_with($domain, '127.0.0.1')) {
                return true;
            }
            if ($domain === 'localhost' || $domain === '_' || $domain === 'default') {
                return true;
            }
        }

        $rootNorm = is_string($root) ? rtrim($root, '/') : '';
        if ($rootNorm !== '' && in_array($rootNorm, self::DEFAULT_DOCROOTS, true)) {
            return true;
        }
        if ($rootNorm !== '' && str_contains($rootNorm, '/azerioid-panel/web/public')) {
            return true;
        }

        return false;
    }
}
