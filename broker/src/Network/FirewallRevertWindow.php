<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * "Apply it, and put it back if I go quiet" (B2 / request #2).
 *
 * The guard in FirewallGuard stops the mistakes the panel can recognise. It cannot
 * stop the ones it cannot see: an allow rule narrowed to the wrong source address, a
 * host reachable only through a VPN whose route the new rule happens to cut, a
 * provider-side NAT the panel knows nothing about. In every such case the operator
 * loses the connection *through which they would undo it*.
 *
 * So a change can be applied inside a window. The panel snapshots the rule set, the
 * change goes in, and a one-shot systemd timer restores the snapshot when the window
 * closes. If the operator can still reach the panel they confirm, the timer is
 * cancelled and the change stands. If they cannot, the timer does what they would
 * have done.
 *
 * Deliberately **not** a background PHP process or an `at` job: this has to survive
 * the panel's PHP-FPM being restarted, the queue worker dying, and the operator's
 * session ending — all of which happen during exactly the kind of network trouble
 * this exists for. systemd is the one thing on the host guaranteed to still be
 * running, and all five supported targets are systemd.
 *
 * The window is verified by the *operator reaching the panel*, not by a probe from
 * the host. A host cannot meaningfully test its own inbound reachability: it sees
 * loopback and its own interfaces, not the path the operator's packets take.
 */
final class FirewallRevertWindow
{
    public const UNIT = 'azerioid-firewall-revert';

    private const SYSTEMD_RUN = '/usr/bin/systemd-run';

    private const SYSTEMCTL = '/usr/bin/systemctl';

    public const MIN_SECONDS = 30;

    public const MAX_SECONDS = 900;

    public const DEFAULT_SECONDS = 120;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    private function statePath(): string
    {
        return '/var/lib/azerioid-panel/firewall-revert.json';
    }

    public function supported(): bool
    {
        return $this->runtime->fileExists(self::SYSTEMD_RUN);
    }

    /** @return array{armed:bool, backend?:string, deadline?:string, seconds?:int, description?:string} */
    public function status(): array
    {
        $path = $this->statePath();
        if (!$this->runtime->fileExists($path)) {
            return ['armed' => false];
        }
        $state = json_decode($this->runtime->readFile($path), true);
        if (!is_array($state)) {
            return ['armed' => false];
        }

        return [
            'armed' => true,
            'backend' => (string) ($state['backend'] ?? ''),
            'deadline' => (string) ($state['deadline'] ?? ''),
            'seconds' => (int) ($state['seconds'] ?? 0),
            'description' => (string) ($state['description'] ?? ''),
        ];
    }

    public function normalizeSeconds(mixed $seconds): int
    {
        $value = (int) $seconds;
        if ($value === 0) {
            return self::DEFAULT_SECONDS;
        }
        if ($value < self::MIN_SECONDS || $value > self::MAX_SECONDS) {
            throw new BrokerException(
                'The revert window must be between ' . self::MIN_SECONDS . ' and '
                . self::MAX_SECONDS . ' seconds.',
                2
            );
        }

        return $value;
    }

    /**
     * Snapshot and arm before the change is applied, so a failure while applying
     * leaves a window that still restores a known-good rule set.
     */
    public function arm(FirewallDriver $driver, int $seconds, string $description): void
    {
        if (!$this->supported()) {
            throw new BrokerException(
                'systemd-run is not available, so no automatic revert can be armed. '
                . 'Re-send with confirm=I-HAVE-CONSOLE-ACCESS to apply the change without one.',
                3
            );
        }
        if ($this->status()['armed']) {
            throw new BrokerException(
                'A firewall change is already waiting to be confirmed or reverted. '
                . 'Confirm or revert that one first.',
                3
            );
        }

        $snapshot = $driver->snapshot();
        $this->runtime->mkdir(dirname($this->statePath()), 0750);
        $this->runtime->writeFile($this->statePath(), (string) json_encode([
            'backend' => $driver->name(),
            'snapshot' => $snapshot,
            'seconds' => $seconds,
            'armed_at' => $this->runtime->now(),
            'deadline' => date('c', strtotime($this->runtime->now()) + $seconds),
            'description' => $description,
        ]), 0600);

        // --on-active fires once, then the transient unit goes away. Nothing is left
        // behind to fire a second time if the operator repeats the change.
        //
        // Anything going wrong here has to clear the state file: one that claims a
        // window nothing will ever close would block every later change with "a
        // firewall change is already waiting", and the only way out would be
        // deleting a file by hand on a host the operator may have just lost access to.
        try {
            $result = $this->runtime->exec([
                self::SYSTEMD_RUN,
                '--quiet',
                '--unit=' . self::UNIT,
                '--on-active=' . $seconds,
                '--timer-property=AccuracySec=1s',
                '--description=Revert an unconfirmed AZERIOID firewall change',
                rtrim($this->config->panelRoot, '/') . '/broker',
                'firewall.revert',
            ], null, 15);
        } catch (\Throwable $e) {
            $this->runtime->deleteFile($this->statePath());
            throw new BrokerException('Could not arm the automatic revert: ' . $e->getMessage(), 1);
        }

        if (!$result->ok()) {
            $this->runtime->deleteFile($this->statePath());
            throw new BrokerException(
                'Could not arm the automatic revert: '
                . trim($result->stderr !== '' ? $result->stderr : $result->stdout),
                1
            );
        }
    }

    /** The operator is still here; the change stands. */
    public function confirm(): array
    {
        $status = $this->status();
        if (!$status['armed']) {
            throw new BrokerException('There is no firewall change waiting to be confirmed.', 3);
        }
        $this->cancelTimer();
        $this->runtime->deleteFile($this->statePath());

        return ['confirmed' => true, 'description' => $status['description'] ?? ''];
    }

    /**
     * Put the snapshot back. Called by the timer when the window closes, and by the
     * operator when they can see the change was wrong but are still connected.
     */
    public function revert(FirewallDriver $driver): array
    {
        $path = $this->statePath();
        if (!$this->runtime->fileExists($path)) {
            throw new BrokerException('There is no firewall change waiting to be reverted.', 3);
        }
        $state = json_decode($this->runtime->readFile($path), true);
        if (!is_array($state) || !is_string($state['snapshot'] ?? null)) {
            // Clear it: a state file nothing can act on would block every later
            // change with "already waiting to be confirmed".
            $this->runtime->deleteFile($path);
            throw new BrokerException('The saved firewall snapshot is unreadable; nothing was changed.', 1);
        }
        if (($state['backend'] ?? '') !== $driver->name()) {
            throw new BrokerException(
                'The snapshot was taken with ' . (string) $state['backend']
                . ' but the active backend is now ' . $driver->name() . '; refusing to restore across backends.',
                3
            );
        }

        $driver->restore((string) $state['snapshot']);
        $this->cancelTimer();
        $this->runtime->deleteFile($path);

        return [
            'reverted' => true,
            'backend' => $driver->name(),
            'description' => (string) ($state['description'] ?? ''),
        ];
    }

    /**
     * Best effort: when the timer itself is running the revert, stopping its own unit
     * is neither possible nor necessary — a one-shot transient timer is already gone.
     */
    private function cancelTimer(): void
    {
        if (!$this->runtime->fileExists(self::SYSTEMCTL)) {
            return;
        }
        $this->runtime->exec([self::SYSTEMCTL, 'stop', self::UNIT . '.timer'], null, 15);
    }
}
