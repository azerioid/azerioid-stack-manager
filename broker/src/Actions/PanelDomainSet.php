<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Web\PanelCaddy;

final class PanelDomainSet
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $panel = new PanelCaddy();
        if ($action === 'panel.domain.show') {
            return $panel->status($runtime, $config);
        }
        if (isset($args[0]) && !isset($input['domain']) && empty($input['clear'])) {
            $input['domain'] = (string) $args[0];
        }

        return $panel->apply($runtime, $config, $input);
    }
}
