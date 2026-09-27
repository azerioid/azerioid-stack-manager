<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Sftp;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * Per-vhost SFTP (A48, B3 / request #11).
 *
 * This is the most lockout-prone change in the roadmap: a bad sshd configuration on a host whose
 * only access is sshd cannot be undone from the network. Every decision here is about that, and
 * none of it is negotiable at the call site.
 *
 *  - **A drop-in, never `sshd_config`.** The distro owns that file; an upgrade replacing it must not
 *    carry the panel's changes away, and the panel must never be the reason a host cannot be
 *    upgraded. The file sorts after the distro's own drop-ins (`50-cloud-init`, `60-cloudimg-…` on
 *    the verification host) so its `Match` block wins.
 *  - **`sshd -t` before every reload, and the drop-in is removed again if it fails.** A rejected
 *    configuration is never left where the next reload — by anyone, for any reason — would load it.
 *  - **Reload, never restart.** A restart with a broken configuration drops existing sessions *and*
 *    fails to come back, which turns a mistake into an outage with no way in. A reload leaves the
 *    running daemon alone if the new configuration is bad.
 *  - **A dedicated group.** `Match Group azerioid-sftp`, not the A25 identity group — that one has
 *    `www-data` and `caddy` as supplementary members, so matching it would have restricted the
 *    panel's own accounts (A48 amendment, found by reading the host).
 *  - **Keys only, and no shell.** These accounts have `/bin/bash` for the Terminal feature, so a
 *    password here would be an SSH shell credential as well. The block denies a shell explicitly
 *    rather than relying on the account's shell field.
 *  - **No chroot.** `ChrootDirectory` needs a root-owned, non-group-writable root, which fights
 *    A25's ownership model and fails by breaking login for one site while working for another.
 *    Confinement comes from the account's home and `internal-sftp`.
 */
final class SftpManager
{
    public const GROUP = 'azerioid-sftp';

    /** 70 so it sorts after the distro drop-ins; Match order decides which block wins. */
    public const DROP_IN = '/etc/ssh/sshd_config.d/70-azerioid-sftp.conf';

    private const SSHD = '/usr/sbin/sshd';

    /** Accounts that must never end up in the SFTP group, whatever else happens. */
    private const NEVER = ['root'];

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $present = $this->runtime->fileExists(self::DROP_IN);

        return [
            'configured' => $present,
            'drop_in' => self::DROP_IN,
            'group' => self::GROUP,
            'sites' => $this->members(),
            'include_present' => $this->includePresent(),
            'service' => $this->serviceName(),
        ];
    }

    /**
     * Write the drop-in and reload sshd. Idempotent: the same configuration is rewritten rather
     * than appended to, so repeated calls cannot accumulate Match blocks.
     *
     * @return array<string,mixed>
     */
    public function configure(): array
    {
        if (!$this->includePresent()) {
            throw new BrokerException(
                'This host\'s sshd_config does not include /etc/ssh/sshd_config.d/*.conf, so a '
                . 'drop-in would be ignored. Refusing to edit sshd_config itself — add the Include '
                . 'line yourself, or SFTP cannot be managed from here.',
                3
            );
        }

        $previous = $this->runtime->fileExists(self::DROP_IN)
            ? $this->runtime->readFile(self::DROP_IN)
            : null;

        $this->ensureGroup();
        // 0644 root:root, matching how sshd's own drop-ins ship. sshd refuses a group-writable
        // configuration file outright.
        $this->runtime->writeFile(self::DROP_IN, $this->render(), 0644);

        try {
            $this->assertSshdAccepts();
        } catch (\Throwable $e) {
            // Never leave a configuration sshd rejected: the next reload, for any unrelated
            // reason, would fail and the daemon would not come back.
            if ($previous === null) {
                $this->runtime->deleteFile(self::DROP_IN);
            } else {
                $this->runtime->writeFile(self::DROP_IN, $previous, 0644);
            }
            throw $e;
        }

        return ['configured' => true, 'reloaded' => $this->reload()] + $this->status();
    }

    /** @return array<string,mixed> */
    public function unconfigure(): array
    {
        if (!$this->runtime->fileExists(self::DROP_IN)) {
            return ['configured' => false, 'reloaded' => false] + $this->status();
        }
        $previous = $this->runtime->readFile(self::DROP_IN);
        $this->runtime->deleteFile(self::DROP_IN);
        try {
            $this->assertSshdAccepts();
        } catch (\Throwable $e) {
            $this->runtime->writeFile(self::DROP_IN, $previous, 0644);
            throw $e;
        }

        return ['configured' => false, 'reloaded' => $this->reload()] + $this->status();
    }

    /** @return array<string,mixed> */
    public function enable(string $domain): array
    {
        $user = $this->identityFor($domain);
        $this->ensureGroup();
        if (!$this->runtime->fileExists(self::DROP_IN)) {
            $this->configure();
        }
        if (!in_array($user, $this->members(), true)) {
            $this->run(['/usr/bin/gpasswd', '-a', $user, self::GROUP], 'add ' . $user . ' to ' . self::GROUP);
        }

        return ['domain' => $domain, 'user' => $user, 'enabled' => true, 'sites' => $this->members()];
    }

    /** @return array<string,mixed> */
    public function disable(string $domain): array
    {
        $user = $this->identityFor($domain);
        if (in_array($user, $this->members(), true)) {
            $this->run(['/usr/bin/gpasswd', '-d', $user, self::GROUP], 'remove ' . $user . ' from ' . self::GROUP);
        }

        return ['domain' => $domain, 'user' => $user, 'enabled' => false, 'sites' => $this->members()];
    }

    /**
     * The identity a domain maps to, with the one refusal that matters: an account that is not a
     * vhost identity must never be given SFTP access through this path. `root` is named explicitly,
     * and so are the panel's own accounts — the A48 amendment exists because the first design would
     * have caught them by accident.
     */
    private function identityFor(string $domain): string
    {
        $domain = Validator::domain($domain);
        $user = VhostUser::username($domain);
        $forbidden = array_merge(self::NEVER, [$this->config->webUser, $this->config->phpUser]);
        if (in_array($user, $forbidden, true) || !str_starts_with($user, VhostUser::PREFIX)) {
            throw new BrokerException('Refusing to grant SFTP to ' . $user . '.', 3);
        }
        if (!$this->userExists($user)) {
            throw new BrokerException(
                'There is no system identity for ' . $domain . ' (expected ' . $user . '). '
                . 'Create the vhost first, or run `azerioid vhost reconcile --repair`.',
                3
            );
        }

        return $user;
    }

    private function render(): string
    {
        $group = self::GROUP;

        return <<<SSHD
# AZERIOID Stack Manager — per-vhost SFTP (broker-managed, ADR A48).
#
# Do not edit: rewritten by `azerioid sftp`. Removing this file and reloading sshd disables
# panel-managed SFTP and changes nothing else.
#
# Only accounts in {$group} are affected, and only vhost identities are ever put in it. The
# administrator's own access is untouched by design: this block cannot match root.
Match Group {$group}
	# File transfer only. The account has a shell for the panel's Terminal feature, which the
	# panel brokers; SFTP must not become a second, unbrokered way in.
	ForceCommand internal-sftp
	PermitTTY no
	# Keys only. A password here would also be an SSH shell credential for the same account.
	PasswordAuthentication no
	KbdInteractiveAuthentication no
	PubkeyAuthentication yes
	# Nothing that turns a file-transfer account into a network foothold.
	AllowTcpForwarding no
	AllowStreamLocalForwarding no
	AllowAgentForwarding no
	X11Forwarding no
	PermitTunnel no
	GatewayPorts no
	PermitOpen none

SSHD;
    }

    /** sshd validates the whole configuration, including every drop-in, with -t. */
    private function assertSshdAccepts(): void
    {
        if (!$this->runtime->fileExists(self::SSHD)) {
            throw new BrokerException(
                'sshd was not found at ' . self::SSHD . ', so the configuration cannot be validated. '
                . 'Refusing to reload something that has not been checked.',
                3
            );
        }
        $result = $this->runtime->exec([self::SSHD, '-t'], null, 30);
        if (!$result->ok()) {
            $detail = trim($result->stderr !== '' ? $result->stderr : $result->stdout);
            throw new BrokerException(
                'sshd rejected the configuration, so nothing was applied: '
                . ($detail === '' ? 'unknown error' : $detail),
                3
            );
        }
    }

    /**
     * Reload, never restart — see the class comment. A failed reload is reported rather than
     * retried as a restart, which is the tempting and dangerous escalation.
     */
    private function reload(): bool
    {
        $service = $this->serviceName();
        $result = $this->runtime->exec(['/usr/bin/systemctl', 'reload', $service], null, 30);
        if (!$result->ok()) {
            throw new BrokerException(
                'sshd accepted the configuration but reloading ' . $service . ' failed: '
                . trim($result->stderr !== '' ? $result->stderr : $result->stdout)
                . '. The running daemon is unchanged and still serving existing sessions.',
                1
            );
        }

        return true;
    }

    /** Debian calls the unit ssh, EL calls it sshd. */
    private function serviceName(): string
    {
        return $this->runtime->fileExists('/lib/systemd/system/ssh.service')
            || $this->runtime->fileExists('/usr/lib/systemd/system/ssh.service')
            ? 'ssh'
            : 'sshd';
    }

    private function includePresent(): bool
    {
        if (!$this->runtime->fileExists('/etc/ssh/sshd_config')) {
            return false;
        }
        foreach (explode("\n", $this->runtime->readFile('/etc/ssh/sshd_config')) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('#^Include\s+/etc/ssh/sshd_config\.d/\*\.conf#i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function ensureGroup(): void
    {
        $check = $this->runtime->exec(['/usr/bin/getent', 'group', self::GROUP], null, 10);
        if (!$check->ok()) {
            $this->run(['/usr/sbin/groupadd', '--system', self::GROUP], 'create group ' . self::GROUP);
        }
    }

    /** @return list<string> */
    private function members(): array
    {
        $result = $this->runtime->exec(['/usr/bin/getent', 'group', self::GROUP], null, 10);
        if (!$result->ok()) {
            return [];
        }
        $parts = explode(':', trim($result->stdout));
        $list = $parts[3] ?? '';

        return $list === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $list))));
    }

    private function userExists(string $user): bool
    {
        return $this->runtime->exec(['/usr/bin/getent', 'passwd', $user], null, 10)->ok();
    }

    /** @param list<string> $command */
    private function run(array $command, string $what): void
    {
        $result = $this->runtime->exec($command, null, 30);
        if (!$result->ok()) {
            throw new BrokerException(
                'Could not ' . $what . ': ' . trim($result->stderr !== '' ? $result->stderr : $result->stdout),
                1
            );
        }
    }
}
