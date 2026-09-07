<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\CertProbe;
use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\WebServers;

final class VhostList
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $vhosts = WebServers::for($config)->listVhosts($runtime, $config);
        $probe = !isset($input['probe_certs']) || filter_var($input['probe_certs'], FILTER_VALIDATE_BOOLEAN);
        if (!$probe) {
            return ['vhosts' => $vhosts];
        }

        foreach ($vhosts as &$v) {
            $mode = (string) ($v['tls_mode'] ?? ( ! empty($v['tls']) ? TlsMode::AUTO : TlsMode::OFF));
            $v['tls_mode'] = $mode;
            $v['tls'] = TlsMode::enabled($mode);
            if (!TlsMode::enabled($mode)) {
                $v['tls_status'] = [
                    'enabled' => false,
                    'mode' => TlsMode::OFF,
                    'issuer_type' => 'none',
                    'issuer' => null,
                    'valid_to' => null,
                    'days_remaining' => null,
                    'label' => 'http',
                ];
                continue;
            }
            $domain = (string) ($v['domain'] ?? '');
            try {
                // Skip openssl probe for listen-style panel hosts that aren't SNI on :443.
                Validator::domain($domain);
            } catch (\Throwable) {
                $v['tls_status'] = [
                    'enabled' => true,
                    'mode' => $mode,
                    'issuer_type' => $mode === TlsMode::INTERNAL ? 'self_signed' : 'unknown',
                    'issuer' => null,
                    'valid_to' => null,
                    'days_remaining' => null,
                    'label' => $mode === TlsMode::INTERNAL ? 'self-signed' : $mode,
                ];
                continue;
            }
            $info = CertProbe::probe($runtime, $domain, $mode);
            $label = match ($info['issuer_type']) {
                'lets_encrypt' => 'Let\'s Encrypt',
                'dns01' => 'DNS-01 (Let\'s Encrypt)',
                'self_signed' => 'self-signed',
                'pending' => 'pending',
                'none' => 'http',
                default => 'TLS',
            };
            if ($info['ok'] && $info['valid_to']) {
                $label .= ' · exp ' . substr((string) $info['valid_to'], 0, 16);
            }
            $v['tls_status'] = [
                'enabled' => true,
                'mode' => $mode,
                'issuer_type' => $info['issuer_type'],
                'issuer' => $info['issuer'],
                'valid_from' => $info['valid_from'],
                'valid_to' => $info['valid_to'],
                'days_remaining' => $info['days_remaining'],
                'renewal' => $info['renewal'],
                'ok' => $info['ok'],
                'error' => $info['error'],
                'label' => $label,
            ];
        }
        unset($v);

        return ['vhosts' => $vhosts];
    }
}
