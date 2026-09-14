<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Component\ComponentInstaller;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class ComponentUninstall
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $id = (string) ($args[0] ?? '');
        $operationId = Validator::operationId((string) ($input['operation_id'] ?? ''));
        $options = is_array($input['options'] ?? null) ? $input['options'] : [];

        return (new ComponentInstaller($config, $runtime))->uninstall($id, $operationId, $options);
    }
}
