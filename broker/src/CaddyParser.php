<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

use AzerioidPanel\Broker\Web\ManagedVhost;

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
     *   reverse_proxy: ?string,
     *   readonly: bool,
     *   source: string
     * }
     */
    public static function parseFile(string $path, string $contents, array $readonlyVhosts): array
    {
        $domains = self::extractDomains($contents);
        $root = self::match($contents, '/^\s*root\s+\*\s+(\S+)/m');
        $phpSocket = self::match($contents, '/^\s*php_fastcgi\s+(\S+)/m');
        $proxy = self::match($contents, '/^\s*reverse_proxy\s+(\S+)/m');
        $explicitHttp = (bool) preg_match('/^:80\b/m', $contents);
        $hasTlsBlock = (bool) preg_match('/^\s*tls\b/m', $contents);

        $type = 'static';
        if ($proxy !== null) {
            $type = 'proxy';
        } elseif ($phpSocket !== null) {
            $type = 'php';
        }

        $phpVersion = null;
        if ($phpSocket !== null && preg_match('/php([0-9]+\.[0-9]+)-fpm\.sock/', $phpSocket, $m)) {
            $phpVersion = $m[1];
        }

        $basename = basename($path, '.conf');

        return [
            'domains' => $domains,
            'domain' => $domains[0] ?? $basename,
            'root' => $root,
            'php_socket' => $phpSocket,
            'php_version' => $phpVersion,
            'type' => $type,
            'tls' => !$explicitHttp || $hasTlsBlock,
            'reverse_proxy' => $proxy,
            'readonly' => ManagedVhost::isReadonly($path, $domains, $root, $type, $readonlyVhosts),
            'enabled' => true,
            'source' => $path,
        ];
    }

    /** @return list<string> */
    public static function extractDomains(string $contents): array
    {
        $contents = preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;
        if (!preg_match('/^([^{\n]+)\{/m', $contents, $m)) {
            return [];
        }
        $header = trim($m[1]);
        $parts = array_map('trim', explode(',', $header));
        $domains = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === ':80' || $part === ':443') {
                continue;
            }
            $part = preg_replace('/^https?:\/\//', '', $part) ?? $part;
            $part = strtolower($part);
            if ($part !== '') {
                $domains[] = $part;
            }
        }
        return array_values(array_unique($domains));
    }

    private static function match(string $contents, string $pattern): ?string
    {
        if (preg_match($pattern, $contents, $m)) {
            return $m[1];
        }
        return null;
    }
}
