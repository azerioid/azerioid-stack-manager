<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * @deprecated Caddy is the permanent front door on :80/:443. Apache and Nginx
 *             are loopback backends; site ports are never released.
 */
final class SitePortReleaser
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /** @return array<string, mixed> */
    public function release(): array
    {
        return [
            'released' => false,
            'deprecated' => true,
            'panel_port' => $this->panelPort(),
            'note' => 'Caddy is the permanent front router on :80/:443. Apache and Nginx listen only on loopback backend ports. Releasing site ports is no longer needed or supported.',
        ];
    }

    private function panelPort(): int
    {
        $access = '/etc/azerioid-panel/access.env';
        if ($this->runtime->fileExists($access)) {
            foreach (explode("\n", $this->runtime->readFile($access)) as $line) {
                if (str_starts_with($line, 'PANEL_PORT=')) {
                    $port = (int) trim(substr($line, strlen('PANEL_PORT=')));
                    if ($port > 0) {
                        return $port;
                    }
                }
            }
        }

        return $this->config->panelPort > 0 ? $this->config->panelPort : 3169;
    }
}
