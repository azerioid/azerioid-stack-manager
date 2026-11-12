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

        // A104: the commit the admin was shown; apply refuses another one.
        $expected = isset($input['expected_commit']) ? strtolower(trim((string) $input['expected_commit'])) : '';
        if ($expected !== '' && preg_match('/^[0-9a-f]{40}$/', $expected) !== 1) {
            throw new BrokerException('expected_commit must be a full commit hash.', 2);
        }

        return (new PanelUpdater($config, $runtime))->apply($operationId, $confirm, $tag, $expected !== '' ? $expected : null);
    }
}
