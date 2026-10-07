<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Php;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Os\DistroPaths;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Vhost\VhostUser;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * One PHP-FPM pool per site, running as the site's own account (ADR A55).
 *
 * Until A55 every site's PHP ran in the distro's shared `www` pool as one account (www-data
 * on apt, apache on EL) that had to be able to write every site — so one site's PHP could read
 * and change every other site (the residual A49 recorded). Now each PHP site has a pool
 * `azv-<site>` on the master of its PHP version:
 *
 *  - `user`/`group` = the site's identity (az-vh-*), which owns the site's files (A49);
 *  - its socket is `<web server>:<site group> 0660`: the web server connects, and no other
 *    site's PHP can — connecting to another site's socket would run code as that site;
 *  - `pm = ondemand` (operator decision): no idle workers, at most five, gone after 10 s idle,
 *    so twenty quiet sites cost nothing on a 1 GB host;
 *  - its own session directory, and `open_basedir` = the site's top directory, /tmp, that
 *    session directory and the shared PHP libraries — on by default, switchable per site
 *    (operator decision); `disable_functions` left at distro defaults (PHP runs as the site, so
 *    exec() grants nothing its Terminal does not).
 *
 * The socket name carries the PHP version, so during a version change the old pool keeps
 * serving until the web server has moved; pools a site no longer uses are pruned afterwards.
 */
final class SitePool
{
    public const PREFIX = 'azv-';

    public const SESSIONS = '/var/lib/azerioid-php-sessions';

    public const SETTINGS = '/var/lib/azerioid-panel/site-php';

    public const MAX_CHILDREN = 5;

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public static function name(string $domain): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)), '-');
        if ($slug === '') {
            throw new BrokerException('Cannot derive a PHP pool name for this domain.', 2);
        }

        return self::PREFIX . (strlen($slug) > 60 ? substr(hash('sha256', $domain), 0, 12) . '-' . substr($slug, 0, 47) : $slug);
    }

    public function poolFile(string $domain, string $version): string
    {
        return $this->paths()->phpFpmPoolDir($version) . '/' . self::name($domain) . '.conf';
    }

    public function socket(string $domain, string $version): string
    {
        return dirname($this->paths()->phpFpmUnixSocket($version)) . '/' . self::name($domain) . '-' . $version . '.sock';
    }

    public function sessionDir(string $domain): string
    {
        return self::SESSIONS . '/' . substr(self::name($domain), strlen(self::PREFIX));
    }

    public function exists(string $domain, string $version): bool
    {
        return $this->runtime->fileExists($this->poolFile($domain, $version));
    }

    /**
     * `isolated` is false only when the migration had to put a site back on the shared pool
     * (its HTTP answer broke); the reason is kept for the operator.
     *
     * @return array{open_basedir: bool, isolated: bool, reason: ?string}
     */
    public function settings(string $domain): array
    {
        $path = $this->settingsPath($domain);
        $d = $this->runtime->fileExists($path) ? json_decode($this->runtime->readFile($path), true) : null;
        $d = is_array($d) ? $d : [];

        return [
            'open_basedir' => ($d['open_basedir'] ?? true) !== false,
            'isolated' => ($d['isolated'] ?? true) !== false,
            'reason' => isset($d['reason']) && is_string($d['reason']) ? $d['reason'] : null,
            // A80: per-site PHP resource caps. null = panel default.
            'php_memory_limit_mb' => isset($d['php_memory_limit_mb']) && is_int($d['php_memory_limit_mb']) ? $d['php_memory_limit_mb'] : null,
            'max_children' => isset($d['max_children']) && is_int($d['max_children']) ? $d['max_children'] : null,
            // A80 inc2: runtime (Octane/PM2/Docker) caps. memory in MB; cpu in percent
            // of one core (100 = one core). null = uncapped.
            'memory_mb' => isset($d['memory_mb']) && is_int($d['memory_mb']) ? $d['memory_mb'] : null,
            'cpu_percent' => isset($d['cpu_percent']) && is_int($d['cpu_percent']) ? $d['cpu_percent'] : null,
        ];
    }

    /**
     * A80 inc2: the site's runtime resource caps (memory MB, cpu percent of one
     * core), read from the same per-site settings file. null = uncapped. Octane/PM2/
     * Docker managers call this when building their program command.
     *
     * @return array{memory_mb:?int, cpu_percent:?int}
     */
    public function resourceLimits(string $domain): array
    {
        $s = $this->settings($domain);

        return ['memory_mb' => $s['memory_mb'], 'cpu_percent' => $s['cpu_percent']];
    }

    /** @param array<string,mixed> $changes */
    public function saveSettings(string $domain, array $changes): array
    {
        $next = array_merge($this->settings($domain), $changes);
        if ($next['isolated']) {
            $next['reason'] = null;
        }
        $this->runtime->mkdir(self::SETTINGS, 0750);
        $this->runtime->writeFile($this->settingsPath($domain), json_encode($next, JSON_UNESCAPED_SLASHES) . "\n", 0640);

        return $next;
    }

    /**
     * The socket a vhost renderer points a PHP site at. The site's own pool, created or
     * refreshed here, unless the site cannot have one yet (not root, identity still on the
     * pre-A49 shared group, or put back on the shared pool by a failed migration).
     */
    public function socketFor(string $domain, string $version, string $root): string
    {
        if ($this->runtime->getuid() !== 0 || !$this->settings($domain)['isolated']) {
            return $this->paths()->phpFpmUnixSocket($version);
        }
        $user = VhostUser::username($domain);
        if (!VhostUser::userExists($this->runtime, $user)) {
            // A new vhost renders before the driver creates its identity; the pool needs it.
            if (!$this->runtime->isDir($root)) {
                $this->runtime->mkdir($root, 0755);
            }
            VhostUser::ensure($this->runtime, $this->config, $domain, $root);
        }
        if (VhostUser::docrootGroup($this->runtime, $domain) === VhostUser::LEGACY_GROUP) {
            return $this->paths()->phpFpmUnixSocket($version);
        }

        return $this->ensure($domain, $version, $root);
    }

    /**
     * Write the site's pool for this version and wait for its socket. The first time also
     * gives the site's files to its identity (they may be the old shared account's) while
     * keeping group write, so the shared pool can still serve the site if it goes back.
     * A pool php-fpm refuses is taken out again before any reload: one bad pool file would
     * otherwise stop the master every other site on that version runs in.
     */
    public function ensure(string $domain, string $version, string $root): string
    {
        $pool = $this->poolFile($domain, $version);
        $socket = $this->socket($domain, $version);
        $previous = $this->runtime->fileExists($pool) ? $this->runtime->readFile($pool) : null;
        $user = VhostUser::username($domain);
        $group = VhostUser::docrootGroup($this->runtime, $domain);
        // A62: if another site's docroot lives under this top, the top is not the
        // isolation unit (A49-E1) — confine the pool (open_basedir and the one-time
        // ownership handover) to this site's own docroot so it cannot take the
        // sibling's tree.
        $top = $this->topOf($root);
        if (VhostUser::topSharedByAnotherSite($this->runtime, $this->config, $domain, $top)) {
            $top = rtrim($root, '/');
        }
        $body = $this->render($domain, $version, $user, $group, $top);
        if ($previous === $body && $this->runtime->fileExists($socket)) {
            return $socket;
        }

        $this->runtime->writeFile($pool, $body, 0644);
        $test = $this->configTest($version);
        if ($test !== null) {
            $this->restore($pool, $previous);
            throw new BrokerException("php-fpm {$version} refused the pool for {$domain}; nothing was reloaded. {$test}", 1);
        }
        // Files the shared pool wrote (uploads, caches) are the shared account's; the site's own
        // PHP could not change them. A new pool file is the moment to take them over.
        if ($previous === null && $this->runtime->isDir($top)) {
            $this->runtime->exec(['/usr/bin/chown', '-R', '-h', $user . ':' . $group, $top], null, 600);
            $this->runtime->exec(['/usr/bin/chmod', '-R', 'g+rwX', $top], null, 600);
        }
        $this->ensureSessionDir($domain, $user, $group);
        $this->reload($version);
        if (!$this->waitForSocket($socket)) {
            $this->restore($pool, $previous);
            $this->reload($version);
            throw new BrokerException("php-fpm {$version} did not open {$socket}; the pool was taken out again. Check: journalctl -u " . $this->paths()->phpFpmUnit($version), 1);
        }

        return $socket;
    }

    /**
     * Take out the pools a site no longer uses: every version but $keep (null = all of them,
     * the site is gone or no longer runs PHP-FPM).
     *
     * @return list<string> removed pool files
     */
    public function prune(string $domain, ?string $keep): array
    {
        $removed = [];
        foreach ($this->runtime->phpVersions() as $version) {
            if ($version !== $keep && $this->exists($domain, $version)) {
                $this->runtime->deleteFile($this->poolFile($domain, $version));
                $this->reload($version);
                $removed[] = $this->poolFile($domain, $version);
            }
        }

        return $removed;
    }

    /** Remove a site's pools (every PHP version), sessions and settings: the site is deleted. */
    public function remove(string $domain): void
    {
        $this->prune($domain, null);
        $sessions = $this->sessionDir($domain);
        if ($this->runtime->isDir($sessions)) {
            $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $sessions], null, 60);
        }
        if ($this->runtime->fileExists($this->settingsPath($domain))) {
            $this->runtime->deleteFile($this->settingsPath($domain));
        }
    }

    /** Every azv-* pool file on the host, as [domain-slug => [version => path]]. */
    public function poolFiles(): array
    {
        $out = [];
        foreach ($this->runtime->phpVersions() as $version) {
            foreach ($this->runtime->glob($this->paths()->phpFpmPoolDir($version) . '/' . self::PREFIX . '*.conf') as $path) {
                $out[basename($path, '.conf')][$version] = $path;
            }
        }

        return $out;
    }

    /** Whether any of the site's web server files already point at its own socket. */
    public function served(string $domain, string $version): bool
    {
        $socket = $this->socket($domain, $version);
        foreach (WebServers::for($this->config)->backupPaths($this->config) as $path) {
            $file = $this->runtime->isDir($path) ? rtrim($path, '/') . '/' . $domain . '.conf' : null;
            if ($file !== null && $this->runtime->fileExists($file) && str_contains($this->runtime->readFile($file), $socket)) {
                return true;
            }
        }

        return false;
    }

    public function render(string $domain, string $version, string $user, string $group, string $top): string
    {
        $name = self::name($domain);
        $socket = $this->socket($domain, $version);
        $sessions = $this->sessionDir($domain);
        $terminate = SitePhpTimeouts::REQUEST_TERMINATE_SECONDS;
        $maxExec = SitePhpTimeouts::MAX_EXECUTION_SECONDS;
        $settings = $this->settings($domain);
        $max = $settings['max_children'] ?? self::MAX_CHILDREN;
        $webUser = $this->config->webUser !== '' ? $this->config->webUser : 'caddy';
        $basedir = $settings['open_basedir']
            ? "php_admin_value[open_basedir] = {$top}/:/tmp/:{$sessions}/:/usr/share/php/:/usr/share/pear/\n"
            : "; open_basedir switched off for this site\n";
        // A80: per-request memory cap. php_admin_value can't be raised by the app at runtime.
        $memory = $settings['php_memory_limit_mb'] !== null
            ? "php_admin_value[memory_limit] = {$settings['php_memory_limit_mb']}M\n"
            : '';

        return <<<INI
; AZERIOID Stack Manager — PHP pool for {$domain} (ADR A55). Managed; edits are overwritten.
[{$name}]
user = {$user}
group = {$group}
listen = {$socket}
listen.owner = {$webUser}
listen.group = {$group}
listen.mode = 0660
pm = ondemand
pm.max_children = {$max}
pm.process_idle_timeout = 10s
pm.max_requests = 500
request_terminate_timeout = {$terminate}
chdir = /
php_admin_value[max_execution_time] = {$maxExec}
php_admin_value[session.save_path] = {$sessions}
php_admin_value[session.gc_probability] = 1
php_admin_value[session.gc_divisor] = 100
{$memory}{$basedir}
INI;
    }

    private function ensureSessionDir(string $domain, string $user, string $group): void
    {
        if (!$this->runtime->isDir(self::SESSIONS)) {
            $this->runtime->mkdir(self::SESSIONS, 0711);
        }
        $this->runtime->chmod(self::SESSIONS, 0711);
        $dir = $this->sessionDir($domain);
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0700);
        }
        $this->runtime->chown($dir, $user, $group);
        $this->runtime->chmod($dir, 0700);
        // EL: php-fpm runs as httpd_t, which may not write var_lib_t. Label the tree.
        if ($this->runtime->fileExists('/usr/sbin/semanage')) {
            $spec = self::SESSIONS . '(/.*)?';
            $add = $this->runtime->exec(['/usr/sbin/semanage', 'fcontext', '-a', '-t', 'httpd_sys_rw_content_t', $spec], null, 60);
            if (!$add->ok()) {
                $this->runtime->exec(['/usr/sbin/semanage', 'fcontext', '-m', '-t', 'httpd_sys_rw_content_t', $spec], null, 60);
            }
            $this->runtime->exec(['/usr/sbin/restorecon', '-R', self::SESSIONS], null, 60);
        }
    }

    /** null when php-fpm accepts its configuration (or there is no binary to ask), else why not. */
    private function configTest(string $version): ?string
    {
        foreach ([
            '/usr/sbin/php-fpm' . $version,
            '/opt/remi/php' . str_replace('.', '', $version) . '/root/usr/sbin/php-fpm',
            '/usr/sbin/php-fpm',
        ] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                $r = $this->runtime->exec([$bin, '-t'], null, 30);

                return $r->ok() ? null : trim($r->stderr . ' ' . $r->stdout);
            }
        }

        return null;
    }

    private function restore(string $pool, ?string $previous): void
    {
        if ($previous === null) {
            $this->runtime->deleteFile($pool);
        } else {
            $this->runtime->writeFile($pool, $previous, 0644);
        }
    }

    private function reload(string $version): void
    {
        $unit = $this->paths()->phpFpmUnit($version);
        // Graceful: running requests finish, and workers of other sites are replaced as they end.
        $r = $this->runtime->exec(['/usr/bin/systemctl', 'reload', $unit], null, 60);
        if (!$r->ok()) {
            $this->runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 60);
        }
    }

    private function waitForSocket(string $socket): bool
    {
        for ($i = 0; $i < 40; $i++) {
            if ($this->runtime->fileExists($socket)) {
                return true;
            }
            $this->runtime->exec(['/bin/sleep', '0.25'], null, 5);
        }

        return false;
    }

    private function topOf(string $root): string
    {
        $www = rtrim($this->config->wwwRoot, '/') . '/';
        $root = rtrim($root, '/');
        if (!str_starts_with($root . '/', $www)) {
            return $root;
        }

        return $www . explode('/', substr($root, strlen($www)))[0];
    }

    private function settingsPath(string $domain): string
    {
        return self::SETTINGS . '/' . substr(self::name($domain), strlen(self::PREFIX)) . '.json';
    }

    private function paths(): DistroPaths
    {
        return DistroPaths::for($this->runtime, $this->config);
    }
}
