<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tls;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Issues certificates and re-points driver config when native Caddy ACME is not enough.
 *
 * Caddy + tls_mode=auto: no-op (Caddy handles Let's Encrypt itself).
 * Apache/Nginx + auto: certbot HTTP-01 webroot, then rewrite SSL paths.
 * Any driver + dns01: certbot DNS plugin, then wire static cert/key into the driver.
 */
final class VhostTlsIssuer
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    public function ensure(string $domain, string $mode, array $input = []): ?array
    {
        $mode = TlsMode::effective($mode, $domain);
        $driver = WebServers::for($this->config);
        $stack = $driver->stackName();
        $web = $this->config->webServer;
        $staging = !empty($input['staging']) || !empty($input['acme_staging']);
        $certbot = new Certbot($this->runtime, $this->config);
        $email = $certbot->defaultEmail($domain);

        if ($mode === TlsMode::AUTO) {
            // Caddy native automatic HTTPS — do not route through certbot.
            if ($web === 'caddy' || $stack === 'lcmp') {
                $certbot->ensureRenewalHook();

                return [
                    'path' => 'caddy_native',
                    'mode' => $mode,
                    'note' => 'Caddy will obtain/renew Let\'s Encrypt via HTTP-01 automatically for public hostnames.',
                ];
            }
            // Apache / Nginx: certbot webroot (broker owns vhost files).
            $paths = $this->issueHttp01($domain, $email, $staging);
            $certbot = new Certbot($this->runtime, $this->config);
            $certbot->ensureRenewalHook();
            // Re-enter update with concrete cert paths (tls_mode stays auto; files mark LE).
            $driver->updateVhost($this->runtime, $this->config, $domain, [
                'tls_mode' => TlsMode::AUTO,
                'tls_cert' => $paths['cert'],
                'tls_key' => $paths['key'],
                'tls' => true,
            ]);

            return ['path' => 'certbot_http01', 'mode' => $mode, 'staging' => $staging] + $paths;
        }

        if ($mode === TlsMode::DNS01) {
            $providerId = strtolower(trim((string) ($input['dns_provider'] ?? '')));
            if ($providerId === '') {
                throw new BrokerException('dns_provider is required for DNS-01 (cloudflare|digitalocean).', 2);
            }
            $registry = new DnsProviderRegistry($this->config, $this->runtime);
            $provider = $registry->get($providerId);
            $credPath = $registry->credentialsPath($providerId);
            $domains = [$domain];
            if (!empty($input['wildcard']) || str_starts_with($domain, '*.')) {
                $apex = ltrim($domain, '*.');
                $domains = [$apex, '*.' . $apex];
            }
            $certbot = new Certbot($this->runtime, $this->config);
            $paths = $certbot->issueDns01($domains, $email, $provider, $credPath, $staging);
            $certbot->ensureRenewalHook();

            // Caller (VhostEdit) applies cert paths; avoid recursive update here.
            return ['path' => 'certbot_dns01', 'mode' => $mode, 'provider' => $providerId, 'staging' => $staging] + $paths;
        }

        return null;
    }

    /** @return array{cert:string,key:string,chain:string,staging:bool} */
    private function issueHttp01(string $domain, string $email, bool $staging): array
    {
        $certbot = new Certbot($this->runtime, $this->config);

        return $certbot->issueHttp01([$domain], $email, $staging);
    }
}
