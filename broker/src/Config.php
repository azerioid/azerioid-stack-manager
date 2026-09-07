<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

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
    public string $artisanPath = '/usr/local/lib/azerioid-panel/web/artisan';
    public string $stagingDir = '/var/lib/azerioid-panel/staging';
    public string $cronDPath = '/etc/cron.d/azerioid-panel';
    public string $webUser = 'caddy';

    public string $ttydBin = '/usr/local/bin/ttyd';
    public string $terminalSessionsPath = '/var/lib/azerioid-panel/terminal-sessions.json';
    public string $terminalCaddyRoutesPath = '/var/lib/azerioid-panel/caddy-terminal-routes.conf';
    public int $panelPort = 3169;
    public int $terminalIdleSeconds = 1200;
    public int $terminalPortMin = 35000;
    public int $terminalPortMax = 35999;

    public string $panelPhpVersion = '8.4';
    public string $panelFpmSocket = '/run/php/azerioid-panel.sock';
    public string $panelFpmPool = 'azerioid-panel';
    public string $panelFpmUnit = 'php8.4-fpm';
    public string $panelRuntimeQueueUnit = 'azerioid-panel-queue.service';
    public string $registryComponentsPath = '/usr/local/lib/azerioid-panel/registry/components';
    public string $managedComponentsPath = '/var/lib/azerioid-panel/managed-components.json';

    /** ACME account email for certbot (HTTP-01 / DNS-01). Empty → derived as admin@<domain>. */
    public string $acmeEmail = '';

    /** @var list<string> reverse-proxy / operator-protected vhosts (detected at install) */
    public array $readonlyVhosts = [];

    /** @var list<string> */
    public array $protectedDatabases = ['information_schema', 'mysql', 'performance_schema', 'sys', 'lacmp_panel'];

    /** @var array<string,string> log key => path */
    public array $logPaths = [
        'caddy' => '/var/log/caddy/access.log',
        'mariadb' => '/var/log/mysql/error.log',
        'php-fpm' => '/var/log/www-error.log',
        'php-slow' => '/var/log/www-slow.log',
        'panel-audit' => '/var/log/azerioid-panel/broker-audit.log',
        'auth' => '/var/log/auth.log',
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
            $cfg->webService = (string) ($data['web_service'] ?? 'apache2');
            $cfg->vhostFormat = (string) ($data['vhost_format'] ?? 'apache');
            $cfg->vhostDir = (string) ($data['paths']['vhost_dir'] ?? '/etc/apache2/sites-enabled');
            $cfg->vhostAvailableDir = (string) ($data['paths']['vhost_available'] ?? '/etc/apache2/sites-available');
            $cfg->webLogDir = (string) ($data['paths']['web_log_dir'] ?? ($cfg->webService === 'httpd' ? '/var/log/httpd' : '/var/log/apache2'));
            $cfg->controllableServices = [$cfg->webService, 'mariadb'];
            $cfg->logPaths['caddy'] = rtrim($cfg->webLogDir, '/') . '/access.log';
        } else {
            $cfg->vhostDir = (string) ($data['paths']['vhost_dir'] ?? $cfg->caddyConfD);
            $cfg->vhostAvailableDir = (string) ($data['paths']['vhost_available'] ?? '');
            $cfg->webLogDir = (string) ($data['paths']['web_log_dir'] ?? $cfg->webLogDir);
        }
        $cfg->auditLog = (string) ($data['paths']['audit_log'] ?? $cfg->auditLog);
        $cfg->mariadbServerCnf = (string) ($data['paths']['mariadb_server_cnf'] ?? $cfg->mariadbServerCnf);
        $cfg->panelRoot = (string) ($data['paths']['panel_root'] ?? $cfg->panelRoot);
        $cfg->registryComponentsPath = (string) ($data['paths']['registry_components'] ?? $cfg->panelRoot.'/registry/components');
        $cfg->managedComponentsPath = (string) ($data['paths']['managed_components'] ?? $cfg->managedComponentsPath);
        $cfg->artisanPath = (string) ($data['paths']['artisan'] ?? $cfg->artisanPath);
        $cfg->stagingDir = (string) ($data['paths']['staging_dir'] ?? $cfg->stagingDir);
        $cfg->cronDPath = (string) ($data['paths']['cron_d'] ?? $cfg->cronDPath);
        $cfg->ttydBin = (string) ($data['paths']['ttyd_bin'] ?? $cfg->ttydBin);
        $cfg->terminalSessionsPath = (string) ($data['paths']['terminal_sessions'] ?? $cfg->terminalSessionsPath);
        $cfg->terminalCaddyRoutesPath = (string) ($data['paths']['terminal_caddy_routes'] ?? $cfg->terminalCaddyRoutesPath);
        $cfg->panelPort = (int) ($data['panel_port'] ?? $cfg->panelPort);
        $cfg->terminalIdleSeconds = (int) ($data['terminal_idle_seconds'] ?? $cfg->terminalIdleSeconds);
        if (isset($data['terminal_ports']) && is_array($data['terminal_ports'])) {
            $cfg->terminalPortMin = (int) ($data['terminal_ports']['min'] ?? $cfg->terminalPortMin);
            $cfg->terminalPortMax = (int) ($data['terminal_ports']['max'] ?? $cfg->terminalPortMax);
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
        return $cfg;
    }

    public function runtimeWithDb(Runtime $runtime): Runtime
    {
        if ($runtime instanceof PosixRuntime) {
            return $runtime->withDatabase($this->mysqlSocket, $this->mysqlUser, $this->mysqlPassword);
        }
        return $runtime;
    }

    public function phpFpmService(string $version): string
    {
        return 'php' . $version . '-fpm';
    }

    public function phpFpmSocket(string $version, ?Runtime $runtime = null): string
    {
        $paths = [
            '/run/php/php-fpm.sock',
            "/run/php/php{$version}-fpm.sock",
            '/run/php-fpm/www.sock',
        ];
        foreach ($paths as $path) {
            if (str_contains($path, 'azerioid-panel')) {
                continue;
            }
            $exists = $runtime !== null ? $runtime->fileExists($path) : is_file($path);
            if ($exists) {
                return 'unix/' . $path;
            }
        }
        return "unix//run/php/php{$version}-fpm.sock";
    }

    public function phpFpmUnixPath(string $version, ?Runtime $runtime = null): string
    {
        $sock = $this->phpFpmSocket($version, $runtime);
        return preg_replace('#^unix/+#', '/', $sock) ?? "/run/php/php{$version}-fpm.sock";
    }

    public function phpIniPath(string $version): string
    {
        return "/etc/php/{$version}/fpm/php.ini";
    }

    public function controllableServiceList(Runtime $runtime): array
    {
        $list = $this->controllableServices;
        $managed = \AzerioidPanel\Broker\Component\ManagedManifest::load($runtime, $this->managedComponentsPath);
        foreach ($managed->units() as $unit) {
            $list[] = $unit;
        }
        foreach ($runtime->phpVersions() as $ver) {
            $list[] = $this->phpFpmService($ver);
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
