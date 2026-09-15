<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\Pm2Manager;

final class VhostPm2
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new Pm2Manager($config, $runtime);
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));

        return match ($action) {
            'vhost.pm2.status' => $manager->status($domain),
            'vhost.pm2.enable' => $manager->enable($domain, $input),
            'vhost.pm2.disable' => $manager->disable($domain),
            'vhost.pm2.reload' => $manager->reload($domain),
            'vhost.pm2.scale' => $manager->scale($domain, $input),
            default => throw new BrokerException('Unknown pm2 action.', 2),
        };
    }
}
