<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * Firewall management across both backends (B2 / request #2).
 *
 * A1/G2 fixed *reporting* — the Security page used to claim no firewall existed on
 * EL hosts while the broker was actively writing firewalld rules. This adds
 * *management*, with the two safety properties that make it usable at all: the
 * lockout refusals live in the broker (FirewallGuard), and a change that costs the
 * operator their connection undoes itself (FirewallRevertWindow).
 *
 * **Additive, not declarative.** The panel adds and removes individual rules and
 * leaves everything else alone. A declarative model — "these are the rules, make the
 * host match" — would be tidier and would delete the operator's own rules, the
 * provider's, and the ones some other tool on the host depends on, the first time
 * anyone pressed Save.
 */
final class FirewallManager
{
    /** Typed by an operator who is saying "I can reach this box another way". */
    public const CONSOLE_CONFIRM = 'I-HAVE-CONSOLE-ACCESS';

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /**
     * ufw first, firewalld when ufw is absent — the same precedence
     * DbAccessFirewall, MailFirewall and SiteHttpFirewall already use, so a rule the
     * operator adds lands in the same backend as the rules the panel writes for
     * itself.
     */
    public function driver(): FirewallDriver
    {
        $ufw = new UfwDriver($this->runtime);
        if ($ufw->active()) {
            return $ufw;
        }
        $firewalld = new FirewalldDriver($this->runtime);
        if ($firewalld->active()) {
            return $firewalld;
        }

        throw new BrokerException(
            'No active firewall. Install and enable ufw (Debian/Ubuntu) or firewalld (EL) first; '
            . 'adding rules to an inactive firewall would report success while changing nothing.',
            3
        );
    }

    public function guard(): FirewallGuard
    {
        return new FirewallGuard($this->runtime, $this->config);
    }

    public function window(): FirewallRevertWindow
    {
        return new FirewallRevertWindow($this->runtime, $this->config);
    }

    /** @return array<string,mixed> */
    public function list(): array
    {
        $driver = $this->driver();
        $rules = $driver->rules();
        $managed = array_values(array_filter($rules, static fn (FirewallRule $r): bool => $r->managed));

        return [
            'backend' => $driver->name(),
            'rules' => array_map(static fn (FirewallRule $r): array => $r->toArray(), $rules),
            'managed_count' => count($managed),
            'protected_ports' => $this->guard()->protectedPorts(),
            'revert' => $this->window()->status(),
            'revert_supported' => $this->window()->supported(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function add(array $input): array
    {
        $rule = FirewallRule::fromInput($input);
        $driver = $this->driver();
        $this->guard()->assertRuleAllowed($rule);

        foreach ($driver->rules() as $existing) {
            if ($existing->key() === $rule->key()) {
                throw new BrokerException('That rule already exists: ' . $rule->describe() . '.', 3);
            }
        }

        $window = $this->openWindow($driver, $input, 'add ' . $rule->describe());
        $driver->add($rule);

        return [
            'added' => $rule->toArray(),
            'backend' => $driver->name(),
            'revert' => $window,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function delete(array $input): array
    {
        $rule = FirewallRule::fromInput($input);
        $driver = $this->driver();

        $match = null;
        foreach ($driver->rules() as $existing) {
            if ($existing->key() === $rule->key()) {
                $match = $existing;
                break;
            }
        }
        if ($match === null) {
            throw new BrokerException('No such rule on this host: ' . $rule->describe() . '.', 3);
        }

        $this->guard()->assertDeletionAllowed($match);

        // A rule the panel wrote for a feature — a database's remote access (A23), a
        // mail port (A36), site serving — is removed by turning that feature off, not
        // from here. Deleting it directly leaves the feature believing it is still
        // reachable, and the next time it runs it puts the rule back anyway.
        if ($match->managed && $this->isFeatureRule($match->comment)) {
            throw new BrokerException(
                'That rule belongs to the ' . $this->featureOf($match->comment)
                . ' feature (' . $match->comment . '). Change it there, not here.',
                3
            );
        }

        $window = $this->openWindow($driver, $input, 'delete ' . $match->describe());
        $driver->delete($match);

        return [
            'deleted' => $match->toArray(),
            'backend' => $driver->name(),
            'revert' => $window,
        ];
    }

    /**
     * The generic "Confirmation phrase did not match" is no use to an operator who
     * has not been told the phrase, and this is a path they reach by accident rather
     * than by choosing it.
     *
     * @param  array<string,mixed>  $input
     */
    private function assertConsoleAccess(array $input, string $why): void
    {
        try {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), self::CONSOLE_CONFIRM);
        } catch (BrokerException) {
            throw new BrokerException(
                $why . ' Re-send with confirm=' . self::CONSOLE_CONFIRM
                . ' to apply it anyway — only do that if you can reach this machine another way.',
                3
            );
        }
    }

    /**
     * Rules written by other parts of the panel carry their own suffixes; only
     * operator-created rules (bare tag, or tag plus the operator's own note) are
     * editable from here.
     */
    private function isFeatureRule(string $comment): bool
    {
        foreach (['db-', 'mail-', 'http', 'https', 'backend-internal', 'panel-', 'terminal-'] as $prefix) {
            if (str_starts_with(substr($comment, strlen(FirewallRule::TAG) + 1), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function featureOf(string $comment): string
    {
        $suffix = substr($comment, strlen(FirewallRule::TAG) + 1);

        return match (true) {
            str_starts_with($suffix, 'db-') => 'database remote access',
            str_starts_with($suffix, 'mail-') => 'mail server',
            str_starts_with($suffix, 'panel-') => 'panel access',
            str_starts_with($suffix, 'terminal-') => 'terminal',
            default => 'site serving',
        };
    }

    /**
     * Arm the revert window, or accept a typed confirmation that the operator has
     * out-of-band access and does not need one.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function openWindow(FirewallDriver $driver, array $input, string $description): array
    {
        $window = $this->window();
        if (!$window->supported()) {
            $this->assertConsoleAccess(
                $input,
                'systemd-run is not available on this host, so nothing can close an automatic revert window.'
            );

            return ['armed' => false, 'reason' => 'systemd-run unavailable; confirmed by the operator'];
        }

        // An explicit opt-out for an operator who is on the console anyway, and for
        // scripted use where nothing will be around to confirm.
        if (($input['revert'] ?? null) === false || ($input['revert'] ?? null) === 'off') {
            $this->assertConsoleAccess($input, 'You asked to apply this without an automatic revert.');

            return ['armed' => false, 'reason' => 'declined by the operator'];
        }

        $seconds = $window->normalizeSeconds($input['revert_after'] ?? 0);
        $window->arm($driver, $seconds, $description);

        return $window->status();
    }
}
