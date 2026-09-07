<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tls;

use AzerioidPanel\Broker\Runtime;

/** Probe the currently served TLS certificate for a hostname (SNI on 127.0.0.1:443). */
final class CertProbe
{
    /**
     * @return array{
     *   domain:string,
     *   ok:bool,
     *   error:?string,
     *   issuer:?string,
     *   issuer_type:string,
     *   valid_from:?string,
     *   valid_to:?string,
     *   days_remaining:?int,
     *   renewal:string
     * }
     */
    public static function probe(Runtime $runtime, string $domain, string $tlsMode = TlsMode::AUTO, int $port = 443): array
    {
        $pem = '';
        $lastError = null;
        // Retry briefly: ACME can finish between list loads, and a single s_client during
        // obtain/reload otherwise yields a false "No certificate captured."
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $raw = $runtime->exec([
                '/usr/bin/openssl',
                's_client',
                '-connect',
                '127.0.0.1:' . $port,
                '-servername',
                $domain,
                '-showcerts',
            ], "\n", 8);
            if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $raw->stdout . $raw->stderr, $m)) {
                $pem = $m[0];
                break;
            }
            $lastError = trim($raw->stderr) !== '' ? trim($raw->stderr) : 'No certificate captured.';
            if ($attempt < 3) {
                usleep(250_000);
            }
        }
        if ($pem === '') {
            $err = $lastError ?? 'No certificate captured.';
            // Keep operator-facing text short (full openssl stderr is noisy).
            if (strlen($err) > 120 || str_contains(strtolower($err), 'verify return')) {
                $err = 'No certificate captured yet (ACME may still be running).';
            }

            return [
                'domain' => $domain,
                'ok' => false,
                'error' => $err,
                'issuer' => null,
                'issuer_type' => $tlsMode === TlsMode::OFF ? 'none' : 'pending',
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'renewal' => 'unknown',
            ];
        }
        $info = $runtime->exec([
            '/usr/bin/openssl',
            'x509',
            '-noout',
            '-issuer',
            '-dates',
            '-enddate',
        ], $pem . "\n", 5);
        $issuer = $notBefore = $notAfter = null;
        foreach (explode("\n", $info->stdout) as $line) {
            if (str_starts_with($line, 'issuer=')) {
                $issuer = substr($line, 7);
            } elseif (str_starts_with($line, 'notBefore=')) {
                $notBefore = substr($line, 10);
            } elseif (str_starts_with($line, 'notAfter=')) {
                $notAfter = substr($line, 9);
            }
        }
        $days = null;
        if ($notAfter !== null) {
            $ts = strtotime($notAfter);
            if ($ts !== false) {
                $days = (int) floor(($ts - time()) / 86400);
            }
        }

        return [
            'domain' => $domain,
            'ok' => true,
            'error' => null,
            'issuer' => $issuer,
            'issuer_type' => TlsMode::classifyIssuer($issuer, $tlsMode),
            'valid_from' => $notBefore,
            'valid_to' => $notAfter,
            'days_remaining' => $days,
            'renewal' => $days === null ? 'unknown' : ($days < 0 ? 'expired' : ($days <= 14 ? 'expiring' : 'ok')),
        ];
    }
}
