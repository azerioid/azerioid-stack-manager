<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;

/**
 * Per-vhost application runtime modes (PHP-FPM, Octane, PM2).
 */
final class AppRuntime
{
    public const FPM = 'fpm';
    public const OCTANE = 'octane';
    public const PM2 = 'pm2';

    public static function normalize(mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return self::FPM;
        }

        return match ($raw) {
            self::FPM, 'php-fpm', 'traditional' => self::FPM,
            self::OCTANE, 'frankenphp' => self::OCTANE,
            self::PM2, 'pm2-runtime', 'node-pm2' => self::PM2,
            default => throw new BrokerException('runtime must be fpm, octane, or pm2.', 2),
        };
    }
}
