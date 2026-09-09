<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tls;

use AzerioidPanel\Broker\BrokerException;

/**
 * TLS modes for user vhosts.
 *
 * - off: HTTP only
 * - auto: Caddy-native Let's Encrypt HTTP-01 (front router; all engines)
 * - internal: Caddy tls internal (self-signed)
 * - dns01: certbot DNS-01 → static tls cert/key on the Caddy block
 */
final class TlsMode
{
    public const OFF = 'off';
    public const AUTO = 'auto';
    public const INTERNAL = 'internal';
    public const DNS01 = 'dns01';

    public const ALL = [self::OFF, self::AUTO, self::INTERNAL, self::DNS01];

    /** Reserved / non-public labels that must not attempt public ACME HTTP-01. */
    private const NON_PUBLIC_TLDS = [
        'test', 'localhost', 'local', 'invalid', 'example', 'lan', 'internal', 'home', 'corp',
    ];

    public static function normalize(mixed $value, bool $legacyTlsBool = false): string
    {
        if (is_bool($value)) {
            return $value ? self::AUTO : self::OFF;
        }
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $legacyTlsBool ? self::AUTO : self::OFF;
        }
        return match ($raw) {
            // Canonical values first (must always round-trip).
            self::AUTO, '1', 'true', 'yes', 'on', 'http01', 'http-01', 'acme', 'letsencrypt', 'le' => self::AUTO,
            self::OFF, '0', 'false', 'no', 'http' => self::OFF,
            self::INTERNAL, 'self', 'self-signed', 'selfsigned', 'snakeoil' => self::INTERNAL,
            self::DNS01, 'dns-01', 'dns', 'wildcard' => self::DNS01,
            default => throw new BrokerException(
                'tls_mode must be off|auto|internal|dns01.',
                2
            ),
        };
    }

    public static function enabled(string $mode): bool
    {
        return $mode !== self::OFF;
    }

    /**
     * Whether a hostname is eligible for public ACME HTTP-01 (not an IP, not a reserved TLD).
     */
    public static function isPublicHostname(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (str_contains($domain, ':')) {
            return false;
        }
        if (!str_contains($domain, '.')) {
            return false;
        }
        $labels = explode('.', $domain);
        $tld = $labels[array_key_last($labels)] ?? '';
        if (in_array($tld, self::NON_PUBLIC_TLDS, true)) {
            return false;
        }
        // Single-label or numeric-looking TLD is not a public registrable name.
        if ($tld === '' || ctype_digit($tld)) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $tld);
    }

    /**
     * Resolve effective mode: auto on non-public hostnames becomes internal.
     */
    public static function effective(string $mode, string $domain): string
    {
        if ($mode === self::AUTO && !self::isPublicHostname($domain)) {
            return self::INTERNAL;
        }

        return $mode;
    }

    public static function classifyIssuer(?string $issuer, string $mode): string
    {
        if ($mode === self::OFF) {
            return 'none';
        }
        $issuer = (string) $issuer;
        $lower = strtolower($issuer);
        if ($issuer === '') {
            return $mode === self::INTERNAL ? 'self_signed' : 'pending';
        }
        if (str_contains($lower, 'let\'s encrypt')
            || str_contains($lower, 'lets encrypt')
            || str_contains($lower, "o=let's encrypt")
            || str_contains($lower, 'isrg')
        ) {
            return $mode === self::DNS01 ? 'dns01' : 'lets_encrypt';
        }
        if (str_contains($lower, 'caddy')
            || str_contains($lower, 'local authority')
            || str_contains($lower, 'snakeoil')
            || str_contains($lower, 'ubuntu')
            || str_contains($lower, 'self-signed')
            || str_contains($lower, 'azerioid')
        ) {
            return 'self_signed';
        }

        return 'unknown';
    }
}
