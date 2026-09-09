<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Files\VhostFiles;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class VhostFilesAction
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $op = match ($action) {
            'vhost.files.list' => 'list',
            'vhost.files.read' => 'read',
            'vhost.files.write' => 'write',
            'vhost.files.mkdir' => 'mkdir',
            'vhost.files.rename' => 'rename',
            'vhost.files.move' => 'move',
            'vhost.files.delete' => 'delete',
            default => throw new BrokerException('Unknown file manager action.', 2),
        };
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));

        return (new VhostFiles($config, $runtime))->run($op, $domain, $input);
    }
}
