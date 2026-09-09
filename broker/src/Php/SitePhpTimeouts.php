<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Php;

use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\CaddyCli;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Os\DistroPaths;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Bounded PHP request lifetime for site (and panel) FPM pools plus reverse-proxy
 * read timeouts. This product uses a shared www pool per PHP version — not a
 * per-vhost pool — so the site pool + every vhost's php_fastcgi / fastcgi /
 * ProxyTimeout must all be bounded.
 */
final class SitePhpTimeouts
{
    public const MAX_EXECUTION_SECONDS = 30;
    public const REQUEST_TERMINATE_SECONDS = 30;
    public const PANEL_TERMINATE_SECONDS = 60;
    public const PROXY_DIAL_SECONDS = 10;
    public const PROXY_READ_SECONDS = 35;

    /**
     * @return array{pools:list<string>,ini:list<string>,vhosts:list<string>,reloaded:list<string>}
     */
    public function ensure(Runtime $runtime, Config $config, bool $reloadWeb = true): array
    {
        $pools = $this->patchPools($runtime, $config);
        $ini = $this->ensurePhpIni($runtime, $config);
        $vhosts = $this->ensureVhostProxies($runtime, $config);
        $reloaded = [];
        if ($pools !== [] || $ini !== []) {
            $reloaded = array_merge($reloaded, $this->reloadFpm($runtime, $config));
        }
        if ($reloadWeb && $vhosts !== []) {
            $reloaded[] = $this->reloadWeb($runtime, $config);
        }

        return [
            'pools' => $pools,
            'ini' => $ini,
            'vhosts' => $vhosts,
            'reloaded' => array_values(array_filter($reloaded)),
            'max_execution_time' => self::MAX_EXECUTION_SECONDS,
            'request_terminate_timeout' => self::REQUEST_TERMINATE_SECONDS,
            'proxy_read_timeout' => self::PROXY_READ_SECONDS,
        ];
    }

    /**
     * Patch www + panel FPM pools and reload FPM when anything changed.
     * Safe to call from vhost add (no web reload).
     *
     * @return list<string>
     */
    public function ensureSitePools(Runtime $runtime, Config $config): array
    {
        $changed = $this->patchPools($runtime, $config);
        if ($changed !== []) {
            $this->reloadFpm($runtime, $config);
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    public function patchPools(Runtime $runtime, ?Config $config = null): array
    {
        $changed = [];
        foreach ($this->poolPaths($runtime, $config ?? new Config()) as $path) {
            $isPanel = str_contains($path, 'azerioid-panel');
            $aclUsers = $isPanel ? [] : $this->existingDialUsers();
            if (!$this->patchFile($runtime, $path, fn (string $text): string => $this->patchPool($text, $isPanel, $aclUsers))) {
                continue;
            }
            $changed[] = $path;
        }

        return $changed;
    }

    public static function caddyPhpFastcgiBlock(string $sock, string $indent = '    '): string
    {
        $inner = $indent . '    ';
        $dial = self::PROXY_DIAL_SECONDS;
        $read = self::PROXY_READ_SECONDS;

        return "{$indent}php_fastcgi {$sock} {\n{$inner}dial_timeout {$dial}s\n{$inner}read_timeout {$read}s\n{$indent}}\n";
    }

    public static function rewriteCaddyPhpFastcgi(string $contents): string
    {
        $rewritten = preg_replace_callback(
            '/^([ \t]*)php_fastcgi\s+(\S+)(?:[ \t]*\{[^{}]*\})?/m',
            static function (array $m): string {
                return rtrim(self::caddyPhpFastcgiBlock($m[2], $m[1]), "\n");
            },
            $contents
        );

        return is_string($rewritten) ? $rewritten : $contents;
    }

    public static function nginxFastcgiTimeouts(): string
    {
        $dial = self::PROXY_DIAL_SECONDS;
        $read = self::PROXY_READ_SECONDS;

        return "        fastcgi_connect_timeout {$dial}s;\n        fastcgi_send_timeout {$read}s;\n        fastcgi_read_timeout {$read}s;\n";
    }

    public static function rewriteNginxFastcgi(string $contents): string
    {
        if (str_contains($contents, 'fastcgi_read_timeout')) {
            $contents = preg_replace(
                '/fastcgi_read_timeout\s+\S+;/',
                'fastcgi_read_timeout ' . self::PROXY_READ_SECONDS . 's;',
                $contents
            ) ?? $contents;
            $contents = preg_replace(
                '/fastcgi_send_timeout\s+\S+;/',
                'fastcgi_send_timeout ' . self::PROXY_READ_SECONDS . 's;',
                $contents
            ) ?? $contents;
            $contents = preg_replace(
                '/fastcgi_connect_timeout\s+\S+;/',
                'fastcgi_connect_timeout ' . self::PROXY_DIAL_SECONDS . 's;',
                $contents
            ) ?? $contents;

            return $contents;
        }
        if (!preg_match('/fastcgi_pass\s+/', $contents)) {
            return $contents;
        }
        $injected = preg_replace(
            '/(fastcgi_pass\s+[^;]+;)/',
            '$1' . "\n" . rtrim(self::nginxFastcgiTimeouts()),
            $contents,
            1
        );

        return is_string($injected) ? $injected : $contents;
    }

    public static function apacheProxyTimeouts(): string
    {
        $read = self::PROXY_READ_SECONDS;

        return "    Timeout {$read}\n    ProxyTimeout {$read}\n";
    }

    public static function rewriteApacheProxy(string $contents): string
    {
        if (!str_contains($contents, 'proxy:unix:') && !str_contains($contents, 'proxy:fcgi:')) {
            return $contents;
        }
        if (preg_match('/^\s*ProxyTimeout\s+/m', $contents)) {
            $contents = preg_replace(
                '/^\s*ProxyTimeout\s+\S+\s*$/m',
                '    ProxyTimeout ' . self::PROXY_READ_SECONDS,
                $contents
            ) ?? $contents;
        } else {
            $contents = preg_replace(
                '/(<VirtualHost\b[^>]*>)/',
                '$1' . "\n" . rtrim(self::apacheProxyTimeouts()),
                $contents
            ) ?? $contents;
        }
        if (preg_match('/^\s*Timeout\s+/m', $contents)) {
            $contents = preg_replace(
                '/^\s*Timeout\s+\S+\s*$/m',
                '    Timeout ' . self::PROXY_READ_SECONDS,
                $contents
            ) ?? $contents;
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function ensurePhpIni(Runtime $runtime, Config $config): array
    {
        $changed = [];
        foreach ($runtime->phpVersions() as $ver) {
            $path = $config->phpIniPath($ver, $runtime);
            if (!$runtime->fileExists($path)) {
                continue;
            }
            $ini = $runtime->readFile($path);
            if (!preg_match('/^\s*max_execution_time\s*=\s*(.+)$/m', $ini, $m)) {
                $ini = rtrim($ini) . "\nmax_execution_time = " . self::MAX_EXECUTION_SECONDS . "\n";
                $runtime->writeFile($path, $ini, 0644);
                $changed[] = $path;
                continue;
            }
            $current = trim($m[1], " \t\"'");
            if ($current !== '0') {
                continue;
            }
            $ini = preg_replace(
                '/^\s*max_execution_time\s*=.*$/m',
                'max_execution_time = ' . self::MAX_EXECUTION_SECONDS,
                $ini,
                1
            ) ?? $ini;
            $runtime->writeFile($path, $ini, 0644);
            $changed[] = $path;
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function ensureVhostProxies(Runtime $runtime, Config $config): array
    {
        $changed = [];
        foreach ($this->caddyVhostPaths($runtime, $config) as $path) {
            if ($this->patchFile($runtime, $path, [self::class, 'rewriteCaddyPhpFastcgi'])) {
                $changed[] = $path;
            }
        }
        foreach ($this->nginxVhostPaths($runtime, $config) as $path) {
            if ($this->patchFile($runtime, $path, [self::class, 'rewriteNginxFastcgi'])) {
                $changed[] = $path;
            }
        }
        foreach ($this->apacheVhostPaths($runtime, $config) as $path) {
            if ($this->patchFile($runtime, $path, [self::class, 'rewriteApacheProxy'])) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * @param  callable(string):string  $rewriter
     */
    private function patchFile(Runtime $runtime, string $path, callable $rewriter): bool
    {
        if (!$runtime->fileExists($path)) {
            return false;
        }
        $before = $runtime->readFile($path);
        $after = $rewriter($before);
        if ($after === $before) {
            return false;
        }
        $runtime->writeFile($path, $after, 0644);

        return true;
    }

    public function patchPool(string $text, bool $panel, array $aclUsers = ['caddy']): string
    {
        $terminate = $panel ? self::PANEL_TERMINATE_SECONDS : self::REQUEST_TERMINATE_SECONDS;
        $exec = $panel ? self::PANEL_TERMINATE_SECONDS : self::MAX_EXECUTION_SECONDS;
        $text = $this->setBareDirective($text, 'request_terminate_timeout', (string) $terminate);
        $text = $this->setAdminValue($text, 'max_execution_time', (string) $exec);
        if (!$panel) {
            $text = $this->ensureFrontRouterCanDial($text, $aclUsers);
        }

        return $text;
    }

    /**
     * EL php-fpm www.sock uses listen.acl_users=apache,nginx. Caddy is the
     * public front router (user `caddy`) and cannot connect → HTTP 502.
     * FPM fails to start if an ACL name has no uid — only pass existing users.
     *
     * @param list<string> $aclUsers
     */
    public function ensureFrontRouterCanDial(string $text, array $aclUsers = ['caddy']): string
    {
        $needed = array_values(array_unique(array_filter($aclUsers, static fn (string $u): bool => $u !== '')));
        if ($needed === []) {
            return $text;
        }
        if (preg_match('/^\s*listen\.acl_users\s*=\s*(.*)$/m', $text, $m)) {
            $existing = array_values(array_filter(array_map('trim', explode(',', $m[1])), static fn (string $u): bool => $u !== ''));
            $merged = [];
            foreach (array_merge($existing, $needed) as $u) {
                if (!in_array($u, $merged, true)) {
                    $merged[] = $u;
                }
            }
            $line = 'listen.acl_users = ' . implode(',', $merged);

            return preg_replace('/^\s*listen\.acl_users\s*=.*$/m', $line, $text, 1) ?? $text;
        }

        return rtrim($text) . "\nlisten.acl_users = " . implode(',', $needed) . "\n";
    }

    /** @return list<string> */
    private function existingDialUsers(): array
    {
        $found = [];
        foreach (DistroPaths::webProcessUserNames() as $user) {
            if (function_exists('posix_getpwnam') && posix_getpwnam($user) !== false) {
                $found[] = $user;
            }
        }

        return $found;
    }

    private function setBareDirective(string $text, string $key, string $value): string
    {
        $line = $key . ' = ' . $value;
        $pattern = '/^\s*;?\s*' . preg_quote($key, '/') . '\s*=.*$/m';
        if (preg_match($pattern, $text)) {
            return preg_replace($pattern, $line, $text) ?? $text;
        }

        return rtrim($text) . "\n{$line}\n";
    }

    private function setAdminValue(string $text, string $key, string $value): string
    {
        $line = 'php_admin_value[' . $key . '] = ' . $value;
        $pattern = '/^\s*;?\s*php_admin_value\[' . preg_quote($key, '/') . '\]\s*=.*$/m';
        if (preg_match($pattern, $text)) {
            return preg_replace($pattern, $line, $text) ?? $text;
        }

        return rtrim($text) . "\n{$line}\n";
    }

    /** @return list<string> */
    private function poolPaths(Runtime $runtime, Config $config): array
    {
        $layout = DistroPaths::for($runtime, $config);
        $paths = $layout->expandGlobs($layout->phpFpmPoolGlobs());
        $out = [];
        foreach ($paths as $path) {
            $base = basename($path);
            if ($base === 'www.conf' || $base === 'azerioid-panel.conf' || $base === 'www-pool.conf') {
                $out[] = $path;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function caddyVhostPaths(Runtime $runtime, Config $config): array
    {
        return $runtime->glob(rtrim($config->caddyConfD, '/') . '/*.conf');
    }

    /** @return list<string> */
    private function nginxVhostPaths(Runtime $runtime, Config $config): array
    {
        $layout = DistroPaths::for($runtime, $config);
        $dirs = array_merge($layout->nginxVhostScanDirs(), [
            rtrim((string) $config->vhostAvailableDir, '/'),
            rtrim((string) $config->vhostDir, '/'),
        ]);

        return $this->confsIn($runtime, $dirs);
    }

    /** @return list<string> */
    private function apacheVhostPaths(Runtime $runtime, Config $config): array
    {
        $layout = DistroPaths::for($runtime, $config);
        $dirs = array_merge($layout->apacheVhostScanDirs(), [
            rtrim((string) $config->vhostAvailableDir, '/'),
            rtrim((string) $config->vhostDir, '/'),
        ]);

        return $this->confsIn($runtime, $dirs);
    }

    /**
     * @param  list<string>  $dirs
     * @return list<string>
     */
    private function confsIn(Runtime $runtime, array $dirs): array
    {
        $out = [];
        foreach (array_unique($dirs) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($runtime->glob($dir . '/*.conf') as $path) {
                $out[] = $path;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function reloadFpm(Runtime $runtime, Config $config): array
    {
        $reloaded = [];
        foreach ($runtime->phpVersions() as $ver) {
            $unit = $config->phpFpmService($ver, $runtime);
            $st = $runtime->exec(['/usr/bin/systemctl', 'is-active', $unit], null, 10);
            if (trim($st->stdout) !== 'active') {
                continue;
            }
            Systemd::control($runtime, 'reload', $unit);
            $reloaded[] = $unit;
        }

        return $reloaded;
    }

    private function reloadWeb(Runtime $runtime, Config $config): string
    {
        $driver = WebServers::for($config);
        if ($driver->webServiceName() === 'caddy') {
            $caddyfile = Config::CADDYFILE;
            if ($runtime->fileExists($caddyfile)) {
                $validate = CaddyCli::validate($runtime, $config, $caddyfile);
                if ($validate->ok()) {
                    CaddyApply::run($runtime, $config, 'auto');

                    return 'caddy';
                }
            }
        }
        $unit = $driver->webServiceName();
        $st = $runtime->exec(['/usr/bin/systemctl', 'is-active', $unit], null, 10);
        if (trim($st->stdout) === 'active') {
            Systemd::control($runtime, 'reload', $unit);

            return $unit;
        }

        return '';
    }
}
