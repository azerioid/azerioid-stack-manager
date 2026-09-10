<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use AzerioidPanel\Broker\Runtime;

final class PanelUpdateCheck
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        return (new PanelUpdater($config, $runtime))->check();
    }
}
