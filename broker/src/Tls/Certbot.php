<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tls;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Thin certbot runner for DNS-01 only.
 *
 * HTTP-01 for site vhosts is Caddy-native (A21) — `issueHttp01()` is unused.
 * Never invoke certbot's apache/nginx installers.
 */
final class Certbot
{
    public const WEBROOT = '/var/lib/azerioid-panel/acme-webroot';
    public const LIVE_DIR = '/etc/letsencrypt/live';

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    public function binary(): string
    {
        foreach (['/usr/bin/certbot', '/usr/local/bin/certbot'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }
        throw new BrokerException(
            'certbot is not installed. Install certbot (and the DNS plugin if using DNS-01) then retry.',
            3
        );
    }

    public function ensureWebroot(): void
    {
        if (!$this->runtime->isDir(self::WEBROOT)) {
            $this->runtime->mkdir(self::WEBROOT, 0755);
        }
        $challenge = self::WEBROOT . '/.well-known/acme-challenge';
        if (!$this->runtime->isDir($challenge)) {
            $this->runtime->mkdir($challenge, 0755);
        }
    }

    public function livePaths(string $domain): array
    {
        $base = rtrim(self::LIVE_DIR, '/') . '/' . $domain;

        return [
            'cert' => $base . '/fullchain.pem',
            'key' => $base . '/privkey.pem',
            'chain' => $base . '/chain.pem',
        ];
    }

    /**
     * Retired: Apache/Nginx certbot HTTP-01 webroot. Not called after the front-router model.
     *
     * @param  list<string>  $domains
     * @return array{cert:string,key:string,chain:string,staging:bool}
     */
    public function issueHttp01(array $domains, string $email, bool $staging = false): array
    {
        $this->ensureWebroot();
        $primary = $domains[0] ?? '';
        if ($primary === '') {
            throw new BrokerException('At least one domain is required for certificate issuance.', 2);
        }
        $cmd = [
            $this->binary(),
            'certonly',
            '--non-interactive',
            '--agree-tos',
            '--email', $email,
            '--webroot',
            '-w', self::WEBROOT,
            '--keep-until-expiring',
            '--preferred-challenges', 'http',
        ];
        if ($staging) {
            $cmd[] = '--staging';
        }
        foreach ($domains as $d) {
            $cmd[] = '-d';
            $cmd[] = $d;
        }
        $result = $this->runtime->exec($cmd, null, 300);
        if (!$result->ok()) {
            $detail = trim($result->stderr . "\n" . $result->stdout);
            throw new BrokerException(
                'certbot HTTP-01 issuance failed: ' . ($detail !== '' ? $detail : 'unknown error'),
                1
            );
        }
        $paths = $this->livePaths($primary);
        if (!$this->runtime->fileExists($paths['cert']) || !$this->runtime->fileExists($paths['key'])) {
            throw new BrokerException('certbot finished but certificate files were not found under ' . self::LIVE_DIR . '.', 1);
        }

        return $paths + ['staging' => $staging];
    }

    /**
     * @param  list<string>  $domains
     * @return array{cert:string,key:string,chain:string,staging:bool}
     */
    public function issueDns01(
        array $domains,
        string $email,
        array $provider,
        string $credentialsFile,
        bool $staging = false,
    ): array {
        $primary = $domains[0] ?? '';
        if ($primary === '') {
            throw new BrokerException('At least one domain is required for certificate issuance.', 2);
        }
        if (!$this->runtime->fileExists($credentialsFile)) {
            throw new BrokerException('DNS provider credentials file is missing. Store credentials first.', 3);
        }
        $plugin = (string) ($provider['certbot_plugin'] ?? '');
        $credFlag = (string) ($provider['credentials_flag'] ?? '');
        if ($plugin === '' || $credFlag === '') {
            throw new BrokerException('DNS provider registry entry is incomplete.', 2);
        }
        $cmd = [
            $this->binary(),
            'certonly',
            '--non-interactive',
            '--agree-tos',
            '--email', $email,
            '--' . ltrim($plugin, '-'),
            $credFlag, $credentialsFile,
            '--keep-until-expiring',
            '--preferred-challenges', 'dns',
        ];
        if ($staging) {
            $cmd[] = '--staging';
        }
        foreach ($domains as $d) {
            $cmd[] = '-d';
            $cmd[] = $d;
        }
        $result = $this->runtime->exec($cmd, null, 600);
        if (!$result->ok()) {
            $detail = trim($result->stderr . "\n" . $result->stdout);
            // Never echo credential file contents; stderr from certbot is OK.
            throw new BrokerException(
                'certbot DNS-01 issuance failed: ' . ($detail !== '' ? $detail : 'unknown error'),
                1
            );
        }
        // Wildcard primary name uses the apex for live/ path when -d example.com -d *.example.com
        $liveName = ltrim($primary, '*.');
        $paths = $this->livePaths($liveName);
        if (!$this->runtime->fileExists($paths['cert'])) {
            // certbot may nest under the first -d as given
            $paths = $this->livePaths($primary);
        }
        if (!$this->runtime->fileExists($paths['cert']) || !$this->runtime->fileExists($paths['key'])) {
            throw new BrokerException('certbot finished but certificate files were not found under ' . self::LIVE_DIR . '.', 1);
        }

        return $paths + ['staging' => $staging];
    }

    public function renewDryRun(): array
    {
        $result = $this->runtime->exec([$this->binary(), 'renew', '--dry-run'], null, 600);

        return [
            'ok' => $result->ok(),
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'exit_code' => $result->exitCode,
        ];
    }

    public function ensureRenewalHook(): string
    {
        $dir = '/etc/letsencrypt/renewal-hooks/deploy';
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0755);
        }
        $path = $dir . '/azerioid-reload.sh';
        $script = <<<'SH'
#!/usr/bin/env bash
# AZERIOID Stack Manager — reload web server after certbot renew.
set -euo pipefail
reload_unit() {
  local unit="$1"
  if systemctl is-active --quiet "$unit" 2>/dev/null; then
    systemctl reload "$unit" 2>/dev/null || systemctl kill -s HUP "$unit" 2>/dev/null || systemctl restart "$unit"
  fi
}
# Prefer site web server when present; always try Caddy (DNS-01 static certs + panel).
reload_unit nginx
reload_unit apache2
reload_unit httpd
reload_unit caddy
SH;
        $this->runtime->writeFile($path, $script, 0755);

        return $path;
    }

    public function defaultEmail(string $domain): string
    {
        $configured = trim((string) ($this->config->acmeEmail ?? ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }
        $apex = preg_replace('/^www\./', '', $domain) ?? $domain;

        return 'admin@' . $apex;
    }
}
