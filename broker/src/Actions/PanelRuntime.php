<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Web\PanelCaddy;

final class PanelRuntime
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $queueUnit = $config->panelRuntimeQueueUnit;
        $queueStatus = Systemd::show($runtime, $queueUnit);
        $domain = (new PanelCaddy())->status($runtime, $config);

        return [
            'php_version' => $config->panelPhpVersion,
            'fpm_socket' => $config->panelFpmSocket,
            'fpm_pool' => $config->panelFpmPool,
            'fpm_service' => $config->phpFpmService($config->panelPhpVersion),
            'queue_unit' => $queueUnit,
            'queue_active' => ($queueStatus['active'] ?? '') === 'active',
            'queue_status' => $queueStatus,
            'system' => true,
            'removable' => false,
            'domain' => $domain['domain'],
            'tls_mode' => $domain['tls_mode'],
            'tls_status' => $domain['tls_status'],
            'app_url' => $domain['app_url'],
            'fallback_urls' => $domain['fallback_urls'],
            'catch_all' => $domain['catch_all'],
            'public_ip' => $domain['public_ip'],
            'note' => $domain['note'],
        ];
    }
}
