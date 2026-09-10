<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\WebServers;

final class VhostEdit
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $domain = Validator::domain($args[0] ?? ($input['domain'] ?? ''));

        if (isset($input['new_domain']) && strtolower(trim((string) $input['new_domain'])) !== $domain) {
            throw new BrokerException(
                'Renaming a vhost domain is not supported; delete and recreate the vhost (certificates, DNS, and config filenames all depend on the domain).',
                3
            );
        }
        if (isset($input['type']) || isset($input['new_type'])) {
            throw new BrokerException(
                'Changing vhost type is not supported; delete and recreate the vhost (PHP, static, and reverse-proxy blocks are not interchangeable).',
                3
            );
        }

        $blocked = array_map('strtolower', $config->readonlyVhosts);
        if (in_array($domain, $blocked, true) || $domain === 'default' || $domain === 'azerioid-panel') {
            throw new BrokerException("{$domain} is managed externally and can't be edited.", 3);
        }

        $changes = [];
        if (array_key_exists('root', $input) || isset($args[1])) {
            $changes['root'] = Validator::webRoot(
                (string) ($args[1] ?? $input['root']),
                $config->wwwRoot,
                $runtime
            );
        }
        if (array_key_exists('php_version', $input) || isset($args[2])) {
            $changes['php_version'] = Validator::phpVersion(
                (string) ($args[2] ?? $input['php_version']),
                $runtime->phpVersions()
            );
        }
        if (array_key_exists('tls', $input) || isset($args[3])) {
            $changes['tls'] = self::parseTls($args[3] ?? $input['tls'] ?? null);
        }
        if (array_key_exists('tls_mode', $input)) {
            $changes['tls_mode'] = \AzerioidPanel\Broker\Tls\TlsMode::normalize($input['tls_mode']);
        }
        if (array_key_exists('tls_cert', $input)) {
            $changes['tls_cert'] = (string) $input['tls_cert'];
        }
        if (array_key_exists('tls_key', $input)) {
            $changes['tls_key'] = (string) $input['tls_key'];
        }
        if (array_key_exists('engine', $input)) {
            $changes['engine'] = Validator::vhostEngine((string) $input['engine']);
        }
        if (array_key_exists('upstream', $input) || isset($args[4])) {
            $changes['upstream'] = Validator::localUpstream((string) ($args[4] ?? $input['upstream']));
        }

        if ($changes === []) {
            throw new BrokerException('No editable fields provided (root, php_version, tls, tls_mode, engine, upstream).', 2);
        }

        $mode = null;
        if (isset($changes['tls_mode'])) {
            $mode = TlsMode::effective((string) $changes['tls_mode'], $domain);
            $changes['tls_mode'] = $mode;
        } elseif (isset($changes['tls'])) {
            $mode = TlsMode::effective(
                TlsMode::normalize((bool) $changes['tls']),
                $domain
            );
            $changes['tls_mode'] = $mode;
        }

        $issuer = new \AzerioidPanel\Broker\Tls\VhostTlsIssuer($runtime, $config);
        $issued = null;

        // DNS-01: obtain cert before rewriting vhost config (needs static file paths).
        if ($mode === TlsMode::DNS01) {
            $issued = $issuer->ensure($domain, TlsMode::DNS01, $input);
            if (is_array($issued)) {
                $changes['tls_cert'] = $issued['cert'] ?? null;
                $changes['tls_key'] = $issued['key'] ?? null;
            }
        }

        $result = WebServers::for($config)->updateVhost($runtime, $config, $domain, $changes);

        // TLS is always terminated by Caddy; auto uses native HTTP-01 regardless of backend engine.
        if ($mode === TlsMode::AUTO) {
            $issued = $issuer->ensure($domain, TlsMode::AUTO, $input);
        }

        if ($issued !== null) {
            $result['tls_issue'] = $issued;
            if (isset($issued['cert'])) {
                $result['tls_cert'] = $issued['cert'];
            }
            $result['tls_mode'] = $mode;
        }

        return $result;
    }

    private static function parseTls(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new BrokerException('tls must be true or false.', 2),
        };
    }
}
