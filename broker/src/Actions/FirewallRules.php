<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Network\FirewallManager;
use AzerioidPanel\Broker\Runtime;

/**
 * Firewall rule management (B2 / request #2).
 *
 * `firewall.status` (A1/G2) already reported what the host has. These five actions
 * change it:
 *
 *  - `firewall.rules`         list every rule, panel-written or not
 *  - `firewall.rule.add`      add one, inside a revert window
 *  - `firewall.rule.delete`   remove one, inside a revert window
 *  - `firewall.confirm`       the operator is still reachable; the change stands
 *  - `firewall.revert`        put the previous rule set back (also called by the timer)
 *
 * The refusals and the revert window live in FirewallGuard / FirewallRevertWindow, in
 * the broker, so they apply to the CLI and to any other caller — not only to the
 * interface that happens to hide a button.
 */
final class FirewallRules
{
    /**
     * @param  list<string>  $args
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new FirewallManager($runtime, $config);

        return match ($action) {
            'firewall.rules' => $manager->list(),
            'firewall.rule.add' => $manager->add($this->withArgs($args, $input)),
            'firewall.rule.delete' => $manager->delete($this->withArgs($args, $input)),
            'firewall.confirm' => $manager->window()->confirm(),
            // Reached from the operator and from the one-shot systemd timer, which
            // runs as root with no stdin at all — so it must need none.
            'firewall.revert' => $manager->window()->revert($manager->driver()),
            default => throw new BrokerException('Unsupported firewall action: ' . $action, 2),
        };
    }

    /**
     * Accepts `firewall.rule.add allow 8080/tcp 10.0.0.5` from the CLI as well as a
     * JSON body from the panel, because a firewall is something operators reach for
     * on a broken host where the panel may be the thing that is unreachable.
     *
     * @param  list<string>  $args
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function withArgs(array $args, array $input): array
    {
        if (isset($args[0]) && !isset($input['action'])) {
            $input['action'] = $args[0];
        }
        if (isset($args[1]) && !isset($input['port'])) {
            [$port, $protocol] = array_pad(explode('/', $args[1], 2), 2, 'tcp');
            $input['port'] = $port;
            $input['protocol'] = $protocol === '' ? 'tcp' : $protocol;
        }
        if (isset($args[2]) && !isset($input['source'])) {
            $input['source'] = $args[2];
        }

        return $input;
    }
}
