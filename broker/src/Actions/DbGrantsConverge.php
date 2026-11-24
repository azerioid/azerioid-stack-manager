<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseManager;
use AzerioidPanel\Broker\Database\MariaDBDriver;
use AzerioidPanel\Broker\Database\PostgreSQLDriver;
use AzerioidPanel\Broker\Runtime;

/**
 * db.grants.converge (A108): bring grants made before A108 in line — MariaDB database
 * grants onto escaped names, PostgreSQL site databases closed to PUBLIC. Idempotent;
 * the scheduler runs it, and an engine that is not configured is skipped.
 */
final class DbGrantsConverge
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new DatabaseManager($config, $runtime);
        $out = ['mariadb' => null, 'postgresql' => null];
        foreach (['mariadb', 'postgresql'] as $engine) {
            $driver = $manager->driver($engine);
            if (!$driver->isConfigured()) {
                continue;
            }
            $out[$engine] = match (true) {
                $driver instanceof MariaDBDriver => $driver->convergeGrantPatterns(),
                $driver instanceof PostgreSQLDriver => $driver->convergePublicAccess(),
                default => null,
            };
        }

        return $out;
    }
}
