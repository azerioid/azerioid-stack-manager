<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Vhost\VhostRegistration;

/**
 * Caddy is the permanent front door on :80/:443. Apache and Nginx serve
 * selected vhosts on loopback; Caddy reverse-proxies to them by Host.
 */
final class VhostFrontRouter implements WebServerDriver
{
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
        $sites = (new CaddyDriver())->listVhosts($runtime, $config);
        foreach ($sites as &$site) {
            $site['engine'] = VhostEngine::normalize($site['engine'] ?? VhostEngine::CADDY);
        }
        unset($site);

        return $sites;
    }

    public function addVhost(Runtime $runtime, Config $config, array $spec): array
    {
        $engine = $this->resolveEngine($spec);
        $spec['engine'] = $engine;
        $this->assertEngineAvailable($runtime, $config, $engine);
        if (VhostEngine::isBackend($engine)) {
            (new BackendEngineBind($runtime, $config))->ensure($engine);
        }

        $caddy = new CaddyDriver();
        $result = $caddy->addVhost($runtime, $config, $spec);
        if (VhostEngine::isBackend($engine)) {
            try {
                $this->backendDriver($engine)->upsertBackendVhost(
                    $runtime,
                    $this->backendConfig($runtime, $config, $engine),
                    $spec
                );
            } catch (\Throwable $e) {
                try {
                    $caddy->removeVhost($runtime, $config, (string) $spec['domain']);
                } catch (\Throwable) {
                }
                throw $e instanceof BrokerException ? $e : new BrokerException($e->getMessage(), 1);
            }
        }
        $result['engine'] = $engine;

        return $result;
    }

    public function removeVhost(Runtime $runtime, Config $config, string $domain): array
    {
        $this->removeBackendIfPresent($runtime, $config, VhostEngine::APACHE, $domain);
        $this->removeBackendIfPresent($runtime, $config, VhostEngine::NGINX, $domain);

        return (new CaddyDriver())->removeVhost($runtime, $config, $domain);
    }

    public function updateVhost(Runtime $runtime, Config $config, string $domain, array $changes): array
    {
        $caddy = new CaddyDriver();
        $parsed = $this->findSite($runtime, $config, $domain);
        if ($parsed === null) {
            throw new BrokerException('Vhost config does not exist.', 3);
        }
        $oldEngine = VhostEngine::normalize($parsed['engine'] ?? VhostEngine::CADDY);
        $newEngine = isset($changes['engine'])
            ? VhostEngine::normalize($changes['engine'])
            : $oldEngine;
        if ((string) ($parsed['type'] ?? '') === 'proxy') {
            $newEngine = VhostEngine::CADDY;
        }
        $changes['engine'] = $newEngine;
        $this->assertEngineAvailable($runtime, $config, $newEngine);

        $merged = $this->backendSpec($parsed, $changes);
        $merged['engine'] = $newEngine;

        if (VhostEngine::isBackend($newEngine)) {
            (new BackendEngineBind($runtime, $config))->ensure($newEngine);
            $this->backendDriver($newEngine)->upsertBackendVhost(
                $runtime,
                $this->backendConfig($runtime, $config, $newEngine),
                $merged
            );
        }

        try {
            $result = $caddy->updateVhost($runtime, $config, $domain, $changes);
        } catch (\Throwable $e) {
            if ($newEngine !== $oldEngine && VhostEngine::isBackend($newEngine)) {
                $this->removeBackendIfPresent($runtime, $config, $newEngine, $domain);
            }
            throw $e instanceof BrokerException ? $e : new BrokerException($e->getMessage(), 1);
        }

        if ($oldEngine !== $newEngine && VhostEngine::isBackend($oldEngine)) {
            $this->removeBackendIfPresent($runtime, $config, $oldEngine, $domain);
        }

        $result['engine'] = $newEngine;
        if (isset($result['after']) && is_array($result['after'])) {
            $result['after']['engine'] = $newEngine;
        }
        if (isset($result['before']) && is_array($result['before'])) {
            $result['before']['engine'] = $oldEngine;
        }

        return $result;
    }

    public function reload(Runtime $runtime, Config $config, string $mode = 'auto', array $expectPorts = []): array
    {
        return CaddyApply::run($runtime, $config, $mode, $expectPorts);
    }

    public function backupPaths(Config $config): array
    {
        return array_values(array_unique(array_merge(
            (new CaddyDriver())->backupPaths($config),
            ['/etc/apache2/sites-available', '/etc/apache2/sites-enabled', '/etc/httpd/conf.d'],
            ['/etc/nginx/sites-available', '/etc/nginx/sites-enabled', '/etc/nginx/conf.d'],
        )));
    }

    public function version(Runtime $runtime, Config $config): array
    {
        return (new CaddyDriver())->version($runtime, $config);
    }

    /**
     * Rebind backends and fold leftover Apache/Nginx :80/:443 vhosts behind Caddy.
     *
     * @return array<string,mixed>
     */
    public function migrate(Runtime $runtime, Config $config): array
    {
        $bind = new BackendEngineBind($runtime, $config);
        $ensured = [];
        foreach ([VhostEngine::APACHE, VhostEngine::NGINX] as $engine) {
            if ($bind->installed($engine)) {
                $ensured[$engine] = $bind->ensure($engine);
            }
        }

        $migrated = [];
        $existing = [];
        foreach ($this->listVhosts($runtime, $config) as $site) {
            $existing[strtolower((string) $site['domain'])] = VhostEngine::normalize($site['engine'] ?? VhostEngine::CADDY);
        }

        foreach ([VhostEngine::APACHE, VhostEngine::NGINX] as $engine) {
            if (!$bind->installed($engine)) {
                continue;
            }
            $driver = $this->backendDriver($engine);
            $bconf = $this->backendConfig($runtime, $config, $engine);
            foreach ($driver->listVhosts($runtime, $bconf) as $site) {
                $domain = strtolower((string) ($site['domain'] ?? ''));
                if ($domain === '' || $domain === '_' || !empty($site['readonly'])) {
                    continue;
                }
                $frontEngine = $existing[$domain] ?? null;
                if ($frontEngine !== null && $frontEngine !== $engine) {
                    // Caddy already fronts this domain on a different engine (including
                    // direct-serve). Leftover Apache/Nginx site files must not steal it.
                    $this->removeBackendIfPresent($runtime, $config, $engine, $domain);
                    $migrated[] = [
                        'domain' => $domain,
                        'engine' => $frontEngine,
                        'front' => 'kept',
                        'dropped_leftover' => $engine,
                    ];
                    continue;
                }
                $spec = [
                    'domain' => (string) $site['domain'],
                    'root' => (string) ($site['root'] ?? ''),
                    'type' => (string) ($site['type'] ?? 'static'),
                    'php_version' => $site['php_version'] ?? null,
                    'upstream' => $site['reverse_proxy'] ?? null,
                    'engine' => $engine,
                ];
                $driver->upsertBackendVhost($runtime, $bconf, $spec);
                if ($frontEngine === null) {
                    $tlsMode = (string) ($site['tls_mode'] ?? TlsMode::OFF);
                    $this->addFrontOnly($runtime, $config, $spec, $tlsMode);
                    $existing[$domain] = $engine;
                    $migrated[] = ['domain' => $domain, 'engine' => $engine, 'front' => 'created'];
                } else {
                    $migrated[] = ['domain' => $domain, 'engine' => $engine, 'front' => 'already-present'];
                }
            }
        }

        return [
            'ensured' => $ensured,
            'migrated' => $migrated,
            'note' => 'Caddy remains on :80/:443. Apache/Nginx listen only on loopback backend ports.',
        ];
    }

    /**
     * @param array<string,mixed> $spec
     */
    private function addFrontOnly(Runtime $runtime, Config $config, array $spec, string $tlsMode): void
    {
        $domain = strtolower((string) $spec['domain']);
        $caddy = new CaddyDriver();
        if (VhostRegistration::findDomain($caddy->listVhosts($runtime, $config), $domain) !== null) {
            return;
        }
        try {
            $caddy->addVhost($runtime, $config, $spec);
        } catch (BrokerException $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }

            return;
        }
        if ($tlsMode !== TlsMode::OFF && TlsMode::enabled($tlsMode)) {
            try {
                $caddy->updateVhost($runtime, $config, (string) $spec['domain'], [
                    'tls_mode' => $tlsMode,
                    'engine' => $spec['engine'],
                ]);
            } catch (BrokerException) {
            }
        }
    }

    /** @param array<string,mixed> $spec */
    private function resolveEngine(array $spec): string
    {
        $engine = VhostEngine::normalize($spec['engine'] ?? VhostEngine::CADDY);
        if (($spec['type'] ?? '') === 'proxy') {
            return VhostEngine::CADDY;
        }

        return $engine;
    }

    private function assertEngineAvailable(Runtime $runtime, Config $config, string $engine): void
    {
        if ($engine === VhostEngine::CADDY) {
            return;
        }
        $bind = new BackendEngineBind($runtime, $config);
        if (!$bind->installed($engine)) {
            $label = $engine === VhostEngine::APACHE ? 'Apache' : 'Nginx';
            throw new BrokerException(
                "{$label} is not installed. Install it from Components before creating an {$label}-engine vhost.",
                3
            );
        }
    }

    private function backendDriver(string $engine): ApacheDriver|NginxDriver
    {
        return $engine === VhostEngine::APACHE ? new ApacheDriver(new Config()) : new NginxDriver(new Config());
    }

    private function backendConfig(Runtime $runtime, Config $config, string $engine): Config
    {
        return $engine === VhostEngine::APACHE
            ? $config->forApacheBackend($runtime)
            : $config->forNginxBackend($runtime);
    }

    private function removeBackendIfPresent(Runtime $runtime, Config $config, string $engine, string $domain): void
    {
        $bind = new BackendEngineBind($runtime, $config);
        if (!$bind->installed($engine)) {
            return;
        }
        try {
            $this->backendDriver($engine)->removeBackendVhost(
                $runtime,
                $this->backendConfig($runtime, $config, $engine),
                $domain
            );
        } catch (BrokerException) {
        }
    }

    /** @return array<string,mixed>|null */
    private function findSite(Runtime $runtime, Config $config, string $domain): ?array
    {
        return VhostRegistration::findDomain($this->listVhosts($runtime, $config), $domain);
    }

    /**
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function backendSpec(array $parsed, array $changes): array
    {
        return [
            'domain' => (string) ($parsed['domain'] ?? ''),
            'root' => (string) ($changes['root'] ?? ($parsed['root'] ?? '')),
            'type' => (string) ($parsed['type'] ?? 'static'),
            'php_version' => $changes['php_version'] ?? ($parsed['php_version'] ?? null),
            'upstream' => $parsed['reverse_proxy'] ?? null,
        ];
    }
}
