<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Network\SiteHttpFirewall;
use AzerioidPanel\Broker\Php\SitePhpTimeouts;
use AzerioidPanel\Broker\Runtime;

final class PhpTimeoutsEnsure
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $timeouts = (new SitePhpTimeouts())->ensure($runtime, $config, true);
        $firewall = (new SiteHttpFirewall($runtime))->ensure();

        return [
            'timeouts' => $timeouts,
            'http_firewall' => $firewall,
        ];
    }
}
