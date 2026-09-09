<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

use AzerioidPanel\Broker\Web\ManagedVhost;
use AzerioidPanel\Broker\Web\VhostEngine;

final class CaddyParser
{
    /**
     * Parse a Caddy v2 site file from this panel (or compatible Caddyfile snippets).
     *
     * @return array{
     *   domains: list<string>,
     *   root: ?string,
     *   php_socket: ?string,
     *   php_version: ?string,
     *   type: string,
     *   tls: bool,
     *   tls_mode: string,
     *   tls_cert: ?string,
     *   tls_key: ?string,
     *   reverse_proxy: ?string,
     *   engine: string,
     *   readonly: bool,
     *   source: string
     * }
     */
    public static function parseFile(string $path, string $contents, array $readonlyVhosts): array
    {
        $domains = self::extractDomains($contents);
        // Port-only listens (:80 / :443) have no hostname in the header — keep filename identity
        // so distro "default" sites stay listed/readonly. Comment-only orphan files stay inactive.
        if ($domains === []) {
            $stripped = preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;
            if (preg_match('/^:(?:80|443)\b/m', $stripped)) {
                $domains = [strtolower(basename($path, '.conf'))];
            }
        }
        $root = self::match($contents, '/^\s*root\s+\*\s+(\S+)/m');
        $phpSocket = self::match($contents, '/^\s*php_fastcgi\s+(\S+)/m');
        $proxy = self::match($contents, '/^\s*reverse_proxy\s+(\S+)/m');
        $managed = self::parseManagedComment($contents);
        $internalEngine = VhostEngine::inferFromProxy($proxy);
        $engine = $managed['engine'] ?? $internalEngine ?? VhostEngine::CADDY;
        $explicitHttp = (bool) preg_match('/^http:\/\//m', $contents) || (bool) preg_match('/^:80\b/m', $contents);
        $hasTlsInternal = (bool) preg_match('/^\s*tls\s+internal\b/m', $contents);
        $tlsCert = null;
        $tlsKey = null;
        if (preg_match('/^\s*tls\s+(\/\S+)\s+(\/\S+)/m', $contents, $tm)) {
            $tlsCert = $tm[1];
            $tlsKey = $tm[2];
        }
        $hasTlsBlock = $hasTlsInternal || ($tlsCert !== null);

        $type = 'static';
        if (VhostEngine::isBackend($engine)) {
            $type = $managed['type'] ?? 'static';
            if ($phpSocket !== null && $type === 'static') {
                $type = 'php';
            }
            if (isset($managed['root']) && $managed['root'] !== '') {
                $root = $managed['root'];
            }
        } elseif ($proxy !== null) {
            $type = 'proxy';
        } elseif ($phpSocket !== null) {
            $type = 'php';
        }

        $phpVersion = $managed['php'] ?? null;
        if ($phpVersion === null && $phpSocket !== null && preg_match('/php([0-9]+\.[0-9]+)-fpm\.sock/', $phpSocket, $m)) {
            $phpVersion = $m[1];
        }

        $basename = basename($path, '.conf');
        $active = $domains !== [];
        $tlsEnabled = !$explicitHttp || $hasTlsBlock;
        $tlsMode = 'off';
        if ($tlsEnabled) {
            if ($hasTlsInternal) {
                $tlsMode = 'internal';
            } elseif ($tlsCert !== null) {
                $tlsMode = str_contains((string) $tlsCert, '/etc/letsencrypt/') ? 'dns01' : 'dns01';
            } else {
                $tlsMode = 'auto';
            }
        }

        return [
            'domains' => $domains,
            // Never invent a domain from the filename — orphan/leftover files are not registrations.
            'domain' => $domains[0] ?? '',
            'root' => $root,
            'php_socket' => $phpSocket,
            'php_version' => $phpVersion,
            'type' => $type,
            'tls' => $tlsEnabled,
            'tls_mode' => $tlsMode,
            'tls_cert' => $tlsCert,
            'tls_key' => $tlsKey,
            'reverse_proxy' => $proxy,
            'engine' => $engine,
            'readonly' => $active && ManagedVhost::isReadonly($path, $domains, $root, $type, $readonlyVhosts),
            'enabled' => true,
            'active' => $active,
            'source' => $path,
            'basename' => $basename,
        ];
    }

    /** @return list<string> */
    public static function extractDomains(string $contents): array
    {
        $contents = preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;
        // Site address lines are unindented; ignore nested blocks (file_server {, header {, …).
        if (!preg_match_all('/^(\S[^{\n]*?)[ \t]*\{/m', $contents, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $domains = [];
        foreach ($matches as $m) {
            $header = trim($m[1]);
            $parts = array_map('trim', explode(',', $header));
            foreach ($parts as $part) {
                $part = preg_replace('/^https?:\/\//', '', $part) ?? $part;
                $part = strtolower($part);
                // Bare :port (catch-all) is not a hostname. Strip the scheme first so
                // https://:3169 does not become a "domain" and steal site matching.
                if ($part === '' || preg_match('/^:\\d+$/', $part) === 1) {
                    continue;
                }
                $domains[] = $part;
            }
        }
        $domains = array_values(array_unique($domains));
        // Prefer non-loopback listen addresses first (panel HTTPS is often PUBLIC:port).
        usort($domains, static function (string $a, string $b): int {
            $aLoop = str_starts_with($a, '127.') || str_starts_with($a, 'localhost');
            $bLoop = str_starts_with($b, '127.') || str_starts_with($b, 'localhost');
            if ($aLoop !== $bLoop) {
                return $aLoop ? 1 : -1;
            }

            return strcmp($a, $b);
        });

        return $domains;
    }

    /**
     * @return array{engine:?string,type:?string,php:?string,root:?string}
     */
    private static function parseManagedComment(string $contents): array
    {
        $out = ['engine' => null, 'type' => null, 'php' => null, 'root' => null];
        if (!preg_match('/^#\s*azerioid-managed\s+(.+)$/m', $contents, $m)) {
            return $out;
        }
        $rest = $m[1];
        if (preg_match('/\bengine=(caddy|apache|nginx|httpd)\b/', $rest, $e)) {
            $out['engine'] = $e[1] === 'httpd' ? VhostEngine::APACHE : $e[1];
        }
        if (preg_match('/\btype=(php|static|proxy)\b/', $rest, $t)) {
            $out['type'] = $t[1];
        }
        if (preg_match('/\bphp=([0-9]+\.[0-9]+)\b/', $rest, $p)) {
            $out['php'] = $p[1];
        }
        if (preg_match('/\broot=(\S+)/', $rest, $r)) {
            $out['root'] = $r[1];
        }

        return $out;
    }

    private static function match(string $contents, string $pattern): ?string
    {
        if (preg_match($pattern, $contents, $m)) {
            return $m[1];
        }
        return null;
    }
}
