<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\BrokerConfigWriter;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\CertProbe;
use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Tls\VhostTlsIssuer;

/**
 * Panel Caddy snippet: tunnel + optional public IP:port fallback + optional
 * white-label hostname on :443. A lone site on :3169 is Caddy's default for
 * ANY Host on that port — the catch-all abort/421 site closes that hole.
 */
final class PanelCaddy
{
    public const SNIPPET = 'azerioid-panel.conf';
    public const BROKER_JSON = '/etc/azerioid-panel/broker.json';

    /**
     * @param  array{domain:?string,tls_mode:string,tls_cert:?string,tls_key:?string,public_ip:?string}  $spec
     */
    public function render(Config $config, array $spec): string
    {
        $port = $config->panelPort > 0 ? $config->panelPort : 3169;
        $web = rtrim($config->panelRoot, '/') . '/web/public';
        $sock = $config->panelFpmSocket;
        if (!str_starts_with($sock, 'unix')) {
            $sock = 'unix/' . $sock;
        }
        $common = $this->commonBlock($web, $sock, false);
        $hsts = $this->commonBlock($web, $sock, true);

        $domain = $spec['domain'] !== null && $spec['domain'] !== '' ? strtolower($spec['domain']) : null;
        $publicIp = $spec['public_ip'] !== null && $spec['public_ip'] !== '' ? $spec['public_ip'] : null;
        $tlsMode = TlsMode::normalize($spec['tls_mode'] ?? TlsMode::AUTO);
        $tlsLine = '';
        if ($domain !== null) {
            $tlsMode = TlsMode::effective($tlsMode, $domain);
            if ($tlsMode === TlsMode::INTERNAL) {
                $tlsLine = "    tls internal\n";
            } elseif ($tlsMode === TlsMode::DNS01 && ($spec['tls_cert'] ?? null) && ($spec['tls_key'] ?? null)) {
                $tlsLine = "    tls {$spec['tls_cert']} {$spec['tls_key']}\n";
            }
        }

        $managed = '# azerioid-managed panel type=php';
        if ($domain !== null) {
            $managed .= " domain={$domain} tls={$tlsMode}";
        }
        $blocks = ["{$managed}\n"];
        $blocks[] = <<<CADDY
http://127.0.0.1:{$port} {
    bind 127.0.0.1
{$common}
}

CADDY;
        if ($publicIp !== null) {
            $blocks[] = <<<CADDY
https://{$publicIp}:{$port} {
    tls internal
{$hsts}
}

# Catch-all on the panel port: Caddy otherwise serves a single site for any Host
# (e.g. https://let.az:3169 hitting the panel). Unrelated site vhosts must 421.
https://:{$port} {
    tls internal
    respond "Misdirected request." 421
}

CADDY;
        }
        if ($domain !== null) {
            $blocks[] = <<<CADDY
{$domain} {
{$tlsLine}{$hsts}
}

CADDY;
        }

        return implode('', $blocks);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function apply(Runtime $runtime, Config $config, array $input): array
    {
        $current = $this->status($runtime, $config);
        $clear = !empty($input['clear']) || (array_key_exists('domain', $input) && trim((string) $input['domain']) === '');
        $domain = $current['domain'];
        $tlsMode = (string) ($current['tls_mode'] ?? TlsMode::AUTO);
        $tlsCert = $current['tls_cert'] ?? null;
        $tlsKey = $current['tls_key'] ?? null;

        if ($clear) {
            $domain = null;
            $tlsMode = TlsMode::AUTO;
            $tlsCert = null;
            $tlsKey = null;
        } elseif (isset($input['domain'])) {
            $domain = \AzerioidPanel\Broker\Validator::domain((string) $input['domain']);
            $this->assertNotSiteVhost($runtime, $config, $domain);
            if (isset($input['tls_mode']) || isset($input['tls'])) {
                $tlsMode = isset($input['tls_mode'])
                    ? TlsMode::normalize($input['tls_mode'])
                    : TlsMode::normalize($input['tls'] ?? true, true);
            }
            $tlsMode = TlsMode::effective($tlsMode, $domain);
            if ($tlsMode === TlsMode::OFF) {
                throw new BrokerException('Panel domain requires TLS (auto, dns01, or internal).', 2);
            }
            $issued = (new VhostTlsIssuer($runtime, $config))->ensure($domain, $tlsMode, $input);
            if (is_array($issued) && ($issued['mode'] ?? '') === TlsMode::DNS01) {
                $tlsCert = $issued['cert'] ?? $tlsCert;
                $tlsKey = $issued['key'] ?? $tlsKey;
            }
        } elseif (isset($input['tls_mode']) && is_string($domain) && $domain !== '') {
            $tlsMode = TlsMode::effective(TlsMode::normalize($input['tls_mode']), $domain);
        }

        $publicIp = $this->resolvePublicIp($runtime, $config, $current);

        $spec = [
            'domain' => $domain,
            'tls_mode' => $tlsMode,
            'tls_cert' => $tlsCert,
            'tls_key' => $tlsKey,
            'public_ip' => $publicIp,
        ];
        $newBody = $this->render($config, $spec);
        $appUrl = $this->appUrl($domain, $publicIp, $config->panelPort);

        return $this->commit($runtime, $config, $spec, $newBody, $appUrl);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(Runtime $runtime, Config $config): array
    {
        $path = $this->snippetPath($config);
        $parsed = [
            'domain' => $config->panelDomain,
            'tls_mode' => $config->panelDomainTlsMode,
            'tls_cert' => $config->panelDomainTlsCert,
            'tls_key' => $config->panelDomainTlsKey,
            'public_ip' => $config->panelPublicIp,
            'catch_all' => false,
        ];
        if ($runtime->fileExists($path)) {
            $body = $runtime->readFile($path);
            $fromFile = $this->inspect($body, $config->panelPort);
            foreach (['domain', 'tls_mode', 'tls_cert', 'tls_key', 'public_ip', 'catch_all'] as $k) {
                if ($fromFile[$k] !== null && $fromFile[$k] !== '' && $fromFile[$k] !== false) {
                    $parsed[$k] = $fromFile[$k];
                }
            }
            if ($parsed['domain'] === null && $fromFile['domain'] !== null) {
                $parsed['domain'] = $fromFile['domain'];
            }
            $parsed['catch_all'] = (bool) $fromFile['catch_all'];
        }

        $port = $config->panelPort > 0 ? $config->panelPort : 3169;
        $fallback = ['http://127.0.0.1:' . $port];
        if (is_string($parsed['public_ip']) && $parsed['public_ip'] !== '') {
            $fallback[] = 'https://' . $parsed['public_ip'] . ':' . $port;
        }
        $tlsStatus = null;
        if (is_string($parsed['domain']) && $parsed['domain'] !== '') {
            $tlsStatus = CertProbe::probe($runtime, $parsed['domain'], (string) $parsed['tls_mode'], 443);
        }

        return [
            'domain' => $parsed['domain'] ?: null,
            'tls_mode' => $parsed['tls_mode'] ?: TlsMode::AUTO,
            'tls_cert' => $parsed['tls_cert'] ?: null,
            'tls_key' => $parsed['tls_key'] ?: null,
            'public_ip' => $parsed['public_ip'] ?: null,
            'catch_all' => (bool) $parsed['catch_all'],
            'app_url' => $this->appUrl($parsed['domain'] ?: null, $parsed['public_ip'] ?: null, $port),
            'fallback_urls' => $fallback,
            'tls_status' => $tlsStatus,
            'note' => 'IP/tunnel fallback always remains. Unrelated Host headers on the panel port receive 421.',
        ];
    }

    /**
     * @return array{domain:?string,tls_mode:?string,tls_cert:?string,tls_key:?string,public_ip:?string,catch_all:bool}
     */
    public function inspect(string $body, int $port): array
    {
        $out = [
            'domain' => null,
            'tls_mode' => null,
            'tls_cert' => null,
            'tls_key' => null,
            'public_ip' => null,
            'catch_all' => (bool) preg_match('/^https:\\/\\/:' . preg_quote((string) $port, '/') . '\\b/m', $body),
        ];
        if (preg_match('/^https:\\/\\/(\\d{1,3}(?:\\.\\d{1,3}){3}):' . preg_quote((string) $port, '/') . '\\b/m', $body, $m)) {
            $out['public_ip'] = $m[1];
        }
        if (preg_match('/^#\\s*azerioid-managed panel\\b.*\\bdomain=(\\S+)/m', $body, $m)) {
            $out['domain'] = strtolower($m[1]);
        }
        if (preg_match('/^#\\s*azerioid-managed panel\\b.*\\btls=(\\S+)/m', $body, $m)) {
            try {
                $out['tls_mode'] = TlsMode::normalize($m[1]);
            } catch (BrokerException) {
            }
        }
        if (preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)\\s*\\{/m', $body, $m)) {
            $label = strtolower($m[1]);
            if (!str_starts_with($label, 'http') && !str_starts_with($label, ':') && !filter_var($label, FILTER_VALIDATE_IP)) {
                $out['domain'] = $out['domain'] ?? $label;
            }
        }
        if (preg_match('/^\\s*tls\\s+(\\/\\S+)\\s+(\\/\\S+)/m', $body, $tm)) {
            $out['tls_cert'] = $tm[1];
            $out['tls_key'] = $tm[2];
        }

        return $out;
    }

    public function snippetPath(Config $config): string
    {
        return rtrim($config->caddyConfD, '/') . '/' . self::SNIPPET;
    }

    public function detectPublicIp(Runtime $runtime): ?string
    {
        $result = $runtime->exec(['/usr/sbin/ip', '-4', 'route', 'get', '1.1.1.1'], null, 5);
        $text = $result->ok() ? $result->stdout : '';
        if ($text === '') {
            $result = $runtime->exec(['/bin/ip', '-4', 'route', 'get', '1.1.1.1'], null, 5);
            $text = $result->ok() ? $result->stdout : '';
        }
        if (preg_match('/\\bsrc\\s+(\\d{1,3}(?:\\.\\d{1,3}){3})\\b/', $text, $m) && !str_starts_with($m[1], '127.')) {
            return $m[1];
        }

        return null;
    }

    private function resolvePublicIp(Runtime $runtime, Config $config, array $current): ?string
    {
        foreach ([$config->panelPublicIp, $current['public_ip'] ?? null, $this->detectPublicIp($runtime)] as $ip) {
            if (is_string($ip) && $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) {
                return $ip;
            }
        }

        return null;
    }

    private function assertNotSiteVhost(Runtime $runtime, Config $config, string $domain): void
    {
        $files = $runtime->glob(rtrim($config->caddyConfD, '/') . '/*.conf');
        foreach ($files as $file) {
            if (strtolower(basename((string) $file)) === self::SNIPPET) {
                continue;
            }
            try {
                $contents = $runtime->readFile((string) $file);
            } catch (\Throwable) {
                continue;
            }
            $parsed = CaddyParser::parseFile((string) $file, $contents, $config->readonlyVhosts);
            if (!($parsed['active'] ?? false)) {
                continue;
            }
            $hosts = array_map('strtolower', $parsed['domains'] ?? []);
            if (in_array($domain, $hosts, true) || strtolower((string) ($parsed['domain'] ?? '')) === $domain) {
                throw new BrokerException(
                    "{$domain} is already a site vhost; choose a different panel domain.",
                    3
                );
            }
        }
    }

    /**
     * @param  array{domain:?string,tls_mode:string,tls_cert:?string,tls_key:?string,public_ip:?string}  $spec
     * @return array<string,mixed>
     */
    private function commit(Runtime $runtime, Config $config, array $spec, string $newBody, string $appUrl): array
    {
        $snippet = $this->snippetPath($config);
        $envPath = rtrim($config->panelRoot, '/') . '/web/.env';
        $brokerPath = $config->brokerConfigPath;
        $oldSnippet = $runtime->fileExists($snippet) ? $runtime->readFile($snippet) : null;
        $oldEnv = $runtime->fileExists($envPath) ? $runtime->readFile($envPath) : null;
        $oldBroker = $runtime->fileExists($brokerPath) ? $runtime->readFile($brokerPath) : null;

        $dir = dirname($snippet);
        if (!$runtime->isDir($dir)) {
            $runtime->mkdir($dir, 0755);
        }
        $runtime->writeFile($snippet, $newBody, 0644);
        if ($oldEnv !== null || $runtime->fileExists($envPath)) {
            $this->setEnvKey($runtime, $envPath, 'APP_URL', $appUrl);
            try {
                $runtime->chown($envPath, $config->phpUser, $config->phpGroup);
            } catch (\Throwable) {
            }
        }
        BrokerConfigWriter::merge($runtime, $brokerPath, [
            'panel' => [
                'domain' => $spec['domain'] ?? '',
                'tls_mode' => $spec['tls_mode'],
                'tls_cert' => $spec['tls_cert'] ?? '',
                'tls_key' => $spec['tls_key'] ?? '',
                'public_ip' => $spec['public_ip'] ?? '',
            ],
        ]);
        $config->panelDomain = $spec['domain'];
        $config->panelDomainTlsMode = $spec['tls_mode'];
        $config->panelDomainTlsCert = $spec['tls_cert'];
        $config->panelDomainTlsKey = $spec['tls_key'];
        $config->panelPublicIp = $spec['public_ip'];

        try {
            $applied = CaddyApply::run($runtime, $config, 'auto');
        } catch (\Throwable $e) {
            if ($oldSnippet !== null) {
                $runtime->writeFile($snippet, $oldSnippet, 0644);
            } else {
                $runtime->deleteFile($snippet);
            }
            if ($oldEnv !== null) {
                $runtime->writeFile($envPath, $oldEnv, 0640);
            }
            if ($oldBroker !== null) {
                $runtime->writeFile($brokerPath, $oldBroker, 0600);
            }
            try {
                CaddyApply::run($runtime, $config, 'auto');
            } catch (\Throwable) {
            }
            throw $e instanceof BrokerException ? $e : new BrokerException($e->getMessage(), 1);
        }

        $this->reloadPanelFpm($runtime, $config);

        $status = $this->status($runtime, $config);
        $status['apply'] = $applied;
        $status['app_url'] = $appUrl;

        return $status;
    }

    private function reloadPanelFpm(Runtime $runtime, Config $config): void
    {
        $unit = $config->panelFpmUnit !== ''
            ? $config->panelFpmUnit
            : $config->phpFpmService($config->panelPhpVersion, $runtime);
        try {
            $runtime->exec(['/usr/bin/systemctl', 'reload', $unit], null, 30);
        } catch (\Throwable) {
        }
    }

    private function appUrl(?string $domain, ?string $publicIp, int $port): string
    {
        if ($domain !== null && $domain !== '') {
            return 'https://' . $domain;
        }
        if ($publicIp !== null && $publicIp !== '') {
            return 'https://' . $publicIp . ':' . $port;
        }

        return 'http://127.0.0.1:' . $port;
    }

    private function setEnvKey(Runtime $runtime, string $path, string $key, string $value): void
    {
        $body = $runtime->fileExists($path) ? $runtime->readFile($path) : '';
        $line = $key . '=' . $value;
        if (preg_match('/^' . preg_quote($key, '/') . '=/m', $body) === 1) {
            $body = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $body, 1) ?? ($body . "\n" . $line . "\n");
        } else {
            $body = rtrim($body) . "\n" . $line . "\n";
        }
        $runtime->writeFile($path, $body, 0640);
    }

    private function commonBlock(string $web, string $sock, bool $hsts): string
    {
        $hstsLine = $hsts
            ? "        Strict-Transport-Security \"max-age=31536000; includeSubDomains\"\n"
            : '';

        return <<<CADDY
    encode gzip zstd
    import /var/lib/azerioid-panel/caddy-terminal-routes.conf
    root * {$web}
    php_fastcgi {$sock} {
        dial_timeout 10s
        read_timeout 35s
    }
    file_server
    header {
{$hstsLine}        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        Referrer-Policy no-referrer
        -Server
        -Alt-Svc
    }
    log {
        output file /var/log/caddy/access_azerioid-panel.log {
            roll_size 16mb
            roll_keep 3
            roll_keep_for 7d
        }
    }
CADDY;
    }
}
