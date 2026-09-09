<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;

final class PhpIniSet
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $version = Validator::phpVersion($args[0] ?? ($input['php_version'] ?? ''), $runtime->phpVersions());
        $key = Validator::phpIniKey($args[1] ?? ($input['key'] ?? ''));
        $value = Validator::phpIniValue($key, (string) ($args[2] ?? ($input['value'] ?? '')));

        $path = $config->phpIniPath($version, $runtime);
        if (!$runtime->fileExists($path)) {
            throw new BrokerException('php.ini not found for this version.', 3);
        }
        $ini = $runtime->readFile($path);
        $quoted = preg_quote($key, '/');
        if (preg_match('/^\s*' . $quoted . '\s*=/m', $ini)) {
            $ini = preg_replace('/^\s*' . $quoted . '\s*=.*$/m', $key . ' = ' . $value, $ini, 1) ?? $ini;
        } else {
            $ini = rtrim($ini) . "\n{$key} = {$value}\n";
        }
        $runtime->writeFile($path, $ini, 0644);
        Systemd::control($runtime, 'reload', $config->phpFpmService($version, $runtime));

        return ['php_version' => $version, 'key' => $key, 'value' => $value];
    }
}
