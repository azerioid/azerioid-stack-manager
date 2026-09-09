<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tls;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Issues certificates at the Caddy front-router layer.
 *
 * Caddy + tls_mode=auto: no-op (Caddy handles Let's Encrypt HTTP-01 itself).
 * Any engine + dns01: certbot DNS plugin, then static tls <cert> <key> on the Caddy block.
 * Apache/Nginx never terminate TLS for site vhosts (plain HTTP on loopback).
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
        $staging = !empty($input['staging']) || !empty($input['acme_staging']);
        $certbot = new Certbot($this->runtime, $this->config);
        $email = $certbot->defaultEmail($domain);

        if ($mode === TlsMode::AUTO) {
            $certbot->ensureRenewalHook();

            return [
                'path' => 'caddy_native',
                'mode' => $mode,
                'note' => 'Caddy terminates TLS for every vhost and obtains/renews Let\'s Encrypt via HTTP-01 automatically for public hostnames.',
            ];
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
            $paths = $certbot->issueDns01($domains, $email, $provider, $credPath, $staging);
            $certbot->ensureRenewalHook();

            return ['path' => 'certbot_dns01', 'mode' => $mode, 'provider' => $providerId, 'staging' => $staging] + $paths;
        }

        return null;
    }
}
