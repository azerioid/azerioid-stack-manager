<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\BrokerConfigWriter;
use AzerioidPanel\Broker\Os\DistroPaths;
use AzerioidPanel\Broker\Runtime;

final class SiteWebConfig
{
    /** @return array<string, mixed> */
    public static function pathsFor(string $componentId, Runtime $runtime, Config $config): array
    {
        $layout = DistroPaths::for($runtime, $config);

        return match ($componentId) {
            'nginx' => [
                'web_server' => 'nginx',
                'web_service' => $layout->nginxUnit(),
                'vhost_format' => 'nginx',
                'site_web_server' => 'nginx',
                'paths' => [
                    'vhost_dir' => ($nginx = $layout->nginxSiteLayout())['vhost_dir'],
                    'vhost_available' => $nginx['vhost_available'],
                    'web_log_dir' => $nginx['web_log_dir'],
                ],
            ],
            'apache' => [
                'web_server' => 'apache',
                'web_service' => $layout->apacheUnit(),
                'vhost_format' => 'apache',
                'site_web_server' => 'apache',
                'stack' => 'lamp',
                'paths' => [
                    'vhost_dir' => ($apache = $layout->apacheSiteLayout())['vhost_dir'],
                    'vhost_available' => $apache['vhost_available'],
                    'web_log_dir' => $apache['web_log_dir'],
                    'apache_ctl' => $apache['apache_ctl'],
                ],
            ],
            'caddy' => [
                'web_server' => 'caddy',
                'web_service' => 'caddy',
                'vhost_format' => 'caddyfile',
                'site_web_server' => 'caddy',
                'stack' => 'lcmp',
                'paths' => [
                    'vhost_dir' => '/etc/caddy/conf.d',
                    'vhost_available' => '',
                    'web_log_dir' => '/var/log/caddy',
                ],
            ],
            default => throw new BrokerException('Component is not a site web server.', 2),
        };
    }

    public static function apply(Runtime $runtime, Config $config, string $componentId, OsRelease $os): void
    {
        unset($os);
        $configPath = getenv('AZERIOID_PANEL_CONFIG') ?: getenv('LACMP_PANEL_CONFIG') ?: '/etc/azerioid-panel/broker.json';
        BrokerConfigWriter::merge($runtime, $configPath, self::pathsFor($componentId, $runtime, $config));
    }
}
