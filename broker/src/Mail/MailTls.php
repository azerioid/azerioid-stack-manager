<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\Certbot;

/**
 * Select TLS material for Postfix/Dovecot (A36 §9.7).
 *
 * Prefer an existing Caddy or Let's Encrypt certificate for the mail hostname;
 * otherwise keep the distro default (snakeoil / EL postfix cert) until the
 * operator issues a dedicated certbot cert for that name.
 */
final class MailTls
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly MailPaths $paths,
    ) {
    }

    /**
     * @return array{cert:string,key:string,source:string}
     */
    public function resolve(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $fallback = [
            'cert' => $this->paths->path('tls_cert'),
            'key' => $this->paths->path('tls_key'),
            'source' => 'distro-default',
        ];
        if ($hostname === '') {
            return $fallback;
        }

        $leBase = rtrim(Certbot::LIVE_DIR, '/') . '/' . $hostname;
        $leCert = $leBase . '/fullchain.pem';
        $leKey = $leBase . '/privkey.pem';
        if ($this->pairExists($leCert, $leKey)) {
            return ['cert' => $leCert, 'key' => $leKey, 'source' => 'letsencrypt'];
        }

        foreach (self::caddyCandidates($hostname) as [$cert, $key]) {
            if ($this->pairExists($cert, $key)) {
                return ['cert' => $cert, 'key' => $key, 'source' => 'caddy'];
            }
        }

        return $fallback;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function caddyCandidates(string $hostname): array
    {
        $base = '/var/lib/caddy/.local/share/caddy/certificates';
        $dirs = [
            $base . '/acme-v02.api.letsencrypt.org-directory/' . $hostname,
            $base . '/local/' . $hostname,
        ];
        $out = [];
        foreach ($dirs as $dir) {
            $out[] = [$dir . '/' . $hostname . '.crt', $dir . '/' . $hostname . '.key'];
        }

        return $out;
    }

    private function pairExists(string $cert, string $key): bool
    {
        return $this->runtime->fileExists($cert) && $this->runtime->fileExists($key);
    }
}
