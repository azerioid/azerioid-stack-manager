#!/usr/bin/env php
<?php
/**
 * AZERIOID Stack Manager privileged broker.
 *
 * Enumerated actions only. Arguments are re-validated here. Commands are
 * executed as argv arrays via proc_open — never interpolated into a shell.
 *
 * Usage: broker <action> [arg ...]
 * Secrets (passwords) MUST be passed as JSON on stdin, never argv.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\PosixRuntime;

$configPath = getenv('AZERIOID_PANEL_CONFIG')
    ?: getenv('LACMP_PANEL_CONFIG')
    ?: '/etc/azerioid-panel/broker.json';
$runtime = new PosixRuntime();
$config = Config::load($configPath, $runtime);
$kernel = new Kernel($config, $runtime);

$stdin = [];
if (defined('STDIN') && is_resource(STDIN)) {
    $raw = stream_get_contents(STDIN);
    if (is_string($raw)) {
        try {
            $stdin = Kernel::decodeStdinString($raw);
        } catch (\AzerioidPanel\Broker\BrokerException $e) {
            fwrite(STDOUT, json_encode([
                'ok' => false,
                'data' => null,
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ], JSON_UNESCAPED_SLASHES) . "\n");
            exit($e->errorCode);
        }
    }
}

exit($kernel->run($argv, $stdin));
