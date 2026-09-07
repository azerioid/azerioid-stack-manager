<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\Certbot;
use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Vhost\VhostRegistration;
use AzerioidPanel\Broker\Vhost\VhostWelcomePage;

final class ApacheDriver implements WebServerDriver
{
    public const APACHE2_CONF = Config::APACHE2_CONF;
    public const HTTPD_CONF = Config::HTTPD_CONF;
    public const SITES_AVAILABLE = '/etc/apache2/sites-available';
    public const SITES_ENABLED = '/etc/apache2/sites-enabled';
    public const HTTPD_VHOST_DIR = '/etc/httpd/conf.d/vhost';

    public function __construct(private readonly Config $config)
    {
    }

    public function stackName(): string
    {
        return 'lamp';
    }

    public function webServiceName(): string
    {
        return $this->config->webService;
    }

    public function listVhosts(Runtime $runtime, Config $config): array
    {
        $sites = [];
        $seenDomain = [];
        foreach ($this->listVhostFiles($runtime, $config) as $entry) {
            try {
                $contents = $runtime->readFile($entry['path']);
            } catch (\Throwable) {
                continue;
            }
            $parsed = ApacheParser::parseFile($entry['path'], $contents, $config->readonlyVhosts, $entry['enabled']);
            if (!VhostRegistration::isActive($parsed)) {
                continue;
            }
            $key = strtolower((string) $parsed['domain']);
            if ($key === '' || isset($seenDomain[$key])) {
                continue;
            }
            $seenDomain[$key] = true;
            $sites[] = $parsed;
        }
        usort($sites, static fn ($a, $b) => strcmp((string) $a['domain'], (string) $b['domain']));
        return $sites;
    }

    public function addVhost(Runtime $runtime, Config $config, array $spec): array
    {
        $domain = $spec['domain'];
        $root = $spec['root'];
        $type = $spec['type'];
        $phpVersion = $spec['php_version'] ?? null;
        $upstream = $spec['upstream'] ?? null;

        $confPath = $this->siteAvailablePath($config, $domain);
        // Duplicate = active registration, never docroot / orphan file existence.
        foreach ($this->listVhosts($runtime, $config) as $parsed) {
            if (($parsed['domain'] ?? '') === $domain || in_array($domain, $parsed['domains'] ?? [], true)) {
                throw new BrokerException(
                    !empty($parsed['readonly'])
                        ? "{$domain} is managed externally and can't be edited."
                        : "A vhost for {$domain} already exists.",
                    3
                );
            }
        }
        if ($runtime->fileExists($confPath)) {
            $runtime->deleteFile($confPath);
        }

        $contents = $this->render($runtime, $config, $domain, $root, $type, $phpVersion, $upstream);
        if (!$runtime->isDir($root)) {
            $runtime->mkdir($root, 0755);
            $runtime->chown($root, $config->phpUser, $config->phpGroup);
        }
        // One-time welcome page for empty php/static docroots (never on edit/reload).
        $welcome = VhostWelcomePage::seedIfEmpty($runtime, $domain, $root, $type);
        if ($welcome !== null) {
            $runtime->chown($welcome, $config->phpUser, $config->phpGroup);
            $runtime->chmod($welcome, 0664);
        }
        $this->ensureLogDir($runtime, $config);

        $dir = dirname($confPath);
        if (!$runtime->isDir($dir)) {
            $runtime->mkdir($dir, 0755);
        }
        $tmp = $confPath . '.lacmp-tmp';
        $runtime->writeFile($tmp, $contents, 0644);
        try {
            $runtime->rename($tmp, $confPath);
        } catch (BrokerException $e) {
            $runtime->deleteFile($tmp);
            throw $e;
        }

        $enabled = false;
        try {
            $this->enableSite($runtime, $config, $domain, $confPath);
            $enabled = true;
            $this->validate($runtime, $config);
            $applied = $this->reload($runtime, $config, 'auto');
        } catch (BrokerException $e) {
            if ($enabled) {
                $this->disableSite($runtime, $config, $domain);
            }
            $runtime->deleteFile($confPath);
            try {
                $this->reload($runtime, $config, 'auto');
            } catch (BrokerException) {
            }
            throw new BrokerException(
                'Apache rejected the config. The new vhost was rolled back. Existing sites were left serving. ' . $e->getMessage(),
                1
            );
        }

        return [
            'domain' => $domain,
            'root' => $root,
            'type' => $type,
            'php_version' => $phpVersion,
            'upstream' => $upstream,
            'source' => $confPath,
            'apply' => $applied,
        ];
    }

    public function removeVhost(Runtime $runtime, Config $config, string $domain): array
    {
        $confPath = $this->existingPath($runtime, $config, $domain);
        if ($confPath === null) {
            throw new BrokerException('Vhost config does not exist.', 3);
        }
        $contents = $runtime->readFile($confPath);
        $parsed = ApacheParser::parseFile($confPath, $contents, $config->readonlyVhosts);
        if ($parsed['readonly']) {
            throw new BrokerException('This vhost is managed externally and cannot be deleted by the panel.', 3);
        }

        $this->disableSite($runtime, $config, $domain);
        $available = $this->siteAvailablePath($config, $domain);
        $enabled = rtrim($config->vhostDir, '/') . '/' . $domain . '.conf';
        foreach (array_unique([$confPath, $available, $enabled]) as $path) {
            if ($runtime->fileExists($path)) {
                $runtime->deleteFile($path);
            }
        }

        try {
            $this->validate($runtime, $config);
            $applied = $this->reload($runtime, $config, 'auto');
        } catch (BrokerException $e) {
            $runtime->writeFile($confPath, $contents, 0644);
            $this->enableSite($runtime, $config, $domain, $confPath);
            try {
                $this->reload($runtime, $config, 'auto');
            } catch (BrokerException) {
            }
            throw new BrokerException(
                'Apache would fail without this vhost; the file was restored. ' . $e->getMessage(),
                1
            );
        }

        return [
            'domain' => $domain,
            'deleted' => $confPath,
            'web_root_preserved' => $parsed['root'],
            'apply' => $applied,
        ];
    }

    public function updateVhost(Runtime $runtime, Config $config, string $domain, array $changes): array
    {
        $confPath = $this->existingPath($runtime, $config, $domain);
        if ($confPath === null) {
            throw new BrokerException('Vhost config does not exist.', 3);
        }
        $oldContents = $runtime->readFile($confPath);
        $parsed = ApacheParser::parseFile($confPath, $oldContents, $config->readonlyVhosts);
        if ($parsed['readonly']) {
            throw new BrokerException('This vhost is managed externally and cannot be edited by the panel.', 3);
        }

        $before = $this->editSnapshot($parsed);
        $spec = $this->mergeEditSpec($runtime, $config, $parsed, $changes);
        $newContents = $this->render(
            $runtime,
            $config,
            $domain,
            $spec['root'],
            $spec['type'],
            $spec['php_version'],
            $spec['upstream'],
            $spec['tls_mode'],
            $spec['tls_cert'],
            $spec['tls_key'],
        );

        $tmp = $confPath . '.lacmp-tmp';
        $runtime->writeFile($tmp, $newContents, 0644);
        try {
            $runtime->rename($tmp, $confPath);
        } catch (BrokerException $e) {
            $runtime->deleteFile($tmp);
            throw $e;
        }

        try {
            $this->validate($runtime, $config);
            $applied = $this->reload($runtime, $config, 'auto');
        } catch (BrokerException $e) {
            $runtime->writeFile($confPath, $oldContents, 0644);
            try {
                $this->reload($runtime, $config, 'auto');
            } catch (BrokerException) {
            }
            throw new BrokerException(
                'Apache rejected the edit; the prior config was restored. Existing sites were left serving. ' . $e->getMessage(),
                1
            );
        }

        $after = $this->editSnapshot(array_merge($parsed, [
            'root' => $spec['root'],
            'php_version' => $spec['php_version'],
            'tls' => TlsMode::enabled($spec['tls_mode']),
            'tls_mode' => $spec['tls_mode'],
            'type' => $spec['type'],
        ]));

        return [
            'domain' => $domain,
            'before' => $before,
            'after' => $after,
            'root' => $spec['root'],
            'type' => $spec['type'],
            'php_version' => $spec['php_version'],
            'tls' => TlsMode::enabled($spec['tls_mode']),
            'tls_mode' => $spec['tls_mode'],
            'source' => $confPath,
            'apply' => $applied,
        ];
    }

    public function reload(Runtime $runtime, Config $config, string $mode = 'auto', array $expectPorts = []): array
    {
        $this->validate($runtime, $config);
        $unit = $config->webService;
        fwrite(STDERR, "==> Applying via systemctl reload {$unit}\n");
        $result = $runtime->exec(['/usr/bin/systemctl', 'reload', $unit], null, 60);
        if (!$result->ok()) {
            if ($mode === 'restart' || $mode === 'auto') {
                fwrite(STDERR, "==> Applying via systemctl restart {$unit} (brief connection drop)\n");
                $result = $runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 60);
                if ($result->ok()) {
                    $this->assertActive($runtime, $unit);
                    return ['path' => 'restart', 'address' => '', 'admin_spec' => 'n/a', 'admin_enabled' => false];
                }
            }
            $detail = trim($result->stderr . "\n" . $result->stdout);
            throw new BrokerException(
                "systemctl reload {$unit} failed" . ($detail !== '' ? ': ' . $detail : '.'),
                1
            );
        }
        $this->assertActive($runtime, $unit);
        fwrite(STDERR, "==> Apache apply path: systemctl (unit {$unit})\n");
        return ['path' => 'systemctl', 'address' => '', 'admin_spec' => 'n/a', 'admin_enabled' => false];
    }

    public function backupPaths(Config $config): array
    {
        $paths = [$config->vhostDir];
        if ($config->vhostAvailableDir !== '' && $config->vhostAvailableDir !== $config->vhostDir) {
            $paths[] = $config->vhostAvailableDir;
        }
        return $paths;
    }

    public function version(Runtime $runtime, Config $config): array
    {
        $ctl = $this->apacheCtl($runtime, $config);
        $r = $runtime->exec(array_merge($ctl, ['-v']));
        $raw = trim($r->stdout !== '' ? $r->stdout : $r->stderr);
        $version = $raw;
        if (preg_match('/Apache\/([0-9.]+)/', $raw, $m)) {
            $version = $m[1];
        }
        return [
            'version' => $version,
            'raw' => explode("\n", $raw)[0] ?? $raw,
            'service' => $config->webService,
            'label' => 'Apache',
            'stack' => 'lamp',
        ];
    }

    public function mainConfigPath(Config $config): string
    {
        return $config->webService === 'httpd' ? self::HTTPD_CONF : self::APACHE2_CONF;
    }

    private function validate(Runtime $runtime, Config $config): void
    {
        $ctl = $this->apacheCtl($runtime, $config);
        foreach (array_merge($ctl, [$this->mainConfigPath($config), $config->vhostDir, $config->vhostAvailableDir]) as $token) {
            if ($token !== '' && preg_match('/\s/', $token) === 1) {
                throw new BrokerException("Apache path contains whitespace: '{$token}'", 1);
            }
        }
        $result = $runtime->exec(array_merge($ctl, ['-t']), null, 20);
        if (!$result->ok()) {
            $detail = trim($result->stderr . "\n" . $result->stdout);
            throw new BrokerException(
                'Apache rejected the config: ' . ($detail !== '' ? $detail : 'configtest failed'),
                1
            );
        }
    }

    /** @return list<string> */
    private function apacheCtl(Runtime $runtime, Config $config): array
    {
        foreach ([$config->apacheCtl, '/usr/sbin/apache2ctl', '/usr/sbin/apachectl', '/usr/sbin/httpd'] as $bin) {
            if ($runtime->fileExists($bin)) {
                return [$bin];
            }
        }
        return ['/usr/sbin/apachectl'];
    }

    private function debianLayout(Runtime $runtime, Config $config): bool
    {
        return $runtime->isDir($config->vhostAvailableDir) || $runtime->isDir(self::SITES_AVAILABLE);
    }

    private function siteAvailablePath(Config $config, string $domain): string
    {
        if ($config->vhostAvailableDir !== '') {
            return rtrim($config->vhostAvailableDir, '/') . '/' . $domain . '.conf';
        }
        return rtrim($config->vhostDir, '/') . '/' . $domain . '.conf';
    }

    private function existingPath(Runtime $runtime, Config $config, string $domain): ?string
    {
        foreach ($this->listVhostFiles($runtime, $config) as $entry) {
            if (basename($entry['path'], '.conf') === $domain) {
                return $entry['path'];
            }
            try {
                $parsed = ApacheParser::parseFile(
                    $entry['path'],
                    $runtime->readFile($entry['path']),
                    $config->readonlyVhosts,
                    $entry['enabled']
                );
            } catch (\Throwable) {
                continue;
            }
            if (($parsed['domain'] ?? '') === $domain || in_array($domain, $parsed['domains'] ?? [], true)) {
                return $entry['path'];
            }
        }
        $candidate = $this->siteAvailablePath($config, $domain);
        return $runtime->fileExists($candidate) ? $candidate : null;
    }

    /**
     * Debian: sites-enabled (active, often symlinks) then sites-available leftovers (disabled).
     * EL: a single vhost dir. Dedup by real path, then basename, then ServerName.
     *
     * @return list<array{path:string,enabled:bool}>
     */
    private function listVhostFiles(Runtime $runtime, Config $config): array
    {
        $enabledDir = rtrim($config->vhostDir, '/');
        $availableDir = rtrim((string) $config->vhostAvailableDir, '/');
        $split = $availableDir !== '' && $availableDir !== $enabledDir;

        $out = [];
        $seenCanon = [];
        $seenBase = [];

        $push = static function (string $path, bool $enabled) use ($runtime, &$out, &$seenCanon, &$seenBase): void {
            $canon = $runtime->realPath($path);
            if ($canon === '') {
                $canon = $path;
            }
            if (isset($seenCanon[$canon])) {
                return;
            }
            $base = strtolower(basename($path));
            if (isset($seenBase[$base])) {
                return;
            }
            $seenCanon[$canon] = true;
            $seenBase[$base] = true;
            $out[] = ['path' => $path, 'enabled' => $enabled];
        };

        foreach ($runtime->glob($enabledDir . '/*.conf') as $file) {
            $read = $file;
            if ($split) {
                $avail = $availableDir . '/' . basename($file);
                if ($runtime->fileExists($avail)) {
                    $read = $avail;
                }
            }
            $push($read, true);
        }
        if ($split) {
            foreach ($runtime->glob($availableDir . '/*.conf') as $file) {
                $push($file, false);
            }
        }

        return $out;
    }

    private function enableSite(Runtime $runtime, Config $config, string $domain, string $confPath): void
    {
        if ($this->debianLayout($runtime, $config) && $runtime->fileExists('/usr/sbin/a2ensite')) {
            $r = $runtime->exec(['/usr/sbin/a2ensite', $domain], null, 15);
            if (!$r->ok()) {
                throw new BrokerException(trim($r->stderr . "\n" . $r->stdout) ?: 'a2ensite failed.', 1);
            }
            return;
        }
        $enabled = rtrim($config->vhostDir, '/') . '/' . $domain . '.conf';
        if ($enabled !== $confPath && !$runtime->fileExists($enabled)) {
            $runtime->writeFile($enabled, $runtime->readFile($confPath), 0644);
        }
    }

    private function disableSite(Runtime $runtime, Config $config, string $domain): void
    {
        if ($this->debianLayout($runtime, $config) && $runtime->fileExists('/usr/sbin/a2dissite')) {
            $runtime->exec(['/usr/sbin/a2dissite', $domain], null, 15);
        }
    }

    private function assertActive(Runtime $runtime, string $unit): void
    {
        $active = $runtime->exec(['/usr/bin/systemctl', 'is-active', $unit]);
        if (trim($active->stdout) !== 'active' && !$active->ok()) {
            throw new BrokerException("Apache is not active after apply (systemctl is-active {$unit} failed).", 1);
        }
    }

    private function ensureLogDir(Runtime $runtime, Config $config): void
    {
        $dir = $config->webLogDir;
        if (!$runtime->isDir($dir)) {
            $runtime->mkdir($dir, 0755);
            $runtime->chown($dir, $config->webUser, $config->webUser);
        }
    }

    private function render(
        Runtime $runtime,
        Config $config,
        string $domain,
        string $root,
        string $type,
        ?string $phpVersion,
        ?string $upstream,
        string $tlsMode = TlsMode::OFF,
        ?string $tlsCert = null,
        ?string $tlsKey = null,
    ): string {
        $mode = TlsMode::effective($tlsMode, $domain);
        $logs = $config->webLogDir;
        $phpBlock = '';
        $proxyBlock = '';
        $dirBlock = '';
        if ($type === 'php' && $phpVersion !== null) {
            $sock = $config->phpFpmUnixPath($phpVersion, $runtime);
            $phpBlock = <<<PHP
    <FilesMatch \\.php\$>
        SetHandler "proxy:unix:{$sock}|fcgi://localhost"
    </FilesMatch>

PHP;
        }
        if ($type === 'proxy' && $upstream !== null) {
            $proxyBlock = "    ProxyPreserveHost On\n    ProxyPass / http://{$upstream}/\n    ProxyPassReverse / http://{$upstream}/\n";
        }
        if ($type !== 'proxy') {
            $dirBlock = <<<DIR
    DocumentRoot {$root}
    <Directory {$root}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

DIR;
        }

        $acmeRoot = Certbot::WEBROOT;
        $acmeBlock = <<<ACME
    Alias /.well-known/acme-challenge/ {$acmeRoot}/.well-known/acme-challenge/
    <Directory {$acmeRoot}/.well-known/acme-challenge/>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

ACME;

        $http = <<<EOF
<VirtualHost *:80>
    ServerName {$domain}
{$dirBlock}{$acmeBlock}{$phpBlock}{$proxyBlock}    ErrorLog  {$logs}/{$domain}-error.log
    CustomLog {$logs}/{$domain}-access.log combined
</VirtualHost>

EOF;

        if ($mode === TlsMode::OFF) {
            return $http;
        }

        if ($mode === TlsMode::INTERNAL || ($tlsCert === null || $tlsKey === null)) {
            $cert = '/etc/ssl/certs/ssl-cert-snakeoil.pem';
            $key = '/etc/ssl/private/ssl-cert-snakeoil.key';
            $tag = '# azerioid-tls-mode=internal';
        } else {
            $cert = $tlsCert;
            $key = $tlsKey;
            $tag = $mode === TlsMode::DNS01 ? '# azerioid-tls-mode=dns01' : '# azerioid-tls-mode=auto';
        }

        $ssl = <<<EOF
{$tag}
<VirtualHost *:443>
    ServerName {$domain}
    SSLEngine on
    SSLCertificateFile {$cert}
    SSLCertificateKeyFile {$key}
{$dirBlock}{$phpBlock}{$proxyBlock}    ErrorLog  {$logs}/{$domain}-ssl-error.log
    CustomLog {$logs}/{$domain}-ssl-access.log combined
</VirtualHost>

EOF;

        return $http . $ssl;
    }

    /** @return array{root:string,type:string,php_version:?string,upstream:?string,tls_mode:string,tls_cert:?string,tls_key:?string} */
    private function mergeEditSpec(Runtime $runtime, Config $config, array $parsed, array $changes): array
    {
        $type = (string) ($parsed['type'] ?? 'static');
        $domain = (string) ($parsed['domain'] ?? '');
        $root = (string) ($changes['root'] ?? ($parsed['root'] ?? ''));
        if ($root === '' && $type !== 'proxy') {
            throw new BrokerException('Docroot is required for this vhost.', 2);
        }
        if (isset($changes['root']) && $root !== '' && !$runtime->isDir($root)) {
            $runtime->mkdir($root, 0755);
            $runtime->chown($root, $config->phpUser, $config->phpGroup);
        }
        if ($type === 'php' && isset($changes['php_version'])) {
            $phpVersion = $changes['php_version'];
        } else {
            $phpVersion = $parsed['php_version'] ?? null;
        }
        if ($type === 'php' && ($phpVersion === null || $phpVersion === '')) {
            throw new BrokerException('php_version is required for PHP vhosts.', 2);
        }
        $upstream = $parsed['reverse_proxy'] ?? null;

        if (isset($changes['tls_mode'])) {
            $tlsMode = TlsMode::normalize($changes['tls_mode']);
        } elseif (array_key_exists('tls', $changes)) {
            $tlsMode = TlsMode::normalize((bool) $changes['tls']);
        } else {
            $tlsMode = (string) ($parsed['tls_mode'] ?? ( ! empty($parsed['tls']) ? TlsMode::AUTO : TlsMode::OFF));
        }
        $tlsMode = TlsMode::effective($tlsMode, $domain);
        $tlsCert = $changes['tls_cert'] ?? ($parsed['tls_cert'] ?? null);
        $tlsKey = $changes['tls_key'] ?? ($parsed['tls_key'] ?? null);

        return [
            'root' => $root,
            'type' => $type,
            'php_version' => $type === 'php' ? $phpVersion : null,
            'upstream' => $type === 'proxy' ? $upstream : null,
            'tls_mode' => $tlsMode,
            'tls_cert' => is_string($tlsCert) ? $tlsCert : null,
            'tls_key' => is_string($tlsKey) ? $tlsKey : null,
        ];
    }

    /** @return array{root:?string,php_version:?string,tls:bool,tls_mode:string,type:string} */
    private function editSnapshot(array $parsed): array
    {
        $mode = (string) ($parsed['tls_mode'] ?? ( ! empty($parsed['tls']) ? TlsMode::AUTO : TlsMode::OFF));

        return [
            'root' => $parsed['root'] ?? null,
            'php_version' => $parsed['php_version'] ?? null,
            'tls' => TlsMode::enabled($mode),
            'tls_mode' => $mode,
            'type' => (string) ($parsed['type'] ?? 'static'),
        ];
    }
}
