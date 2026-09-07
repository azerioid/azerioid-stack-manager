<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

/**
 * Active vhost registration helpers.
 *
 * Source of truth is a parsed web-server config with a real site identity —
 * never "docroot directory exists on disk".
 */
final class VhostRegistration
{
    /**
     * @param  array<string,mixed>  $parsed
     */
    public static function isActive(array $parsed): bool
    {
        if (array_key_exists('active', $parsed)) {
            return (bool) $parsed['active'];
        }
        $domain = trim((string) ($parsed['domain'] ?? ''));
        $domains = $parsed['domains'] ?? [];

        return $domain !== '' && is_array($domains) && $domains !== [];
    }

    /**
     * @param  list<array<string,mixed>>  $vhosts
     */
    public static function findDomain(array $vhosts, string $domain): ?array
    {
        foreach ($vhosts as $parsed) {
            if (!self::isActive($parsed)) {
                continue;
            }
            if (($parsed['domain'] ?? '') === $domain || in_array($domain, $parsed['domains'] ?? [], true)) {
                return $parsed;
            }
        }

        return null;
    }
}
