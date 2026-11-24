<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Vhost\DockerSettings;
use AzerioidPanel\Broker\Web\VhostFrontRouter;

/**
 * vhost.render.converge (A115/A116): vhosts written by older renderers get the current hardening.
 * A117: and Docker sites set up before it move their container config onto their own group.
 */
final class VhostRenderConverge
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        return (new VhostFrontRouter())->convergeRenders($runtime, $config)
            + ['docker_env' => DockerSettings::convergeAll($runtime, $config)];
    }
}
