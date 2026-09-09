<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Web\VhostFrontRouter;

final class WebFrontRouterMigrate
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        return (new VhostFrontRouter())->migrate($runtime, $config);
    }
}
