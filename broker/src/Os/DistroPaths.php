<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Os;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;

/**
 * Single resolver for OS-varying paths, units, and sockets.
 *
 * Call sites must not interpolate Debian/EL names. Prefer:
 *  1. registry `distros.{family}.paths` / `unit_name`
 *  2. live first-existing / loaded-unit probes from this class
 */
final class DistroPaths
{
    /** @var array<string, list<string>> */
    private const PROBE = [
        'mariadb.server_cnf' => [
            '/etc/mysql/mariadb.conf.d/50-server.cnf',
            '/etc/my.cnf.d/mariadb-server.cnf',
            '/etc/my.cnf.d/server.cnf',
        ],
        'mariadb.socket' => [
            '/run/mysqld/mysqld.sock',
            '/var/lib/mysql/mysql.sock',
            '/tmp/mysql.sock',
        ],
        'mongodb.config' => [
            '/etc/mongod.conf',
            '/etc/mongodb.conf',
        ],
        'postgresql.conf_globs' => [
            '/etc/postgresql/*/main/postgresql.conf',
            '/var/lib/pgsql/*/data/postgresql.conf',
            '/var/lib/pgsql/data/postgresql.conf',
        ],
        'postgresql.hba_globs' => [
            '/etc/postgresql/*/main/pg_hba.conf',
            '/var/lib/pgsql/*/data/pg_hba.conf',
            '/var/lib/pgsql/data/pg_hba.conf',
        ],
        'apache.ports_conf' => [
            '/etc/apache2/ports.conf',
            '/etc/httpd/conf/httpd.conf',
        ],
        'apache.main_config' => [
            '/etc/apache2/apache2.conf',
            '/etc/httpd/conf/httpd.conf',
        ],
        'apache.conf_available' => [
            '/etc/apache2/conf-available',
        ],
        'apache.conf_enabled' => [
            '/etc/apache2/conf-enabled',
        ],
        'apache.conf_d' => [
            '/etc/httpd/conf.d',
        ],
        'apache.vhost_dir' => [
            '/etc/apache2/sites-enabled',
            '/etc/httpd/conf.d/vhost',
            '/etc/httpd/conf.d',
        ],
        'apache.vhost_available' => [
            '/etc/apache2/sites-available',
            '/etc/httpd/conf.d/vhost',
            '/etc/httpd/conf.d',
        ],
        'apache.web_log_dir' => [
            '/var/log/apache2',
            '/var/log/httpd',
        ],
        'apache.apache_ctl' => [
            '/usr/sbin/apache2ctl',
            '/usr/sbin/apachectl',
            '/usr/sbin/httpd',
        ],
        'nginx.vhost_dir' => [
            '/etc/nginx/sites-enabled',
            '/etc/nginx/conf.d',
        ],
        'nginx.vhost_available' => [
            '/etc/nginx/sites-available',
            '/etc/nginx/conf.d',
        ],
        'nginx.web_log_dir' => [
            '/var/log/nginx',
        ],
        'php.fpm_pool_globs' => [
            '/etc/php/*/fpm/pool.d/*.conf',
            '/etc/opt/remi/php*/php-fpm.d/*.conf',
            '/etc/php-fpm.d/*.conf',
        ],
    ];

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
        private readonly ?OsRelease $os,
    ) {
    }

    public static function for(Runtime $runtime, Config $config): self
    {
        $os = null;
        try {
            $os = OsRelease::detect($runtime);
        } catch (BrokerException) {
        }

        return new self($runtime, $config, $os);
    }

    /** @return list<string> */
    public static function probeList(string $key): array
    {
        return self::PROBE[$key] ?? [];
    }

    public function distroKey(): ?string
    {
        return $this->os?->distroKey;
    }

    public function unitName(string $componentId): ?string
    {
        $unit = $this->distroBlock($componentId)['unit_name'] ?? null;

        return is_string($unit) && $unit !== '' ? $unit : null;
    }

    public function apacheUnit(): string
    {
        $fromRegistry = $this->unitName('apache');
        if ($fromRegistry !== null && Systemd::isLoaded($this->runtime, $fromRegistry)) {
            return $fromRegistry;
        }
        foreach (['httpd', 'apache2'] as $unit) {
            if (Systemd::isLoaded($this->runtime, $unit)) {
                return $unit;
            }
        }

        if ($this->firstExisting(['/usr/sbin/httpd']) !== null && !$this->dirExists('/etc/apache2')) {
            return $fromRegistry ?? 'httpd';
        }

        return $fromRegistry ?? 'apache2';
    }

    public function nginxUnit(): string
    {
        return $this->unitName('nginx') ?? 'nginx';
    }

    /**
     * PHP-FPM systemd unit for a version: registry unit_name, then live loaded units.
     */
    public function phpFpmUnit(string $version): string
    {
        $id = 'php-' . $version;
        $registry = $this->unitName($id);
        if ($registry !== null && Systemd::isLoaded($this->runtime, $registry)) {
            return $registry;
        }
        foreach ($this->phpFpmUnitCandidates($version) as $unit) {
            if (Systemd::isLoaded($this->runtime, $unit)) {
                return $unit;
            }
        }

        return $registry ?? $this->phpFpmUnitCandidates($version)[0];
    }

    /** @return list<string> */
    public function phpFpmUnitCandidates(string $version): array
    {
        $list = [];
        $registry = $this->unitName('php-' . $version);
        if ($registry !== null) {
            $list[] = $registry;
        }
        $nodot = str_replace('.', '', $version);
        $list[] = 'php' . $version . '-fpm';
        $list[] = 'php' . $nodot . '-php-fpm';
        if ($registry === 'php-fpm' || $version === $this->config->panelPhpVersion) {
            $list[] = 'php-fpm';
        }

        return array_values(array_unique($list));
    }

    /** @return list<string> */
    public function reloadableServiceUnits(): array
    {
        $units = [
            $this->config->panelFpmUnit,
            $this->nginxUnit(),
            $this->apacheUnit(),
        ];
        foreach ($this->runtime->phpVersions() as $ver) {
            $units[] = $this->phpFpmUnit($ver);
        }

        return array_values(array_unique(array_filter($units, static fn (string $u): bool => $u !== '')));
    }

    public function mariadbServerCnf(): ?string
    {
        $configured = trim($this->config->mariadbServerCnf);
        $candidates = $this->candidates('mariadb', 'server_cnf');
        if ($configured !== '') {
            array_unshift($candidates, $configured);
        }

        return $this->firstExisting($candidates);
    }

    public function mariadbSocket(): ?string
    {
        return $this->firstExisting($this->candidates('mariadb', 'socket'));
    }

    public function mongodbConfig(): ?string
    {
        return $this->firstExisting($this->candidates('mongodb', 'config'));
    }

    /** @return list<string> */
    public function postgresqlConfGlobs(): array
    {
        return $this->candidates('postgresql', 'conf_globs');
    }

    /** @return list<string> */
    public function postgresqlHbaGlobs(): array
    {
        return $this->candidates('postgresql', 'hba_globs');
    }

    public function phpIni(string $version): ?string
    {
        return $this->firstExisting($this->phpPathCandidates($version, 'php_ini'));
    }

    /** @return list<string> */
    public function phpIniCandidates(string $version): array
    {
        return $this->phpPathCandidates($version, 'php_ini');
    }

    /** @return list<string> unix socket filesystem paths (no unix/ prefix) */
    public function phpFpmSocketPaths(string $version): array
    {
        return $this->phpPathCandidates($version, 'fpm_socket');
    }

    public function phpFpmUnixSocket(string $version): string
    {
        $paths = $this->phpFpmSocketPaths($version);
        $found = $this->firstExisting($paths);

        return $found ?? ($paths[0] ?? '/run/php-fpm/www.sock');
    }

    /** @return list<string> */
    public static function webProcessUserNames(): array
    {
        return ['caddy', 'www-data', 'nginx', 'apache'];
    }

    /** @return list<string> */
    public function webProcessUsers(): array
    {
        return self::webProcessUserNames();
    }

    public function apacheHelper(string $name): ?string
    {
        return $this->firstExisting(match ($name) {
            'a2ensite', 'a2dissite', 'a2enmod', 'a2enconf', 'a2dismod' => ['/usr/sbin/' . $name],
            default => [],
        });
    }

    public function apacheCtlBin(): ?string
    {
        $configured = trim($this->config->apacheCtl);
        $candidates = $this->candidates('apache', 'apache_ctl');
        if ($configured !== '') {
            array_unshift($candidates, $configured);
        }

        return $this->firstExisting($candidates);
    }

    /** @return list<string> */
    public function postgresqlConfFiles(): array
    {
        return $this->expandGlobs($this->postgresqlConfGlobs());
    }

    /** @return list<string> */
    public function postgresqlHbaFiles(): array
    {
        return $this->expandGlobs($this->postgresqlHbaGlobs());
    }

    /** @return list<string> */
    public function postgresqlUnits(): array
    {
        $units = [];
        foreach ($this->postgresqlConfFiles() as $path) {
            if (preg_match('#/postgresql/(\d+)/main/#', $path, $m) === 1) {
                $units[] = 'postgresql@' . $m[1] . '-main';
            }
        }
        $units[] = $this->unitName('postgresql') ?? 'postgresql';

        return array_values(array_unique($units));
    }

    public function nginxConfDDir(): ?string
    {
        if ($this->dirExists('/etc/nginx/conf.d')) {
            return '/etc/nginx/conf.d';
        }
        if ($this->dirExists('/etc/nginx')) {
            return '/etc/nginx/conf.d';
        }

        return null;
    }

    /** @return list<string> */
    public function phpFpmPoolGlobs(): array
    {
        $fromPhp = [];
        foreach ($this->runtime->phpVersions() as $ver) {
            $fromPhp = array_merge($fromPhp, $this->phpPathCandidates($ver, 'fpm_pool_globs'));
        }

        return array_values(array_unique(array_merge($fromPhp, self::PROBE['php.fpm_pool_globs'])));
    }

    /** @return list<string> */
    public function apacheVhostScanDirs(): array
    {
        return $this->existingDirs($this->candidates('apache', 'vhost_available', 'vhost_dir'));
    }

    /** @return list<string> */
    public function nginxVhostScanDirs(): array
    {
        return $this->existingDirs($this->candidates('nginx', 'vhost_available', 'vhost_dir'));
    }

    public function apachePortsConf(): ?string
    {
        return $this->firstExisting($this->candidates('apache', 'ports_conf'));
    }

    /** @return list<string> existing Listen config files */
    public function apacheListenConfigs(): array
    {
        $out = [];
        foreach ($this->candidates('apache', 'ports_conf') as $path) {
            if ($this->runtime->fileExists($path)) {
                $out[] = $path;
            }
        }

        return array_values(array_unique($out));
    }

    public function apacheMainConfig(): ?string
    {
        return $this->firstExisting($this->candidates('apache', 'main_config'));
    }

    public function apacheConfAvailableDir(): ?string
    {
        return $this->firstDir($this->candidates('apache', 'conf_available'));
    }

    public function apacheConfEnabledDir(): ?string
    {
        return $this->firstDir($this->candidates('apache', 'conf_enabled'));
    }

    public function apacheConfDDir(): ?string
    {
        return $this->firstDir($this->candidates('apache', 'conf_d'));
    }

    public function apacheCtl(): ?string
    {
        return $this->firstExisting($this->candidates('apache', 'apache_ctl'));
    }

    /** @return array<string, string> */
    public function apacheSiteLayout(): array
    {
        $block = is_array($this->distroBlock('apache')['paths'] ?? null)
            ? $this->distroBlock('apache')['paths']
            : [];
        $vhostDir = $this->preferredPath($block['vhost_dir'] ?? null, 'apache.vhost_dir');
        $avail = $this->preferredPath($block['vhost_available'] ?? null, 'apache.vhost_available', true);
        $logs = $this->preferredPath($block['web_log_dir'] ?? null, 'apache.web_log_dir');
        $ctl = $this->apacheCtlBin() ?? $this->preferredPath($block['apache_ctl'] ?? null, 'apache.apache_ctl');

        return [
            'web_service' => $this->apacheUnit(),
            'vhost_dir' => $vhostDir,
            'vhost_available' => $avail,
            'web_log_dir' => $logs,
            'apache_ctl' => $ctl,
        ];
    }

    /** @return array<string, string> */
    public function nginxSiteLayout(): array
    {
        $block = is_array($this->distroBlock('nginx')['paths'] ?? null)
            ? $this->distroBlock('nginx')['paths']
            : [];
        $vhostDir = $this->scalarPath($block['vhost_dir'] ?? null);
        $avail = $this->scalarPath($block['vhost_available'] ?? null);
        if ($vhostDir === null) {
            if ($this->dirExists('/etc/nginx/sites-available')) {
                $vhostDir = '/etc/nginx/sites-enabled';
                $avail = '/etc/nginx/sites-available';
            } else {
                $vhostDir = $this->firstDir(self::PROBE['nginx.vhost_dir']) ?? '/etc/nginx/conf.d';
                $avail = $vhostDir === '/etc/nginx/sites-enabled' ? '/etc/nginx/sites-available' : '';
            }
        }

        return [
            'web_service' => $this->nginxUnit(),
            'vhost_dir' => $vhostDir,
            'vhost_available' => $avail ?? '',
            'web_log_dir' => $this->scalarPath($block['web_log_dir'] ?? null)
                ?? $this->firstDir(self::PROBE['nginx.web_log_dir'])
                ?? '/var/log/nginx',
        ];
    }

    /**
     * Registry path for the detected family, even if the file/dir is not on disk yet
     * (component install writes broker.json before creating every directory).
     */
    private function preferredPath(mixed $registryValue, string $probeKey, bool $allowEmpty = false): string
    {
        $fromReg = $this->scalarPath($registryValue);
        if ($fromReg !== null) {
            return $fromReg;
        }
        $existing = $this->firstDir(self::PROBE[$probeKey] ?? []) ?? $this->firstExisting(self::PROBE[$probeKey] ?? []);
        if ($existing !== null) {
            return $existing;
        }
        $fallback = self::PROBE[$probeKey][0] ?? '';

        return $allowEmpty ? ($fallback === '/etc/httpd/conf.d' ? '' : $fallback) : $fallback;
    }

    private function scalarPath(mixed $value): ?string
    {
        $vals = $this->normalizePathValue($value);

        return $vals[0] ?? null;
    }

    /**
     * @param  list<string>  $paths
     */
    public function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if ($path !== '' && $this->runtime->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Expand globs then return existing files. @param list<string> $globs */
    public function expandGlobs(array $globs): array
    {
        $out = [];
        foreach ($globs as $glob) {
            if (str_contains($glob, '*')) {
                foreach ($this->runtime->glob($glob) as $path) {
                    $out[] = $path;
                }
            } elseif ($this->runtime->fileExists($glob)) {
                $out[] = $glob;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> */
    private function distroBlock(string $componentId): array
    {
        $def = $this->definition($componentId);
        if ($def === null) {
            return [];
        }
        $key = $this->distroKey();
        if ($key !== null && is_array($def['distros'][$key] ?? null)) {
            return $def['distros'][$key];
        }

        return [];
    }

    /** @return array<string, mixed>|null */
    private function definition(string $componentId): ?array
    {
        $path = rtrim($this->config->registryComponentsPath, '/') . '/' . $componentId . '.json';
        if (!$this->runtime->fileExists($path)) {
            return null;
        }
        try {
            $data = json_decode($this->runtime->readFile($path), true);
        } catch (BrokerException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @return list<string> */
    private function candidates(string $componentId, string ...$keys): array
    {
        $out = [];
        $block = $this->distroBlock($componentId);
        $paths = is_array($block['paths'] ?? null) ? $block['paths'] : [];
        foreach ($keys as $key) {
            $out = array_merge($out, $this->normalizePathValue($paths[$key] ?? null));
            $probeKey = $componentId . '.' . $key;
            if (isset(self::PROBE[$probeKey])) {
                $out = array_merge($out, self::PROBE[$probeKey]);
            }
        }
        $def = $this->definition($componentId);
        foreach (($def['distros'] ?? []) as $dblock) {
            if (!is_array($dblock) || !is_array($dblock['paths'] ?? null)) {
                continue;
            }
            foreach ($keys as $key) {
                $out = array_merge($out, $this->normalizePathValue($dblock['paths'][$key] ?? null));
            }
        }

        return array_values(array_unique(array_filter($out, static fn (string $p): bool => $p !== '')));
    }

    /** @return list<string> */
    private function phpPathCandidates(string $version, string $key): array
    {
        $id = 'php-' . $version;
        $out = [];
        $block = $this->distroBlock($id);
        $paths = is_array($block['paths'] ?? null) ? $block['paths'] : [];
        foreach ($this->normalizePathValue($paths[$key] ?? null) as $path) {
            $out[] = $this->expand($path, $version);
        }
        $def = $this->definition($id);
        foreach (($def['distros'] ?? []) as $dblock) {
            if (!is_array($dblock['paths'] ?? null)) {
                continue;
            }
            foreach ($this->normalizePathValue($dblock['paths'][$key] ?? null) as $path) {
                $out[] = $this->expand($path, $version);
            }
        }
        $nodot = str_replace('.', '', $version);
        if ($key === 'php_ini') {
            $out[] = "/etc/php/{$version}/fpm/php.ini";
            $out[] = '/etc/php.ini';
            $out[] = "/etc/opt/remi/php{$nodot}/php.ini";
        } elseif ($key === 'fpm_socket') {
            $out[] = "/run/php/php{$version}-fpm.sock";
            $out[] = '/run/php-fpm/www.sock';
            $out[] = '/run/php/php-fpm.sock';
            $out[] = "/var/opt/remi/php{$nodot}/run/php-fpm/www.sock";
        } elseif ($key === 'fpm_pool_globs') {
            $out[] = "/etc/php/{$version}/fpm/pool.d/*.conf";
            $out[] = "/etc/opt/remi/php{$nodot}/php-fpm.d/*.conf";
            $out[] = '/etc/php-fpm.d/*.conf';
        }

        return array_values(array_unique($out));
    }

    private function expand(string $path, string $version): string
    {
        return strtr($path, [
            '{version}' => $version,
            '{nodot}' => str_replace('.', '', $version),
        ]);
    }

    /** @return list<string> */
    private function normalizePathValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param list<string> $dirs */
    private function firstDir(array $dirs): ?string
    {
        foreach ($dirs as $dir) {
            if ($dir !== '' && $this->dirExists($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /** @param list<string> $dirs @return list<string> */
    private function existingDirs(array $dirs): array
    {
        $out = [];
        foreach ($dirs as $dir) {
            if ($dir !== '' && $this->dirExists($dir)) {
                $out[] = $dir;
            }
        }

        return array_values(array_unique($out));
    }

    private function dirExists(string $path): bool
    {
        return $this->runtime->isDir($path);
    }
}
