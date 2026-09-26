<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * The rules the panel will not let you write (B2 / request #2).
 *
 * A firewall manager is a lockout weapon. The dangerous change is not exotic: it is
 * `deny 22/tcp` typed by someone who meant to close something else, on a host whose
 * only access is that port. There is no recovery from inside — the operator has to
 * go to the provider's console, if the provider has one.
 *
 * So the refusals live **here, in the broker**, not in the interface. A hidden
 * button is not a guard: the same action is reachable from the CLI, from a second
 * panel session, and from anything that can call the broker. The UI may also hide
 * these, but it is not what makes them safe.
 *
 * SSH ports are read from the host's own sshd configuration rather than assumed to
 * be 22, because an operator who moved SSH to 2222 is exactly the operator who would
 * be locked out by a guard that only knew about 22.
 */
final class FirewallGuard
{
    /** Serving ports. Denying these does not lock the operator out, it takes every site offline. */
    private const WEB_PORTS = [80, 443];

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{ssh:list<int>, panel:int, web:list<int>}
     */
    public function protectedPorts(): array
    {
        return [
            'ssh' => $this->sshPorts(),
            'panel' => $this->config->panelPort,
            'web' => self::WEB_PORTS,
        ];
    }

    /** @return list<int> */
    public function allProtectedPorts(): array
    {
        $ports = $this->protectedPorts();

        return array_values(array_unique(array_merge($ports['ssh'], [$ports['panel']], $ports['web'])));
    }

    /**
     * Refuse a rule that would deny a port the operator needs to stay reachable on.
     *
     * Deny-with-a-source is refused too. "Deny SSH from that one address" reads as
     * narrow, but the panel cannot know the operator is not behind that address —
     * and the case where they are is precisely the case where the mistake is
     * unrecoverable.
     */
    public function assertRuleAllowed(FirewallRule $rule): void
    {
        if ($rule->action !== FirewallRule::DENY) {
            return;
        }
        $why = $this->why($rule->port);
        if ($why === null) {
            return;
        }
        throw new BrokerException(
            'Refusing to deny port ' . $rule->port . ': ' . $why
            . ' Use the provider firewall if you genuinely mean to close it.',
            3
        );
    }

    /**
     * Deleting an allow rule is as effective a lockout as adding a deny, once the
     * default incoming policy is deny — which is what `ufw enable` does.
     */
    public function assertDeletionAllowed(FirewallRule $rule): void
    {
        if ($rule->action !== FirewallRule::ALLOW) {
            return;
        }
        $why = $this->why($rule->port);
        if ($why === null) {
            return;
        }
        throw new BrokerException(
            'Refusing to remove the rule allowing port ' . $rule->port . ': ' . $why
            . ' With a default-deny policy, removing it closes the port.',
            3
        );
    }

    private function why(int $port): ?string
    {
        $protected = $this->protectedPorts();
        if (in_array($port, $protected['ssh'], true)) {
            return 'this host accepts SSH on it, and closing it locks you out with no way back in from the network.';
        }
        if ($port === $protected['panel']) {
            return 'the panel itself answers on it, and closing it locks you out of this interface.';
        }
        if (in_array($port, $protected['web'], true)) {
            return 'every site on this host serves on it, and closing it takes them all offline.';
        }

        return null;
    }

    /**
     * Every `Port` in sshd's configuration, including drop-ins, because a host that
     * moved SSH usually did it in `sshd_config.d`. Falls back to 22 when nothing is
     * declared, which is what sshd itself does.
     *
     * @return list<int>
     */
    private function sshPorts(): array
    {
        $ports = [];
        foreach ($this->sshConfigFiles() as $path) {
            foreach (explode("\n", $this->runtime->readFile($path)) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (preg_match('/^Port\s+(\d{1,5})\b/i', $line, $m)) {
                    $port = (int) $m[1];
                    if ($port >= 1 && $port <= 65535) {
                        $ports[] = $port;
                    }
                }
            }
        }

        return $ports === [] ? [22] : array_values(array_unique($ports));
    }

    /** @return list<string> */
    private function sshConfigFiles(): array
    {
        $files = [];
        if ($this->runtime->fileExists('/etc/ssh/sshd_config')) {
            $files[] = '/etc/ssh/sshd_config';
        }
        foreach ($this->runtime->glob('/etc/ssh/sshd_config.d/*.conf') as $extra) {
            $files[] = $extra;
        }

        return $files;
    }
}
