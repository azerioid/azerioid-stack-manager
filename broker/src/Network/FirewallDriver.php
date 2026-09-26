<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

/**
 * One host firewall backend (B2 / request #2).
 *
 * Mirrors the existing WebServerDriver / BackupEngine shape: the actions talk to
 * this, never to `ufw` or `firewall-cmd` directly, so a rule added from the panel
 * means the same thing on Debian and on Rocky. The two implementations are the only
 * places a backend's syntax appears.
 */
interface FirewallDriver
{
    public function name(): string;

    /** The binary is installed. */
    public function available(): bool;

    /** The binary is installed *and* the firewall is actually filtering. */
    public function active(): bool;

    /**
     * Every rule the backend reports, panel-written or not.
     *
     * @return list<FirewallRule>
     */
    public function rules(): array;

    public function add(FirewallRule $rule): void;

    public function delete(FirewallRule $rule): void;

    /**
     * The complete current rule set as an opaque blob, and the means to put it back.
     * Used by the auto-revert window: a snapshot taken before a change is restored
     * verbatim if nobody confirms the change worked.
     */
    public function snapshot(): string;

    public function restore(string $snapshot): void;
}
