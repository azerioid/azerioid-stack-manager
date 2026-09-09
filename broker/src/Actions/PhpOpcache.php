<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Os\DistroPaths;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class PhpOpcache
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $version = Validator::phpVersion((string) ($args[0] ?? ''), $runtime->phpVersions());
        $sock = DistroPaths::for($runtime, $config)->phpFpmUnixSocket($version);
        $cachetool = $runtime->fileExists('/usr/local/bin/cachetool')
            ? '/usr/local/bin/cachetool'
            : ($runtime->fileExists('/usr/bin/cachetool') ? '/usr/bin/cachetool' : null);
        if ($cachetool === null) {
            return [
                'php_version' => $version,
                'available' => false,
                'error' => 'cachetool is not installed; FPM OPcache cannot be inspected from CLI.',
            ];
        }
        if ($action === 'php.opcache.reset') {
            $result = $runtime->exec([$cachetool, 'opcache:reset', '--fcgi=' . $sock], null, 20);
            if (!$result->ok()) {
                throw new BrokerException(trim($result->stderr) ?: 'opcache reset failed.', 1);
            }
            return ['php_version' => $version, 'reset' => true, 'available' => true];
        }
        $result = $runtime->exec([$cachetool, 'opcache:status', '--fcgi=' . $sock], null, 20);
        return [
            'php_version' => $version,
            'available' => $result->ok(),
            'raw' => trim($result->stdout . "\n" . $result->stderr),
        ];
    }
}
