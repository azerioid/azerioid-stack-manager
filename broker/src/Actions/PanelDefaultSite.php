<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Web\DefaultSite;

/**
 * `panel.default-site.show|set|clear` — the catch-all for hostnames no vhost
 * claims (B1 / request #14).
 */
final class PanelDefaultSite
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $site = new DefaultSite($runtime, $config);

        return match ($action) {
            'panel.default-site.show' => $site->status(),
            'panel.default-site.clear' => $site->disable(),
            'panel.default-site.set' => $site->apply(
                (string) ($args[0] ?? $input['mode'] ?? DefaultSite::DEFAULT_MODE),
                isset($input['html']) ? (string) $input['html'] : null
            ),
            default => throw new BrokerException('Unknown default-site action.', 2),
        };
    }
}
