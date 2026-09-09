<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseManager;
use AzerioidPanel\Broker\Database\DbAccessApply;
use AzerioidPanel\Broker\Runtime;

final class DbList
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new DatabaseManager($config, $runtime);
        $engine = $manager->resolveEngine((string) ($input['engine'] ?? ''));
        $driver = $manager->driver($engine);
        $databases = (new DbAccessApply($config, $runtime))->annotate($engine, $driver->list());

        return [
            'engine' => $engine,
            'databases' => $databases,
        ];
    }
}
