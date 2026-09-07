<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

final class ApacheParser
{
    /**
     * @param  list<string>  $readonlyVhosts
     * @return array<string,mixed>
     */
    /**
     * @param  list<string>  $readonlyVhosts
     * @return array<string,mixed>
     */
    public static function parseFile(string $path, string $contents, array $readonlyVhosts, bool $enabled = true): array
    {
        $serverName = self::match($contents, '/^\s*ServerName\s+(\S+)/m');
        $active = is_string($serverName) && $serverName !== '';
        $domain = $active ? $serverName : '';
        $aliases = [];
        if (preg_match_all('/^\s*ServerAlias\s+(.+)$/m', $contents, $am)) {
            foreach ($am[1] as $line) {
                foreach (preg_split('/\s+/', trim($line)) ?: [] as $alias) {
                    if ($alias !== '') {
                        $aliases[] = $alias;
                    }
                }
            }
        }
        $domains = $active ? array_values(array_unique(array_merge([$domain], $aliases))) : [];
        $root = self::match($contents, '/^\s*DocumentRoot\s+(\S+)/m');
        if (is_string($root)) {
            $root = trim($root, '"\'');
        }
        $proxy = self::match($contents, '/^\s*ProxyPass\s+\/\s+(\S+)/m');
        if (is_string($proxy)) {
            $proxy = rtrim($proxy, '/');
            $proxy = preg_replace('#^https?://#', '', $proxy) ?? $proxy;
        }
        $phpSocket = null;
        if (preg_match('/proxy:unix:([^|"]+)/', $contents, $m)) {
            $phpSocket = 'unix/' . $m[1];
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

        $tls = (bool) preg_match('/^\s*SSLEngine\s+on/mi', $contents);
        $tlsCert = self::match($contents, '/^\s*SSLCertificateFile\s+(\S+)/mi');
        $tlsKey = self::match($contents, '/^\s*SSLCertificateKeyFile\s+(\S+)/mi');
        $tlsMode = 'off';
        if ($tls) {
            $cert = (string) $tlsCert;
            if (str_contains($cert, 'snakeoil') || str_contains($cert, 'ssl-cert-snakeoil')) {
                $tlsMode = 'internal';
            } elseif (str_contains($cert, '/etc/letsencrypt/')) {
                // HTTP-01 and DNS-01 both land under letsencrypt; DNS-01 is tagged via comment.
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
            'readonly' => $active && ManagedVhost::isReadonly($path, $domains, $root, $type, $readonlyVhosts),
            'enabled' => $enabled,
            'active' => $active,
            'source' => $path,
        ];
    }

    private static function match(string $contents, string $pattern): ?string
    {
        if (preg_match($pattern, $contents, $m)) {
            return $m[1];
        }
        return null;
    }
}
