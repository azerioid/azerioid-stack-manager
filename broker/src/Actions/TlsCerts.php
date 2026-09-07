<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Tls\CertProbe;
use AzerioidPanel\Broker\Tls\TlsMode;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\WebServers;

final class TlsCerts
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $seen = [];
        $certs = [];
        foreach (WebServers::for($config)->listVhosts($runtime, $config) as $parsed) {
            $mode = (string) ($parsed['tls_mode'] ?? ( ! empty($parsed['tls']) ? TlsMode::AUTO : TlsMode::OFF));
            if (!TlsMode::enabled($mode)) {
                continue;
            }
            foreach ($parsed['domains'] ?? [] as $domain) {
                try {
                    $domain = Validator::domain((string) $domain);
                } catch (\Throwable) {
                    continue;
                }
                if (isset($seen[$domain])) {
                    continue;
                }
                $seen[$domain] = true;
                $certs[] = CertProbe::probe($runtime, $domain, $mode) + [
                    'tls_mode' => $mode,
                ];
            }
        }
        usort($certs, static fn ($a, $b) => ($a['days_remaining'] ?? 9999) <=> ($b['days_remaining'] ?? 9999));

        return ['certs' => $certs];
    }
}
