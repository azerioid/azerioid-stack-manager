<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\OctaneManager;

final class VhostOctane
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new OctaneManager($config, $runtime);
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));

        return match ($action) {
            'vhost.octane.status' => $manager->status($domain),
            'vhost.octane.enable' => $manager->enable($domain, $input),
            'vhost.octane.disable' => $manager->disable($domain),
            'vhost.octane.reload' => $manager->reload($domain),
            default => throw new BrokerException('Unknown octane action.', 2),
        };
    }
}
