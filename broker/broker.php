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

$stdin = null;
if (defined('STDIN') && is_resource(STDIN)) {
    $raw = stream_get_contents(STDIN);
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            fwrite(STDOUT, json_encode([
                'ok' => false,
                'data' => null,
                'error' => 'Stdin must be a JSON object when provided.',
                'code' => 2,
            ], JSON_UNESCAPED_SLASHES) . "\n");
            exit(2);
        }
        $stdin = $decoded;
    } else {
        $stdin = [];
    }
}

exit($kernel->run($argv, $stdin));
