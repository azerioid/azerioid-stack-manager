<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\DockerManager;

final class VhostDocker
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new DockerManager($config, $runtime);
        $domain = Validator::domain((string) ($args[0] ?? ($input['domain'] ?? '')));

        return match ($action) {
            'vhost.docker.status' => $manager->status($domain),
            'vhost.docker.enable' => $manager->enable($domain, $input),
            'vhost.docker.disable' => $manager->disable($domain),
            'vhost.docker.build' => $manager->build($domain),
            'vhost.docker.restart' => $manager->restart($domain),
            'vhost.docker.logs' => $manager->logs($domain, $input),
            default => throw new BrokerException('Unknown docker action.', 2),
        };
    }
}
