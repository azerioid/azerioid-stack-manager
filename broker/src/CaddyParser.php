<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\OctaneManager;
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
     *   runtime: string,
     *   octane_port: ?int,
     *   octane_max_requests: ?int,
     *   pm2_port: ?int,
     *   pm2_instances: ?int,
     *   pm2_entry: ?string,
     *   docker_port: ?int,
     *   docker_internal_port: ?int,
     *   docker_mode: ?string,
     *   docker_image: ?string,
     *   docker_compose: ?string,
     *   docker_dockerfile: ?string,
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

        $octane = ($managed['runtime'] ?? null) === OctaneManager::RUNTIME_OCTANE;
        $pm2 = ($managed['runtime'] ?? null) === AppRuntime::PM2;
        $docker = ($managed['runtime'] ?? null) === AppRuntime::DOCKER;

        $type = 'static';
        if (VhostEngine::isBackend($engine)) {
            $type = $managed['type'] ?? 'static';
            if ($phpSocket !== null && $type === 'static') {
                $type = 'php';
            }
            if (isset($managed['root']) && $managed['root'] !== '') {
                $root = $managed['root'];
            }
        } elseif ($octane) {
            // Octane sites render reverse_proxy to the loopback worker but stay type=php.
            $type = $managed['type'] ?? 'php';
            if (isset($managed['root']) && $managed['root'] !== '') {
                $root = $managed['root'];
            }
        } elseif ($pm2 || $docker) {
            // PM2/Docker sites render reverse_proxy to loopback but keep root in managed comment.
            $type = $managed['type'] ?? 'proxy';
            if (isset($managed['root']) && $managed['root'] !== '') {
                $root = $managed['root'];
            }
        } elseif ($proxy !== null) {
            $type = 'proxy';
        } elseif ($phpSocket !== null) {
            $type = 'php';
        }

        // Proxy (and other) sites store docroot only in the managed comment — no `root *` directive.
        if (($root === null || $root === '') && isset($managed['root']) && $managed['root'] !== '') {
            $root = $managed['root'];
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
            'runtime' => match (true) {
                $octane => OctaneManager::RUNTIME_OCTANE,
                $pm2 => AppRuntime::PM2,
                $docker => AppRuntime::DOCKER,
                default => OctaneManager::RUNTIME_FPM,
            },
            'octane_port' => $octane ? $managed['octane_port'] : null,
            'octane_max_requests' => $octane ? $managed['octane_max_requests'] : null,
            'pm2_port' => $pm2 ? $managed['pm2_port'] : null,
            'pm2_instances' => $pm2 ? $managed['pm2_instances'] : null,
            'pm2_entry' => $pm2 ? $managed['pm2_entry'] : null,
            'docker_port' => $docker ? $managed['docker_port'] : null,
            'docker_internal_port' => $docker ? $managed['docker_internal_port'] : null,
            'docker_mode' => $docker ? $managed['docker_mode'] : null,
            'docker_image' => $docker ? $managed['docker_image'] : null,
            'docker_compose' => $docker ? $managed['docker_compose'] : null,
            'docker_dockerfile' => $docker ? $managed['docker_dockerfile'] : null,
            'readonly' => $active && ManagedVhost::isReadonly(
                $path,
                $domains,
                $root,
                $type,
                $readonlyVhosts,
                ($managed['engine'] ?? null) !== null || ($managed['type'] ?? null) !== null,
            ),
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
     * @return array{
     *   engine:?string,type:?string,php:?string,root:?string,runtime:?string,
     *   octane_port:?int,octane_max_requests:?int,
     *   pm2_port:?int,pm2_instances:?int,pm2_entry:?string,
     *   docker_port:?int,docker_internal_port:?int,docker_mode:?string,
     *   docker_image:?string,docker_compose:?string,docker_dockerfile:?string
     * }
     */
    private static function parseManagedComment(string $contents): array
    {
        $out = [
            'engine' => null,
            'type' => null,
            'php' => null,
            'root' => null,
            'runtime' => null,
            'octane_port' => null,
            'octane_max_requests' => null,
            'pm2_port' => null,
            'pm2_instances' => null,
            'pm2_entry' => null,
            'docker_port' => null,
            'docker_internal_port' => null,
            'docker_mode' => null,
            'docker_image' => null,
            'docker_compose' => null,
            'docker_dockerfile' => null,
        ];
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
        if (preg_match('/\bruntime=(fpm|octane|pm2|docker)\b/', $rest, $rn)) {
            $out['runtime'] = $rn[1];
        }
        if (preg_match('/\boctane_port=([0-9]{2,5})\b/', $rest, $op)) {
            $out['octane_port'] = (int) $op[1];
        }
        if (preg_match('/\boctane_max_requests=([0-9]{1,7})\b/', $rest, $om)) {
            $out['octane_max_requests'] = (int) $om[1];
        }
        if (preg_match('/\bpm2_port=([0-9]{2,5})\b/', $rest, $pp)) {
            $out['pm2_port'] = (int) $pp[1];
        }
        if (preg_match('/\bpm2_instances=([0-9]{1,3})\b/', $rest, $pi)) {
            $out['pm2_instances'] = (int) $pi[1];
        }
        if (preg_match('/\bpm2_entry=(\S+)/', $rest, $pe)) {
            $out['pm2_entry'] = $pe[1];
        }
        if (preg_match('/\bdocker_port=([0-9]{2,5})\b/', $rest, $dp)) {
            $out['docker_port'] = (int) $dp[1];
        }
        if (preg_match('/\bdocker_internal_port=([0-9]{1,5})\b/', $rest, $di)) {
            $out['docker_internal_port'] = (int) $di[1];
        }
        if (preg_match('/\bdocker_mode=(image|compose|dockerfile)\b/', $rest, $dm)) {
            $out['docker_mode'] = $dm[1];
        }
        if (preg_match('/\bdocker_image=(\S+)/', $rest, $dimg)) {
            $out['docker_image'] = $dimg[1];
        }
        if (preg_match('/\bdocker_compose=(\S+)/', $rest, $dc)) {
            $out['docker_compose'] = $dc[1];
        }
        if (preg_match('/\bdocker_dockerfile=(\S+)/', $rest, $dd)) {
            $out['docker_dockerfile'] = $dd[1];
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
