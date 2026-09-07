<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\AcmeStatusHint;
use AzerioidPanel\Broker\Tls\CertProbe;
use AzerioidPanel\Broker\Tls\TlsMode;
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

        $localIps = AcmeStatusHint::localIpv4($runtime);

        foreach ($vhosts as &$v) {
            $mode = (string) ($v['tls_mode'] ?? (! empty($v['tls']) ? TlsMode::AUTO : TlsMode::OFF));
            $v['tls_mode'] = $mode;
            $v['tls'] = TlsMode::enabled($mode);
            if (!TlsMode::enabled($mode)) {
                $v['tls_status'] = [
                    'enabled' => false,
                    'mode' => TlsMode::OFF,
                    'issuer_type' => 'none',
                    'issuer' => null,
                    'valid_from' => null,
                    'valid_to' => null,
                    'days_remaining' => null,
                    'renewal' => 'n/a',
                    'ok' => true,
                    'pending' => false,
                    'failed' => false,
                    'error' => null,
                    'label' => 'No TLS',
                ];
                continue;
            }

            [$probeHost, $probePort] = self::probeTarget($v);
            $info = CertProbe::probe($runtime, $probeHost, $mode, $probePort);
            $issuerType = $info['issuer_type'];

            if ($issuerType === 'lets_encrypt' && $mode === TlsMode::DNS01) {
                $issuerType = 'dns01';
            }
            if ($issuerType === 'pending' && $mode === TlsMode::INTERNAL) {
                $issuerType = 'self_signed';
            }

            $error = $info['error'] ?? null;
            $pending = false;
            $failed = false;
            $ok = (bool) ($info['ok'] ?? false);

            if (!$ok && in_array($mode, [TlsMode::AUTO, TlsMode::DNS01], true)) {
                $hint = AcmeStatusHint::explainMissingCert($runtime, $probeHost, $localIps);
                $error = $hint;
                if (str_starts_with($hint, 'Failed')) {
                    $issuerType = 'failed';
                    $failed = true;
                    $pending = false;
                } else {
                    $issuerType = 'pending';
                    $pending = true;
                }
            } elseif (!$ok && $mode === TlsMode::INTERNAL) {
                $rawErr = trim((string) ($error ?? ''));
                if ($rawErr === '' || str_contains(strtolower($rawErr), 'wrong version') || str_contains(strtolower($rawErr), 'verify return')) {
                    $error = 'Self-signed certificate not captured on ' . $probeHost . ':' . $probePort;
                }
                $issuerType = 'self_signed';
                $failed = true;
            }

            $label = match ($issuerType) {
                'lets_encrypt' => 'Let\'s Encrypt (HTTP-01)',
                'dns01' => 'Let\'s Encrypt (DNS-01)',
                'self_signed' => 'self-signed',
                'pending' => 'pending',
                'failed' => 'failed',
                'none' => 'No TLS',
                default => 'TLS (unknown)',
            };
            if ($ok && $info['valid_to']) {
                $label .= ' · exp ' . substr((string) $info['valid_to'], 0, 16);
            } elseif (is_string($error) && $error !== '') {
                $short = strlen($error) > 72 ? substr($error, 0, 69) . '…' : $error;
                $label .= ' · ' . $short;
            }

            $v['tls_status'] = [
                'enabled' => true,
                'mode' => $mode,
                'issuer_type' => $issuerType,
                'issuer' => $info['issuer'],
                'valid_from' => $info['valid_from'],
                'valid_to' => $info['valid_to'],
                'days_remaining' => $info['days_remaining'],
                'renewal' => $info['renewal'] ?? 'unknown',
                'ok' => $ok,
                'pending' => $pending,
                'failed' => $failed,
                'error' => $error,
                'label' => $label,
                'probe_host' => $probeHost,
                'probe_port' => $probePort,
            ];
        }
        unset($v);

        return ['vhosts' => $vhosts];
    }

    /**
     * Pick host+port for openssl SNI probe.
     * Panel conf lists http://127.0.0.1:3169 before https://PUBLIC:3169 — prefer the public TLS listen.
     *
     * @param  array<string,mixed>  $v
     * @return array{0:string,1:int}
     */
    private static function probeTarget(array $v): array
    {
        $domains = $v['domains'] ?? [];
        if (!is_array($domains) || $domains === []) {
            $domains = [(string) ($v['domain'] ?? '')];
        }

        $fallbackHost = (string) ($v['domain'] ?? '');
        $fallbackPort = 443;

        foreach ($domains as $d) {
            $d = trim((string) $d);
            if ($d === '') {
                continue;
            }
            if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/', $d, $m)) {
                $ip = $m[1];
                $port = (int) $m[2];
                if ($ip !== '127.0.0.1' && $ip !== '0.0.0.0') {
                    return [$ip, $port];
                }
                $fallbackHost = $ip;
                $fallbackPort = $port;
                continue;
            }
            if (preg_match('/^([a-z0-9.-]+):(\d+)$/i', $d, $m)) {
                return [strtolower($m[1]), (int) $m[2]];
            }
            // Plain hostname → standard HTTPS
            if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $d)) {
                return [strtolower($d), 443];
            }
        }

        if (preg_match('/^(.+):(\d+)$/', $fallbackHost, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [$fallbackHost !== '' ? $fallbackHost : '127.0.0.1', $fallbackPort];
    }
}
