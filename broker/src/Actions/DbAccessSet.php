<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DbAccessApply;
use AzerioidPanel\Broker\Runtime;

final class DbAccessSet
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $engine = (string) ($input['engine'] ?? '');
        $name = (string) ($args[0] ?? ($input['name'] ?? ''));
        $mode = (string) ($input['mode'] ?? ($args[1] ?? ''));
        $ips = $input['ips'] ?? ($input['ip'] ?? []);
        $confirm = (string) ($input['confirm'] ?? '');

        return (new DbAccessApply($config, $runtime))->set($engine, $name, $mode, $ips, $confirm);
    }
}
