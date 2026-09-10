<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

use AzerioidPanel\Broker\Os\DistroPaths;

final class Config
{
    public const CADDYFILE = '/etc/caddy/Caddyfile';
    public const CADDY_CONFD = '/etc/caddy/conf.d';
    public const APACHE2_CONF = '/etc/apache2/apache2.conf';
    public const HTTPD_CONF = '/etc/httpd/conf/httpd.conf';

    public string $wwwRoot = '/data/www';
    public string $caddyConfD = self::CADDY_CONFD;
    public string $caddyfile = self::CADDYFILE;
    public string $caddyBin = '/usr/bin/caddy';
    public string $stack = 'lcmp';
    public string $webServer = 'caddy';
    public string $webService = 'caddy';
    public string $siteWebServer = '';
    public string $vhostDir = '/etc/caddy/conf.d';
    public string $vhostAvailableDir = '';
    public string $vhostFormat = 'caddyfile';
    public string $apacheCtl = '/usr/sbin/apachectl';
    public string $webLogDir = '/var/log/caddy';
    public string $auditLog = '/var/log/azerioid-panel/broker-audit.log';
    public string $mysqlSocket = '/run/mysqld/mysqld.sock';
    public string $mysqlUser = 'azerioid_panel_admin';
    public string $mysqlPassword = '';
    public string $databaseEngine = '';
    public string $postgresqlHost = '127.0.0.1';
    public int $postgresqlPort = 5432;
    public string $postgresqlUser = 'azerioid_panel_admin';
    public string $postgresqlPassword = '';
    public string $mongodbHost = '127.0.0.1';
    public int $mongodbPort = 27017;
    public string $mongodbUser = 'azerioid_panel_admin';
    public string $mongodbPassword = '';
    public string $phpUser = 'caddy';
    public string $phpGroup = 'caddy';
    public string $mariadbServerCnf = '/etc/mysql/mariadb.conf.d/50-server.cnf';
    public string $panelRoot = '/usr/local/lib/azerioid-panel';

    /** Managed git checkout used for panel self-update (PREFIX itself is not a git tree). */
    public string $panelSourcePath = '/var/lib/azerioid-panel/src';

    public string $panelGitRemote = 'https://github.com/azerioid/azerioid-stack-manager.git';
    public string $artisanPath = '/usr/local/lib/azerioid-panel/web/artisan';
    public string $stagingDir = '/var/lib/azerioid-panel/staging';
    public string $localBackupDir = '/var/lib/azerioid-panel/backups';
    public string $cronDPath = '/etc/cron.d/azerioid-panel';
    public string $webUser = 'caddy';

    /** Apache loopback backend (Caddy reverse_proxy target). */
    public int $apacheBackendPort = 8081;
    /** Nginx loopback backend (Caddy reverse_proxy target). */
    public int $nginxBackendPort = 8082;
    public string $backendBind = '127.0.0.1';

    public string $ttydBin = '/usr/local/bin/ttyd';
    public string $terminalSessionsPath = '/var/lib/azerioid-panel/terminal-sessions.json';
    public string $terminalCaddyRoutesPath = '/var/lib/azerioid-panel/caddy-terminal-routes.conf';
    public int $panelPort = 3169;
    public string $brokerConfigPath = '/etc/azerioid-panel/broker.json';
    public ?string $panelDomain = null;
    public string $panelDomainTlsMode = 'auto';
    public ?string $panelDomainTlsCert = null;
    public ?string $panelDomainTlsKey = null;
    public ?string $panelPublicIp = null;
    public int $terminalIdleSeconds = 1200;
    public int $terminalPortMin = 35000;
    public int $terminalPortMax = 35999;
    /** Per-request file-manager payload cap (read/write/upload). Default 20 MiB. */
    public int $vhostFilesMaxBytes = 20971520;

    public string $panelPhpVersion = '8.4';
    public string $panelFpmSocket = '/run/php/azerioid-panel.sock';
    public string $panelFpmPool = 'azerioid-panel';
    public string $panelFpmUnit = 'php8.4-fpm';
    public string $panelRuntimeQueueUnit = 'azerioid-panel-queue.service';
    public string $registryComponentsPath = '/usr/local/lib/azerioid-panel/registry/components';
    public string $managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';

    /** ACME account email for certbot DNS-01 (Caddy uses its own ACME account for HTTP-01). Empty → derived as admin@<domain>. */
    public string $acmeEmail = '';

    /** @var list<string> reverse-proxy / operator-protected vhosts (detected at install) */
    public array $readonlyVhosts = [];

    /** @var list<string> */
    public array $protectedDatabases = ['information_schema', 'mysql', 'performance_schema', 'sys', 'lacmp_panel'];

    /** @var array<string,string> log key => path */
    public array $logPaths = [
        'caddy' => '/var/log/caddy/access_azerioid-panel.log',
        'mariadb' => '/var/log/mysql/error.log',
        'php-fpm' => '/var/log/php8.4-fpm.log',
        'php-slow' => '/var/log/www-slow.log',
        'panel-audit' => '/var/log/azerioid-panel/broker-audit.log',
        'auth' => '/var/log/auth.log',
        'auth-secure' => '/var/log/secure',
        'auth-syslog' => '/var/log/syslog',
    ];

    /** @var list<string> */
    public array $controllableServices = ['caddy', 'mariadb'];

    /** @var list<string> extra systemd units or host:port to observe (default empty) */
    public array $observedServices = [];

    public static function load(string $path, Runtime $runtime): self
    {
        $cfg = new self();
        if (!$runtime->fileExists($path)) {
            return $cfg;
        }
        $raw = $runtime->readFile($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new BrokerException('Broker config is not valid JSON.', 1);
        }
        $cfg->wwwRoot = (string) ($data['paths']['www_root'] ?? $cfg->wwwRoot);
        $cfg->caddyConfD = (string) ($data['paths']['caddy_confd'] ?? $cfg->caddyConfD);
        $cfg->caddyfile = self::sanitizeExecPath(
            (string) ($data['paths']['caddyfile'] ?? self::CADDYFILE),
            self::CADDYFILE
        );
        $cfg->caddyBin = (string) ($data['paths']['caddy_bin'] ?? $cfg->caddyBin);
        $cfg->stack = (string) ($data['stack'] ?? $cfg->stack);
        if ($cfg->stack === 'lacmp') {
            $cfg->stack = 'lcmp';
        }
        $cfg->webServer = (string) ($data['web_server'] ?? $cfg->webServer);
        $cfg->webService = (string) ($data['web_service'] ?? $cfg->webService);
        $cfg->siteWebServer = (string) ($data['site_web_server'] ?? $cfg->siteWebServer);
        $cfg->vhostFormat = (string) ($data['vhost_format'] ?? $cfg->vhostFormat);
        $cfg->apacheCtl = (string) ($data['paths']['apache_ctl'] ?? $cfg->apacheCtl);
        if ($cfg->stack === 'lamp') {
            $cfg->webServer = (string) ($data['web_server'] ?? 'apache');
            $cfg->vhostFormat = (string) ($data['vhost_format'] ?? 'apache');
        } else {
            $cfg->vhostDir = (string) ($data['paths']['vhost_dir'] ?? $cfg->caddyConfD);
            $cfg->vhostAvailableDir = (string) ($data['paths']['vhost_available'] ?? '');
            $cfg->webLogDir = (string) ($data['paths']['web_log_dir'] ?? $cfg->webLogDir);
        }
        $cfg->auditLog = (string) ($data['paths']['audit_log'] ?? $cfg->auditLog);
        $cfg->mariadbServerCnf = (string) ($data['paths']['mariadb_server_cnf'] ?? $cfg->mariadbServerCnf);
        $cfg->panelRoot = (string) ($data['paths']['panel_root'] ?? $cfg->panelRoot);
        $cfg->panelSourcePath = (string) ($data['paths']['panel_source'] ?? $cfg->panelSourcePath);
        $cfg->panelGitRemote = (string) ($data['paths']['panel_git_remote'] ?? $cfg->panelGitRemote);
        $cfg->registryComponentsPath = (string) ($data['paths']['registry_components'] ?? $cfg->panelRoot.'/registry/components');
        $cfg->managedComponentsPath = (string) ($data['paths']['managed_components'] ?? $cfg->managedComponentsPath);
        $cfg->artisanPath = (string) ($data['paths']['artisan'] ?? $cfg->artisanPath);
        $cfg->stagingDir = (string) ($data['paths']['staging_dir'] ?? $cfg->stagingDir);
        $cfg->localBackupDir = (string) ($data['paths']['local_backup_dir'] ?? $cfg->localBackupDir);
        $cfg->cronDPath = (string) ($data['paths']['cron_d'] ?? $cfg->cronDPath);
        $cfg->ttydBin = (string) ($data['paths']['ttyd_bin'] ?? $cfg->ttydBin);
        $cfg->terminalSessionsPath = (string) ($data['paths']['terminal_sessions'] ?? $cfg->terminalSessionsPath);
        $cfg->terminalCaddyRoutesPath = (string) ($data['paths']['terminal_caddy_routes'] ?? $cfg->terminalCaddyRoutesPath);
        $cfg->panelPort = (int) ($data['panel_port'] ?? $cfg->panelPort);
        $cfg->brokerConfigPath = (string) ($data['paths']['broker_json'] ?? $cfg->brokerConfigPath);
        if (isset($data['panel']) && is_array($data['panel'])) {
            $panel = $data['panel'];
            $dom = strtolower(trim((string) ($panel['domain'] ?? '')));
            $cfg->panelDomain = $dom !== '' ? $dom : null;
            try {
                $cfg->panelDomainTlsMode = Tls\TlsMode::normalize((string) ($panel['tls_mode'] ?? $cfg->panelDomainTlsMode));
            } catch (BrokerException) {
                $cfg->panelDomainTlsMode = 'auto';
            }
            $cert = trim((string) ($panel['tls_cert'] ?? ''));
            $key = trim((string) ($panel['tls_key'] ?? ''));
            $cfg->panelDomainTlsCert = $cert !== '' ? $cert : null;
            $cfg->panelDomainTlsKey = $key !== '' ? $key : null;
            $ip = trim((string) ($panel['public_ip'] ?? ''));
            $cfg->panelPublicIp = $ip !== '' ? $ip : null;
        }
        $cfg->terminalIdleSeconds = (int) ($data['terminal_idle_seconds'] ?? $cfg->terminalIdleSeconds);
        if (isset($data['terminal_ports']) && is_array($data['terminal_ports'])) {
            $cfg->terminalPortMin = (int) ($data['terminal_ports']['min'] ?? $cfg->terminalPortMin);
            $cfg->terminalPortMax = (int) ($data['terminal_ports']['max'] ?? $cfg->terminalPortMax);
        }
        if (isset($data['vhost_files_max_bytes'])) {
            $max = (int) $data['vhost_files_max_bytes'];
            if ($max >= 1024) {
                $cfg->vhostFilesMaxBytes = $max;
            }
        }
        $cfg->webUser = (string) ($data['web_user'] ?? $cfg->webUser);
        $cfg->phpUser = $cfg->webUser;
        $cfg->phpGroup = $cfg->webUser;
        if (isset($data['panel_runtime']) && is_array($data['panel_runtime'])) {
            $rt = $data['panel_runtime'];
            $cfg->panelPhpVersion = (string) ($rt['php_version'] ?? $cfg->panelPhpVersion);
            $cfg->panelFpmSocket = (string) ($rt['fpm_socket'] ?? $cfg->panelFpmSocket);
            $cfg->panelFpmPool = (string) ($rt['fpm_pool'] ?? $cfg->panelFpmPool);
            $cfg->panelFpmUnit = (string) ($rt['fpm_unit'] ?? $cfg->panelFpmUnit);
            $cfg->panelRuntimeQueueUnit = (string) ($rt['queue_unit'] ?? $cfg->panelRuntimeQueueUnit);
        }
        $cfg->mysqlSocket = (string) ($data['mariadb']['socket'] ?? $cfg->mysqlSocket);
        $cfg->mysqlUser = (string) ($data['mariadb']['user'] ?? $cfg->mysqlUser);
        $cfg->mysqlPassword = (string) ($data['mariadb']['password'] ?? $cfg->mysqlPassword);
        if (isset($data['database']) && is_array($data['database'])) {
            $db = $data['database'];
            $cfg->databaseEngine = (string) ($db['engine'] ?? $cfg->databaseEngine);
            if (isset($db['mariadb']) && is_array($db['mariadb'])) {
                $cfg->mysqlSocket = (string) ($db['mariadb']['socket'] ?? $cfg->mysqlSocket);
                $cfg->mysqlUser = (string) ($db['mariadb']['user'] ?? $cfg->mysqlUser);
                $cfg->mysqlPassword = (string) ($db['mariadb']['password'] ?? $cfg->mysqlPassword);
            }
            if (isset($db['postgresql']) && is_array($db['postgresql'])) {
                $pg = $db['postgresql'];
                $cfg->postgresqlHost = (string) ($pg['host'] ?? $cfg->postgresqlHost);
                $cfg->postgresqlPort = (int) ($pg['port'] ?? $cfg->postgresqlPort);
                $cfg->postgresqlUser = (string) ($pg['user'] ?? $cfg->postgresqlUser);
                $cfg->postgresqlPassword = (string) ($pg['password'] ?? $cfg->postgresqlPassword);
            }
        }
        if (isset($data['readonly_vhosts']) && is_array($data['readonly_vhosts'])) {
            $cfg->readonlyVhosts = array_values(array_map('strval', $data['readonly_vhosts']));
        }
        if (isset($data['observed_services']) && is_array($data['observed_services'])) {
            $cfg->observedServices = [];
            foreach ($data['observed_services'] as $entry) {
                $entry = trim((string) $entry);
                if ($entry !== '') {
                    $cfg->observedServices[] = $entry;
                }
            }
        }
        if (isset($data['logs']) && is_array($data['logs'])) {
            $cfg->logPaths = array_merge($cfg->logPaths, $data['logs']);
        }
        if (isset($data['mongodb']) && is_array($data['mongodb'])) {
            $mongo = $data['mongodb'];
            $cfg->mongodbHost = (string) ($mongo['host'] ?? $cfg->mongodbHost);
            $cfg->mongodbPort = (int) ($mongo['port'] ?? $cfg->mongodbPort);
            $cfg->mongodbUser = (string) ($mongo['user'] ?? $cfg->mongodbUser);
            $cfg->mongodbPassword = (string) ($mongo['password'] ?? $cfg->mongodbPassword);
        }
        if (isset($data['acme']) && is_array($data['acme'])) {
            $cfg->acmeEmail = (string) ($data['acme']['email'] ?? $cfg->acmeEmail);
        } elseif (isset($data['acme_email'])) {
            $cfg->acmeEmail = (string) $data['acme_email'];
        }
        if (isset($data['front_router']) && is_array($data['front_router'])) {
            $fr = $data['front_router'];
            if (isset($fr['apache_backend_port'])) {
                $cfg->apacheBackendPort = (int) $fr['apache_backend_port'];
            }
            if (isset($fr['nginx_backend_port'])) {
                $cfg->nginxBackendPort = (int) $fr['nginx_backend_port'];
            }
            if (isset($fr['bind']) && is_string($fr['bind']) && $fr['bind'] !== '') {
                $cfg->backendBind = $fr['bind'];
            }
        }
        if ($cfg->stack === 'lamp') {
            $layout = DistroPaths::for($runtime, $cfg)->apacheSiteLayout();
            $cfg->webService = (string) ($data['web_service'] ?? $layout['web_service']);
            $cfg->vhostDir = (string) ($data['paths']['vhost_dir'] ?? $layout['vhost_dir']);
            $cfg->vhostAvailableDir = (string) ($data['paths']['vhost_available'] ?? $layout['vhost_available']);
            $cfg->webLogDir = (string) ($data['paths']['web_log_dir'] ?? $layout['web_log_dir']);
            $cfg->apacheCtl = (string) ($data['paths']['apache_ctl'] ?? $layout['apache_ctl']);
            $cfg->controllableServices = [$cfg->webService, 'mariadb'];
            $cfg->logPaths['caddy'] = rtrim($cfg->webLogDir, '/') . '/access.log';
        }
        $cfg->resolveLogPaths($runtime);
        return $cfg;
    }

    /**
     * Prefer live-on-disk log paths over Debian-shaped defaults.
     * Explicit broker.json "logs" entries still win when the file exists.
     */
    public function resolveLogPaths(Runtime $runtime): void
    {
        $dir = rtrim($this->webLogDir, '/');
        $caddyCandidates = [
            $this->logPaths['caddy'] ?? '',
            $dir . '/access_azerioid-panel.log',
            $dir . '/access.log',
        ];
        foreach ($caddyCandidates as $candidate) {
            if ($candidate !== '' && $runtime->fileExists($candidate)) {
                $this->logPaths['caddy'] = $candidate;
                break;
            }
        }
        if (!isset($this->logPaths['caddy']) || $this->logPaths['caddy'] === '') {
            $this->logPaths['caddy'] = $dir . '/access_azerioid-panel.log';
        }

        $authCandidates = [
            $this->logPaths['auth'] ?? '',
            $this->logPaths['auth-secure'] ?? '',
            '/var/log/auth.log',
            '/var/log/secure',
            '/var/log/syslog',
        ];
        foreach ($authCandidates as $candidate) {
            if ($candidate !== '' && $runtime->fileExists($candidate)) {
                $this->logPaths['auth'] = $candidate;
                break;
            }
        }
        $this->logPaths['auth-secure'] = $this->logPaths['auth-secure'] ?? '/var/log/secure';

        $phpVer = $this->panelPhpVersion !== '' ? $this->panelPhpVersion : '8.4';
        $nodot = str_replace('.', '', $phpVer);
        $phpCandidates = [
            $this->logPaths['php-fpm'] ?? '',
            "/var/log/php{$phpVer}-fpm.log",
            '/var/log/php-fpm/error.log',
            '/var/log/php-fpm.log',
            "/var/opt/remi/php{$nodot}/log/php-fpm/error.log",
            '/var/log/azerioid-panel/php-fpm.log',
            '/var/log/www-error.log',
        ];
        foreach ($phpCandidates as $candidate) {
            if ($candidate !== '' && $runtime->fileExists($candidate)) {
                $this->logPaths['php-fpm'] = $candidate;
                break;
            }
        }

        $mariaCandidates = [
            $this->logPaths['mariadb'] ?? '',
            '/var/log/mysql/error.log',
            '/var/log/mariadb/mariadb.log',
            '/var/log/mysqld.log',
        ];
        foreach ($mariaCandidates as $candidate) {
            if ($candidate !== '' && $runtime->fileExists($candidate)) {
                $this->logPaths['mariadb'] = $candidate;
                break;
            }
        }
    }

    public function forApacheBackend(Runtime $runtime): self
    {
        $c = clone $this;
        $c->webServer = 'apache';
        $c->vhostFormat = 'apache';
        $layout = DistroPaths::for($runtime, $c)->apacheSiteLayout();
        $c->webService = $layout['web_service'];
        $c->vhostDir = $layout['vhost_dir'];
        $c->vhostAvailableDir = $layout['vhost_available'];
        $c->webLogDir = $layout['web_log_dir'];
        $c->apacheCtl = $layout['apache_ctl'];
        if ($c->webService === 'httpd' && $c->webUser === 'caddy') {
            $c->webUser = 'apache';
        }

        return $c;
    }

    public function forNginxBackend(Runtime $runtime): self
    {
        $c = clone $this;
        $c->webServer = 'nginx';
        $c->webService = 'nginx';
        $c->vhostFormat = 'nginx';
        $layout = DistroPaths::for($runtime, $c)->nginxSiteLayout();
        $c->webService = $layout['web_service'];
        $c->vhostDir = $layout['vhost_dir'];
        $c->vhostAvailableDir = $layout['vhost_available'];
        $c->webLogDir = $layout['web_log_dir'];

        return $c;
    }

    public function runtimeWithDb(Runtime $runtime): Runtime
    {
        if ($runtime instanceof PosixRuntime) {
            return $runtime->withDatabase($this->mysqlSocket, $this->mysqlUser, $this->mysqlPassword);
        }
        return $runtime;
    }

    /**
     * Distro-correct PHP-FPM unit for a version (php8.4-fpm on Debian/Ubuntu,
     * php83-php-fpm / php-fpm on EL/Remi). Probes loaded units when a runtime
     * is provided so Services/Overview never show phantom Debian names on EL.
     */
    public function phpFpmService(string $version, ?Runtime $runtime = null): string
    {
        $layout = DistroPaths::for($runtime ?? new FakeRuntime(), $this);
        if ($runtime !== null) {
            return $layout->phpFpmUnit($version);
        }

        return $layout->phpFpmUnitCandidates($version)[0];
    }

    /** @return list<string> */
    public function phpFpmServiceCandidates(string $version, ?Runtime $runtime = null): array
    {
        return DistroPaths::for($runtime ?? new FakeRuntime(), $this)->phpFpmUnitCandidates($version);
    }

    public function resolveMariadbServerCnf(?Runtime $runtime = null): ?string
    {
        if ($runtime !== null) {
            return DistroPaths::for($runtime, $this)->mariadbServerCnf();
        }
        $candidates = array_values(array_unique(array_filter([
            $this->mariadbServerCnf,
            ...DistroPaths::probeList('mariadb.server_cnf'),
        ])));
        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function phpFpmSocket(string $version, ?Runtime $runtime = null): string
    {
        $layout = DistroPaths::for($runtime ?? new FakeRuntime(), $this);
        if ($runtime !== null) {
            return 'unix/' . $layout->phpFpmUnixSocket($version);
        }
        foreach ($layout->phpFpmSocketPaths($version) as $path) {
            if ($path !== '' && is_file($path)) {
                return 'unix/' . $path;
            }
        }

        return 'unix/' . $layout->phpFpmUnixSocket($version);
    }

    public function phpFpmUnixPath(string $version, ?Runtime $runtime = null): string
    {
        $sock = $this->phpFpmSocket($version, $runtime);

        return preg_replace('#^unix/+#', '/', $sock) ?? DistroPaths::for($runtime ?? new FakeRuntime(), $this)->phpFpmUnixSocket($version);
    }

    public function phpIniPath(string $version, ?Runtime $runtime = null): string
    {
        $layout = DistroPaths::for($runtime ?? new FakeRuntime(), $this);
        if ($runtime !== null) {
            return $layout->phpIni($version) ?? ($layout->phpIniCandidates($version)[0] ?? '/etc/php.ini');
        }
        foreach ($layout->phpIniCandidates($version) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $layout->phpIniCandidates($version)[0] ?? '/etc/php.ini';
    }

    public function controllableServiceList(Runtime $runtime): array
    {
        $list = $this->controllableServices;
        $managed = \AzerioidPanel\Broker\Component\ManagedManifest::load($runtime, $this->managedComponentsPath);
        foreach ($managed->units() as $unit) {
            $list[] = $unit;
        }
        foreach ($runtime->phpVersions() as $ver) {
            $unit = $this->phpFpmService($ver, $runtime);
            if (Systemd::loadState($runtime, $unit) === 'not-found') {
                continue;
            }
            $list[] = $unit;
        }
        return array_values(array_unique($list));
    }

    /** Paths passed to exec() must be a single token (no word-splitting). */
    public static function sanitizeExecPath(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('/\s/', $path) === 1) {
            return $fallback;
        }
        return $path;
    }

    public static function assertMainConfigPath(string $path, string $expected): void
    {
        if ($path !== $expected || preg_match('/\s/', $path) === 1) {
            throw new BrokerException(
                "Web-server main-config path is invalid (got '{$path}'; expected '{$expected}').",
                1
            );
        }
    }
}
