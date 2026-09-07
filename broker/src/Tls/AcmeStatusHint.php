<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tls;

use AzerioidPanel\Broker\Runtime;

/**
 * When no certificate is being served yet, give operators a concrete reason
 * (DNS / wrong target) instead of a bare "pending" / null.
 */
final class AcmeStatusHint
{
    /**
     * @param  list<string>  $localIpv4  This host's IPv4 addresses (empty = skip "points here" check)
     */
    public static function explainMissingCert(Runtime $runtime, string $domain, array $localIpv4 = []): string
    {
        $records = self::lookupA($runtime, $domain);
        if ($records === []) {
            return 'Failed — domain does not resolve';
        }

        // Cloudflare / other CDN ranges are a common HTTP-01 footgun.
        $cloudflare = false;
        foreach ($records as $ip) {
            if (self::isCloudflareIp($ip)) {
                $cloudflare = true;
                break;
            }
        }

        if ($localIpv4 !== []) {
            $hits = array_values(array_intersect($records, $localIpv4));
            if ($hits === []) {
                if ($cloudflare) {
                    return 'Failed — DNS is proxied (Cloudflare); grey-cloud or point A at this server';
                }

                return 'Failed — DNS does not point at this server (' . implode(', ', array_slice($records, 0, 3)) . ')';
            }
        }

        if ($cloudflare && $localIpv4 !== [] && array_intersect($records, $localIpv4) === []) {
            return 'Failed — DNS is proxied (Cloudflare); grey-cloud or point A at this server';
        }

        return 'Pending — certificate not served yet (ACME may still be running or rate-limited)';
    }

    /**
     * @return list<string>
     */
    public static function lookupA(Runtime $runtime, string $domain): array
    {
        $out = [];
        foreach (['A', 'AAAA'] as $type) {
            $dig = $runtime->exec(['/usr/bin/dig', '+short', $type, $domain], null, 5);
            if (!$dig->ok() && trim($dig->stdout) === '') {
                continue;
            }
            foreach (preg_split('/\s+/', trim($dig->stdout)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_contains($line, ' ')) {
                    continue;
                }
                // dig sometimes returns CNAME then A — skip hostnames
                if (filter_var($line, FILTER_VALIDATE_IP)) {
                    $out[] = $line;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public static function localIpv4(Runtime $runtime): array
    {
        $ips = [];
        $r = $runtime->exec(['/usr/bin/hostname', '-I'], null, 5);
        foreach (preg_split('/\s+/', trim($r->stdout)) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && !str_starts_with($ip, '127.')
            ) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isCloudflareIp(string $ip): bool
    {
        // Practical check: Cloudflare published ranges commonly seen on orange-cloud A records.
        // Not exhaustive — used only for operator messaging.
        $prefixes = [
            '104.16.', '104.17.', '104.18.', '104.19.', '104.20.', '104.21.', '104.22.', '104.23.',
            '104.24.', '104.25.', '104.26.', '104.27.', '104.28.',
            '172.64.', '172.65.', '172.66.', '172.67.', '172.68.', '172.69.', '172.70.', '172.71.',
            '162.158.', '188.114.',
        ];
        foreach ($prefixes as $p) {
            if (str_starts_with($ip, $p)) {
                return true;
            }
        }

        return false;
    }
}
