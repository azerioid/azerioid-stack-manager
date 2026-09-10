<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class PanelUpdateApply
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $confirm = (string) ($input['confirm'] ?? '');
        if ($confirm === '') {
            throw new BrokerException(
                'Confirmation required. Pass confirm=' . PanelUpdater::CONFIRM . ' (CLI: --confirm).',
                3
            );
        }
        $operationId = Validator::operationId((string) ($input['operation_id'] ?? ''));
        $tag = isset($input['tag']) ? trim((string) $input['tag']) : null;
        if ($tag === '') {
            $tag = null;
        }
        // Accept CLI-style aliases.
        if ($tag === null && isset($input['v'])) {
            $tag = trim((string) $input['v']);
            $tag = $tag !== '' ? $tag : null;
        }

        return (new PanelUpdater($config, $runtime))->apply($operationId, $confirm, $tag);
    }
}
