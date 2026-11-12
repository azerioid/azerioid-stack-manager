<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\Config;

final class WebServers
{
    public static function for(Config $config): WebServerDriver
    {
        return new VhostFrontRouter();
    }

    /**
     * A94: refuse a docroot that is, contains, or lies inside another vhost's docroot.
     * Each vhost's identity takes ownership of its own docroot tree (A49), so an
     * overlap hands one site's files to the other. Sibling docroots under a shared
     * top directory (A62/A63) do not overlap and stay allowed.
     *
     * $root comes from Validator::webRoot, already resolved. A stored root may name
     * the base through a symlinked www root or predate A47, so the configured base
     * prefix is mapped to its real path - but only the base: resolving the rest would
     * follow links a site owns, and a site that pointed its own root at '/' could then
     * block every add and edit.
     */
    public static function assertRootNotShared(\AzerioidPanel\Broker\Runtime $runtime, Config $config, string $domain, string $root): void
    {
        $root = rtrim($root, '/');
        $base = \AzerioidPanel\Broker\Validator::normalizeAbsolute(rtrim($config->wwwRoot, '/'));
        $baseReal = rtrim($runtime->realPath($base), '/');
        foreach (self::for($config)->listVhosts($runtime, $config) as $vhost) {
            if (($vhost['domain'] ?? '') === $domain) {
                continue;
            }
            $other = (string) ($vhost['root'] ?? '');
            if ($other === '') {
                continue;
            }
            $other = \AzerioidPanel\Broker\Validator::normalizeAbsolute($other);
            if (str_starts_with($other, $base . '/')) {
                $other = $baseReal . substr($other, strlen($base));
            }
            $other = rtrim($other, '/');
            if ($other === '' || $other === $baseReal || !str_starts_with($other, $baseReal . '/')) {
                continue;
            }
            if ($other === $root || str_starts_with($root, $other . '/') || str_starts_with($other, $root . '/')) {
                throw new \AzerioidPanel\Broker\BrokerException(
                    "The web root {$root} overlaps the web root of " . ($vhost['domain'] ?? 'another vhost') . "; each vhost needs its own directory.",
                    3
                );
            }
        }
    }
}
