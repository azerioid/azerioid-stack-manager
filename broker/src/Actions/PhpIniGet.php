<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class PhpIniGet
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $version = Validator::phpVersion($args[0] ?? ($input['php_version'] ?? ''), $runtime->phpVersions());
        $path = $config->phpIniPath($version, $runtime);
        if (!$runtime->fileExists($path)) {
            throw new BrokerException('php.ini not found for this version.', 3);
        }
        $ini = $runtime->readFile($path);
        $values = [];
        foreach (Validator::ALLOWED_PHP_INI_KEYS as $key) {
            $values[$key] = self::readKey($ini, $key);
        }
        return ['php_version' => $version, 'path' => $path, 'values' => $values];
    }

    public static function readKey(string $ini, string $key): ?string
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+)$/m', $ini, $m)) {
            return trim($m[1], " \t\"'");
        }
        return null;
    }
}
