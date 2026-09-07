<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\DnsProviderRegistry;

/**
 * Store DNS provider API credentials for certbot DNS-01.
 * Token arrives on stdin only — never argv. Written root-only 0600.
 */
final class TlsDnsCredential
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        return match ($action) {
            'tls.dns-providers' => $this->listProviders($runtime, $config),
            'tls.dns-credential.store' => $this->store($runtime, $config, $input),
            'tls.dns-credential.status' => $this->status($runtime, $config, $input),
            default => throw new BrokerException('Unknown TLS DNS credential action.', 2),
        };
    }

    /** @return array{providers:list<array<string,mixed>>} */
    private function listProviders(Runtime $runtime, Config $config): array
    {
        $registry = new DnsProviderRegistry($config, $runtime);
        $providers = [];
        foreach ($registry->list() as $row) {
            $id = (string) ($row['id'] ?? '');
            $path = $registry->credentialsPath($id);
            $providers[] = [
                'id' => $id,
                'display_name' => $row['display_name'] ?? $id,
                'notes' => $row['notes'] ?? '',
                'credentials_present' => $runtime->fileExists($path),
            ];
        }

        return ['providers' => $providers];
    }

    /** @param  array<string,mixed>  $input */
    private function store(Runtime $runtime, Config $config, array $input): array
    {
        $providerId = strtolower(trim((string) ($input['provider'] ?? $input['dns_provider'] ?? '')));
        $token = (string) ($input['token'] ?? $input['api_token'] ?? '');
        if ($providerId === '') {
            throw new BrokerException('provider is required.', 2);
        }
        if ($token === '' || strlen($token) < 8) {
            throw new BrokerException('API token is required (stdin JSON: token). Never pass tokens via argv.', 2);
        }
        $registry = new DnsProviderRegistry($config, $runtime);
        $provider = $registry->get($providerId);
        $template = (string) ($provider['credentials_template'] ?? "token = {token}\n");
        $body = str_replace('{token}', $token, $template);

        $dir = '/etc/azerioid-panel/dns-credentials';
        if (!$runtime->isDir($dir)) {
            $runtime->mkdir($dir, 0700);
        }
        $path = $registry->credentialsPath($providerId);
        $runtime->writeFile($path, $body, 0600);

        return [
            'provider' => $providerId,
            'path' => $path,
            'stored' => true,
            // Intentionally omit token.
        ];
    }

    /** @param  array<string,mixed>  $input */
    private function status(Runtime $runtime, Config $config, array $input): array
    {
        $providerId = strtolower(trim((string) ($input['provider'] ?? $input['dns_provider'] ?? '')));
        $registry = new DnsProviderRegistry($config, $runtime);
        if ($providerId !== '') {
            $path = $registry->credentialsPath($providerId);

            return [
                'provider' => $providerId,
                'credentials_present' => $runtime->fileExists($path),
                'path' => $path,
            ];
        }

        return $this->listProviders($runtime, $config);
    }
}
