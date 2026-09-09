<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseManager;
use AzerioidPanel\Broker\Database\DbAccessApply;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class DbDel
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new DatabaseManager($config, $runtime);
        $engine = $manager->resolveEngine((string) ($input['engine'] ?? ''));
        $name = Validator::dbName($args[0] ?? ($input['name'] ?? ''));
        $user = Validator::userName((string) ($args[1] ?? ($input['user'] ?? $name)));

        $result = $manager->driver($engine)->delete($name, $user);
        try {
            (new DbAccessApply($config, $runtime))->forgetAndSync($engine, $name);
        } catch (\Throwable) {
        }

        return $result + ['engine' => $engine];
    }
}
