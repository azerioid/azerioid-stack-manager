<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class PanelUpdateOperationLog
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $operationId = Validator::operationId((string) ($args[0] ?? $input['operation_id'] ?? ''));

        return (new PanelUpdater($config, $runtime))->operationLog($operationId);
    }
}
