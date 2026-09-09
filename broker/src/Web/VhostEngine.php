<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;

/**
 * Per-vhost serving engine. Caddy is always the TLS front door;
 * Apache/Nginx are loopback-only backends reached via reverse_proxy.
 */
final class VhostEngine
{
    public const CADDY = 'caddy';
    public const APACHE = 'apache';
    public const NGINX = 'nginx';

    /** @var list<string> */
    public const ALL = [self::CADDY, self::APACHE, self::NGINX];

    public static function normalize(mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return self::CADDY;
        }

        return match ($raw) {
            self::CADDY, 'lcmp', 'lacmp' => self::CADDY,
            self::APACHE, 'httpd', 'apache2', 'lamp' => self::APACHE,
            self::NGINX => self::NGINX,
            default => throw new BrokerException(
                'engine must be caddy, apache, or nginx.',
                2
            ),
        };
    }

    public static function isBackend(string $engine): bool
    {
        return $engine === self::APACHE || $engine === self::NGINX;
    }

    public static function backendPort(Config $config, string $engine): int
    {
        return match ($engine) {
            self::APACHE => $config->apacheBackendPort,
            self::NGINX => $config->nginxBackendPort,
            default => throw new BrokerException('Caddy has no internal backend port.', 2),
        };
    }

    public static function backendAddress(Config $config, string $engine): string
    {
        return $config->backendBind . ':' . self::backendPort($config, $engine);
    }

    /**
     * Infer engine from a Caddy reverse_proxy target (internal backends only).
     */
    public static function inferFromProxy(?string $proxy, ?Config $config = null): ?string
    {
        if ($proxy === null || $proxy === '') {
            return null;
        }
        $proxy = strtolower(trim($proxy));
        $bind = $config !== null ? strtolower($config->backendBind) : '127.0.0.1';
        $apachePort = $config !== null ? $config->apacheBackendPort : 8081;
        $nginxPort = $config !== null ? $config->nginxBackendPort : 8082;
        if ($proxy === $bind . ':' . $apachePort || $proxy === '127.0.0.1:' . $apachePort) {
            return self::APACHE;
        }
        if ($proxy === $bind . ':' . $nginxPort || $proxy === '127.0.0.1:' . $nginxPort) {
            return self::NGINX;
        }

        return null;
    }
}
