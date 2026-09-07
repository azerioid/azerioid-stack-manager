<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

final class NginxParser
{
    /**
     * @param  list<string>  $readonlyVhosts
     * @return array<string, mixed>
     */
    public static function parseFile(string $path, string $contents, array $readonlyVhosts, bool $enabled = true): array
    {
        $domain = self::match($contents, '/^\s*server_name\s+([^;]+);/m') ?? basename($path, '.conf');
        $domain = preg_split('/\s+/', trim($domain))[0] ?? $domain;
        $aliases = [];
        if (preg_match('/^\s*server_name\s+([^;]+);/m', $contents, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $alias) {
                if ($alias !== '' && $alias !== $domain) {
                    $aliases[] = $alias;
                }
            }
        }
        $domains = array_values(array_unique(array_merge([$domain], $aliases)));
        $root = self::match($contents, '/^\s*root\s+([^;]+);/m');
        if (is_string($root)) {
            $root = trim($root, " \t\n\r\0\x0B\"'");
        }
        $proxy = self::match($contents, '/^\s*proxy_pass\s+https?:\/\/([^;]+);/m');
        if (is_string($proxy)) {
            $proxy = rtrim($proxy, '/');
        }
        $phpSocket = null;
        if (preg_match('/fastcgi_pass\s+unix:([^;]+);/', $contents, $m)) {
            $phpSocket = 'unix/' . trim($m[1]);
        }
        $phpVersion = null;
        if ($phpSocket !== null && preg_match('/php([0-9]+\.[0-9]+)-fpm\.sock/', $phpSocket, $m)) {
            $phpVersion = $m[1];
        }

        $type = 'static';
        if ($proxy !== null && $proxy !== '') {
            $type = 'proxy';
        } elseif ($phpSocket !== null) {
            $type = 'php';
        }

        $tls = (bool) preg_match('/^\s*listen\s+443\b/m', $contents);
        $tlsCert = self::match($contents, '/^\s*ssl_certificate\s+([^;]+);/m');
        $tlsKey = self::match($contents, '/^\s*ssl_certificate_key\s+([^;]+);/m');
        $tlsMode = 'off';
        if ($tls) {
            $cert = (string) $tlsCert;
            if (str_contains($cert, 'snakeoil') || str_contains($cert, 'ssl-cert-snakeoil')) {
                $tlsMode = 'internal';
            } elseif (str_contains($cert, '/etc/letsencrypt/')) {
                $tlsMode = str_contains($contents, 'azerioid-tls-mode=dns01') ? 'dns01' : 'auto';
            } else {
                $tlsMode = 'auto';
            }
        }

        return [
            'domains' => $domains,
            'domain' => $domain,
            'root' => $root,
            'php_socket' => $phpSocket,
            'php_version' => $phpVersion,
            'type' => $type,
            'tls' => $tls,
            'tls_mode' => $tlsMode,
            'tls_cert' => $tlsCert,
            'tls_key' => $tlsKey,
            'reverse_proxy' => $type === 'proxy' ? $proxy : null,
            'readonly' => ManagedVhost::isReadonly($path, $domains, $root, $type, $readonlyVhosts),
            'enabled' => $enabled,
            'source' => $path,
        ];
    }

    private static function match(string $contents, string $pattern): ?string
    {
        if (preg_match($pattern, $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
