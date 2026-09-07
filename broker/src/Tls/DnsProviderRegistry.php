<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tls;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Small registry of certbot DNS plugins (Cloudflare, DigitalOcean in v1).
 * Pattern mirrors registry/components — JSON files under registry/dns-providers/.
 */
final class DnsProviderRegistry
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function directory(): string
    {
        $panel = rtrim($this->config->panelRoot, '/');
        $candidates = [
            $panel . '/registry/dns-providers',
            dirname($this->config->registryComponentsPath) . '/dns-providers',
        ];
        foreach ($candidates as $dir) {
            if ($this->runtime->isDir($dir)) {
                return $dir;
            }
        }

        return $candidates[0];
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $dir = $this->directory();
        if (!$this->runtime->isDir($dir)) {
            return [];
        }
        $out = [];
        foreach ($this->runtime->glob(rtrim($dir, '/') . '/*.json') as $file) {
            try {
                $data = json_decode($this->runtime->readFile($file), true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($data) || empty($data['id'])) {
                continue;
            }
            $out[] = $data;
        }
        usort($out, static fn ($a, $b) => strcmp((string) $a['id'], (string) $b['id']));

        return $out;
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        $id = strtolower(trim($id));
        foreach ($this->list() as $row) {
            if (($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        throw new BrokerException("Unknown DNS provider '{$id}'.", 2);
    }

    public function credentialsPath(string $providerId): string
    {
        $providerId = strtolower(trim($providerId));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,32}$/', $providerId)) {
            throw new BrokerException('Invalid DNS provider id.', 2);
        }

        return '/etc/azerioid-panel/dns-credentials/' . $providerId . '.ini';
    }
}
