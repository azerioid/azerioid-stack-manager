<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DbAccessApply;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class DbAccessShow
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $engine = (string) ($input['engine'] ?? '');
        $name = (string) ($args[0] ?? ($input['name'] ?? ''));
        Validator::dbName($name);

        return (new DbAccessApply($config, $runtime))->show($engine, $name);
    }
}
