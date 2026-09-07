<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\CaddyCli;
use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Vhost\VhostUser;

final class CaddyDriver implements WebServerDriver
{
    public const CADDYFILE = Config::CADDYFILE;

    public function stackName(): string
    {
        return 'lcmp';
    }

    public function webServiceName(): string
    {
        return 'caddy';
    }

    public function listVhosts(Runtime $runtime, Config $config): array
    {
        $files = $runtime->glob(rtrim($config->caddyConfD, '/') . '/*.conf');
        $sites = [];
        foreach ($files as $file) {
            try {
                $contents = $runtime->readFile($file);
            } catch (\Throwable) {
                continue;
            }
            $sites[] = CaddyParser::parseFile($file, $contents, $config->readonlyVhosts);
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

        $confPath = rtrim($config->caddyConfD, '/') . '/' . $domain . '.conf';
        if ($runtime->fileExists($confPath)) {
            throw new BrokerException("A vhost for {$domain} already exists.", 3);
        }
        $this->assertDomainFree($runtime, $config, $domain);

        $contents = $this->render(
            $runtime,
            $config,
            $domain,
            $root,
            $type,
            $phpVersion,
            $upstream,
            TlsMode::OFF
        );
        if (!$runtime->isDir($root)) {
            $runtime->mkdir($root, 0755);
        }
        if ($type !== 'proxy') {
            VhostUser::ensure($runtime, $config, $domain, $root);
        } else {
            $runtime->chown($root, $config->phpUser, $config->phpGroup);
        }
        $this->ensureAccessLog($runtime, $config, $domain);

        $this->assertCaddyfile($runtime, $config);

        $tmp = $confPath . '.lacmp-tmp';
        $runtime->writeFile($tmp, $contents, 0644);
        try {
            $runtime->rename($tmp, $confPath);
        } catch (BrokerException $e) {
            $runtime->deleteFile($tmp);
            throw $e;
        }

        $validate = CaddyCli::validate($runtime, $config, $this->mainConfigPath($config));
        if (!$validate->ok()) {
            $runtime->deleteFile($confPath);
            $detail = trim($validate->stderr . "\n" . $validate->stdout);
            $detail = preg_replace('#/etc/caddy/conf\.d/[^\s:]+#', 'the new vhost file', $detail) ?? $detail;
            throw new BrokerException(
                'Caddy rejected the config: ' . ($detail !== '' ? $detail : 'validation failed') . ' The new vhost was rolled back.',
                1
            );
        }

        try {
            $applied = CaddyApply::run($runtime, $config, 'auto');
        } catch (BrokerException $e) {
            $runtime->deleteFile($confPath);
            try {
                CaddyApply::run($runtime, $config, 'auto');
            } catch (BrokerException) {
            }
            throw new BrokerException(
                'Caddy could not apply the new vhost; the file was rolled back. Existing sites were left serving. ' . $e->getMessage(),
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
        $confPath = rtrim($config->caddyConfD, '/') . '/' . $domain . '.conf';
        if (!$runtime->fileExists($confPath)) {
            throw new BrokerException('Vhost config does not exist.', 3);
        }
        $contents = $runtime->readFile($confPath);
        $parsed = CaddyParser::parseFile($confPath, $contents, $config->readonlyVhosts);
        if ($parsed['readonly']) {
            throw new BrokerException('This vhost is managed externally and cannot be deleted by the panel.', 3);
        }

        $this->assertCaddyfile($runtime, $config);
        $runtime->deleteFile($confPath);
        $validate = CaddyCli::validate($runtime, $config, $this->mainConfigPath($config));
        if (!$validate->ok()) {
            $runtime->writeFile($confPath, $contents, 0644);
            throw new BrokerException(
                'Caddy would fail without this vhost; the file was restored. ' . trim($validate->stderr . "\n" . $validate->stdout),
                1
            );
        }

        try {
            $applied = CaddyApply::run($runtime, $config, 'auto');
        } catch (BrokerException $e) {
            $runtime->writeFile($confPath, $contents, 0644);
            try {
                CaddyApply::run($runtime, $config, 'auto');
            } catch (BrokerException) {
            }
            throw new BrokerException(
                'Caddy could not apply the deletion; the vhost file was restored. ' . $e->getMessage(),
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
        $confPath = $this->resolveConfPath($runtime, $config, $domain);
        if ($confPath === null) {
            throw new BrokerException('Vhost config does not exist.', 3);
        }
        $oldContents = $runtime->readFile($confPath);
        $parsed = CaddyParser::parseFile($confPath, $oldContents, $config->readonlyVhosts);
        if ($parsed['readonly']) {
            throw new BrokerException('This vhost is managed externally and cannot be edited by the panel.', 3);
        }

        $before = $this->editSnapshot($parsed);
        $spec = $this->mergeEditSpec($runtime, $config, $domain, $parsed, $changes);

        $this->assertCaddyfile($runtime, $config);
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

        $validate = CaddyCli::validate($runtime, $config, $this->mainConfigPath($config));
        if (!$validate->ok()) {
            $runtime->writeFile($confPath, $oldContents, 0644);
            $detail = trim($validate->stderr . "\n" . $validate->stdout);
            $detail = preg_replace('#/etc/caddy/conf\.d/[^\s:]+#', 'the vhost file', $detail) ?? $detail;
            throw new BrokerException(
                'Caddy rejected the config: ' . ($detail !== '' ? $detail : 'validation failed') . ' The edit was rolled back.',
                1
            );
        }

        try {
            $applied = CaddyApply::run($runtime, $config, 'auto');
        } catch (BrokerException $e) {
            $runtime->writeFile($confPath, $oldContents, 0644);
            try {
                CaddyApply::run($runtime, $config, 'auto');
            } catch (BrokerException) {
            }
            throw new BrokerException(
                'Caddy could not apply the edit; the prior config was restored. Existing sites were left serving. ' . $e->getMessage(),
                1
            );
        }

        $after = $this->editSnapshot(array_merge($parsed, [
            'root' => $spec['root'],
            'php_version' => $spec['php_version'],
            'tls' => TlsMode::enabled($spec['tls_mode']),
            'tls_mode' => $spec['tls_mode'],
            'tls_cert' => $spec['tls_cert'],
            'tls_key' => $spec['tls_key'],
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
        return CaddyApply::run($runtime, $config, $mode, $expectPorts);
    }

    public function backupPaths(Config $config): array
    {
        return [$this->mainConfigPath($config), $config->caddyConfD];
    }

    public function mainConfigPath(Config $config): string
    {
        return self::CADDYFILE;
    }

    private function assertCaddyfile(Runtime $runtime, Config $config): void
    {
        $path = $this->mainConfigPath($config);
        Config::assertMainConfigPath($path, self::CADDYFILE);
        if (!$runtime->fileExists($path)) {
            throw new BrokerException("Caddy main-config not found: {$path}", 1);
        }
    }

    public function version(Runtime $runtime, Config $config): array
    {
        $bin = $runtime->fileExists($config->caddyBin) ? $config->caddyBin : '/usr/bin/caddy';
        $r = $runtime->exec([$bin, 'version']);
        $line = trim(explode("\n", $r->stdout)[0] ?? '');
        $version = $line;
        if (preg_match('/v?(\d+\.\d+\.\d+\S*)/', $line, $m)) {
            $version = $m[1];
        }
        return [
            'version' => $version,
            'raw' => $line,
            'service' => 'caddy',
            'label' => 'Caddy',
            'stack' => 'lcmp',
        ];
    }

    private function assertDomainFree(Runtime $runtime, Config $config, string $domain): void
    {
        foreach ($this->listVhosts($runtime, $config) as $parsed) {
            if ($parsed['readonly'] && (in_array($domain, $parsed['domains'] ?? [], true) || ($parsed['domain'] ?? '') === $domain)) {
                throw new BrokerException("{$domain} is managed externally and can't be edited.", 3);
            }
            if (in_array($domain, $parsed['domains'] ?? [], true) || ($parsed['domain'] ?? '') === $domain) {
                throw new BrokerException("A vhost for {$domain} already exists.", 3);
            }
        }
    }

    private function ensureAccessLog(Runtime $runtime, Config $config, string $domain): void
    {
        $dir = '/var/log/caddy';
        $path = $dir . '/access_' . $domain . '.log';
        $user = CaddyCli::serviceUser($runtime) ?? $config->webUser;
        if (!$runtime->isDir($dir)) {
            $runtime->mkdir($dir, 0755);
        }
        $runtime->chown($dir, $user, $user);
        if (!$runtime->fileExists($path)) {
            $runtime->writeFile($path, '', 0640);
        }
        $runtime->chown($path, $user, $user);
        $runtime->chmod($path, 0640);
    }

    private function render(
        Runtime $runtime,
        Config $config,
        string $domain,
        string $root,
        string $type,
        ?string $phpVersion,
        ?string $upstream,
        string $tlsMode = TlsMode::AUTO,
        ?string $tlsCert = null,
        ?string $tlsKey = null,
    ): string {
        $mode = TlsMode::effective($tlsMode, $domain);
        if ($mode === TlsMode::OFF) {
            $siteLabel = "http://{$domain}";
            $tlsLine = '';
        } else {
            $siteLabel = $domain;
            if ($mode === TlsMode::INTERNAL) {
                $tlsLine = "    tls internal\n";
            } elseif (($mode === TlsMode::DNS01 || $tlsCert !== null) && $tlsCert !== null && $tlsKey !== null) {
                $tlsLine = "    tls {$tlsCert} {$tlsKey}\n";
            } else {
                // Native Caddy automatic HTTPS (Let's Encrypt HTTP-01).
                $tlsLine = '';
            }
        }
        $phpBlock = '';
        $proxyBlock = '';
        if ($type === 'php' && $phpVersion !== null) {
            $sock = $config->phpFpmSocket($phpVersion, $runtime);
            $phpBlock = "    php_fastcgi {$sock}\n";
        }
        if ($type === 'proxy' && $upstream !== null) {
            $proxyBlock = "    reverse_proxy {$upstream}\n";
        }
        $rootBlock = $type === 'proxy' ? '' : "    root * {$root}\n";
        $fileServer = $type === 'proxy' ? '' : "    file_server {\n        index index.html index.php\n    }\n";

        return <<<EOF
{$siteLabel} {
{$tlsLine}    header {
        Strict-Transport-Security "max-age=31536000; preload"
        X-Content-Type-Options nosniff
        X-Frame-Options SAMEORIGIN
    }
{$rootBlock}    encode gzip zstd
{$phpBlock}{$proxyBlock}{$fileServer}    log {
        output file /var/log/caddy/access_{$domain}.log {
            roll_size 32mb
            roll_keep 3
            roll_keep_for 7d
        }
    }
}

EOF;
    }

    /** @return array{root:string,type:string,php_version:?string,upstream:?string,tls_mode:string,tls_cert:?string,tls_key:?string} */
    private function mergeEditSpec(Runtime $runtime, Config $config, string $domain, array $parsed, array $changes): array
    {
        $type = (string) ($parsed['type'] ?? 'static');
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

    private function resolveConfPath(Runtime $runtime, Config $config, string $domain): ?string
    {
        $direct = rtrim($config->caddyConfD, '/') . '/' . $domain . '.conf';
        if ($runtime->fileExists($direct)) {
            return $direct;
        }
        foreach ($this->listVhosts($runtime, $config) as $parsed) {
            if (($parsed['domain'] ?? '') === $domain || in_array($domain, $parsed['domains'] ?? [], true)) {
                return (string) ($parsed['source'] ?? '');
            }
        }

        return null;
    }
}
