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

    /**
     * Keys live outside the account's home, owned by root.
     *
     * The obvious place is `~/.ssh/authorized_keys`, and it is the wrong one: the account owns its
     * home, so the site could rewrite its own key file. Anyone who got code execution as the site
     * once could then install a key and keep access after the original hole was closed — a
     * privilege-persistence foothold handed over by the panel. Root owns these; the site can use
     * them and cannot change them.
     */
    public const KEY_DIR = '/etc/ssh/azerioid-authorized-keys';

    public const JAIL = '/etc/fail2ban/jail.d/azerioid-sftp.conf';

    public const FILTER = '/etc/fail2ban/filter.d/azerioid-sftp.conf';

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
            'jail' => $this->runtime->fileExists(self::JAIL),
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

        $reloaded = $this->reload();
        // After sshd, never before: a jail watching for failures on a service that was not
        // reconfigured is noise, and if the reload had failed there would be nothing to watch.
        $jail = $this->installJail();

        return ['configured' => true, 'reloaded' => $reloaded, 'jail' => $jail] + $this->status();
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

        $reloaded = $this->reload();
        $jail = $this->removeJail();

        return ['configured' => false, 'reloaded' => $reloaded, 'jail' => $jail] + $this->status();
    }

    /**
     * Ban repeated failed key attempts against site identities.
     *
     * A jail of its own rather than leaning on the stock `sshd` one: these accounts are key-only,
     * so a refused attempt does not log "Failed password" and the sshd filter does not see it. The
     * account type most likely to be probed would otherwise be the one nothing watches.
     *
     * Scoped to `az-vh-*`, so a mistake in the filter cannot ban an operator. fail2ban being absent
     * is reported, not fatal — SFTP works without it, and refusing to configure sshd because a
     * monitoring package is missing would be the wrong trade.
     *
     * @return array<string,mixed>
     */
    private function installJail(): array
    {
        if (!$this->runtime->isDir('/etc/fail2ban/jail.d')) {
            return ['installed' => false, 'reason' => 'fail2ban is not installed on this host'];
        }
        // Generated here rather than copied from deploy/, the way the cron wrapper is
        // (CronRenderer::wrapperScript). The first version read template files from
        // {panelRoot}/deploy/fail2ban — a directory the installer does not ship, so the jail could
        // never install on a real host. The unit test passed only because its fixture created those
        // files at a path production does not have, which made the defect invisible. Generating the
        // content removes the dependency entirely: there is nothing to be missing.
        $this->runtime->writeFile(self::FILTER, self::filterConfig(), 0644);
        $this->runtime->writeFile(self::JAIL, self::jailConfig(), 0644);
        $reload = $this->runtime->exec(['/usr/bin/fail2ban-client', 'reload'], null, 60);

        return [
            'installed' => true,
            'jail' => 'azerioid-sftp',
            // A jail that failed to load is reported rather than thrown: sshd is already
            // configured correctly at this point, and undoing that to report a fail2ban problem
            // would trade a working feature for a tidy error.
            'reloaded' => $reload->ok(),
            'detail' => $reload->ok() ? null : trim($reload->stderr !== '' ? $reload->stderr : $reload->stdout),
        ];
    }

    /** The fail2ban filter, as shipped. Single source: nothing on disk to drift from it. */
    public static function filterConfig(): string
    {
        return <<<'CONF'
# AZERIOID Stack Manager — SFTP authentication failures (A48).
#
# Why a filter of its own rather than relying on the stock `sshd` jail: these accounts are
# key-only, and a failed key attempt does not produce "Failed password". sshd logs it as a
# connection closed by an authenticating user, or as the authentication-attempt limit being
# reached — lines the sshd filter either ignores or treats as normal churn. Without this, the
# one account type most likely to be probed is the one nothing watches.
#
# Scoped to `az-vh-*` on purpose. Administrator logins stay the stock sshd jail's business, so
# a mistake in this file cannot ban an operator who mistyped their own passphrase.

[INCLUDES]
before = common.conf

[Definition]

_daemon = sshd

# `Connection closed by authenticating user az-vh-x 1.2.3.4 port N [preauth]` — a key that was
# offered and refused, which is what a probe of one of these accounts looks like.
# `maximum authentication attempts exceeded for az-vh-x from 1.2.3.4` — several in one session.
# `Invalid user az-vh-x from 1.2.3.4` — a guessed site identity.
failregex = ^%(__prefix_line)sConnection closed by authenticating user az-vh-\S+ <HOST> port \d+ \[preauth\]\s*$
            ^%(__prefix_line)serror: maximum authentication attempts exceeded for az-vh-\S+ from <HOST> port \d+ ssh2\s*(?:\[preauth\])?\s*$
            ^%(__prefix_line)sInvalid user az-vh-\S+ from <HOST> port \d+\s*$
            ^%(__prefix_line)sUser az-vh-\S+ from <HOST> not allowed because not listed in AllowUsers\s*$

# A successful transfer is not a failure, and neither is a clean disconnect after one. Both appear
# near the failure lines above and would otherwise inflate counts.
#
# Unanchored and without %(__prefix_line)s, which an ignore rule does not need — fail2ban only
# consults ignoreregex for lines a failregex already matched, so these exist purely as a brake in
# case a failregex is ever widened to catch a line that is really a success.
#
# Note for anyone reading fail2ban-regex output: its "Ignoreregex: N total" counts *hits*, not
# loaded patterns. Zero there means nothing needed ignoring, which is the normal result — not that
# the section failed to load. Read the "Lines: … ignored … matched … missed" summary instead.
ignoreregex = Accepted publickey for az-vh-\S+ from <HOST>
              Disconnected from user az-vh-\S+ <HOST>

[Init]
journalmatch = _SYSTEMD_UNIT=sshd.service + _COMM=sshd
CONF;
    }

    public static function jailConfig(): string
    {
        return <<<'CONF'
# AZERIOID Stack Manager — SFTP jail (A48). Installed with panel-managed SFTP, removed with it.
#
# Separate from the stock `sshd` jail so the two can be tuned independently, and so a change here
# can never affect how administrator logins are handled.
#
# maxretry is lower than the sshd jail's: a legitimate SFTP client presents one key and either
# works or does not. Several failures in a row from one address is a probe, not a person
# mistyping something — there is nothing to mistype.
[azerioid-sftp]
enabled  = true
filter   = azerioid-sftp
backend  = auto
port     = ssh
maxretry = 3
findtime = 600
bantime  = 3600
CONF;
    }

    /** @return array<string,mixed> */
    private function removeJail(): array
    {
        $removed = false;
        foreach ([self::JAIL, self::FILTER] as $path) {
            if ($this->runtime->fileExists($path)) {
                $this->runtime->deleteFile($path);
                $removed = true;
            }
        }
        if ($removed && $this->runtime->fileExists('/usr/bin/fail2ban-client')) {
            $this->runtime->exec(['/usr/bin/fail2ban-client', 'reload'], null, 60);
        }

        return ['installed' => false, 'removed' => $removed];
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
        $keyDir = self::KEY_DIR;

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
	# Keys are read from a root-owned directory, not from the account's home: the account owns its
	# home and could otherwise install its own key and keep access after a hole was closed.
	AuthorizedKeysFile {$keyDir}/%u

SSHD;
    }

    /**
     * Install a public key for one site.
     *
     * @return array<string,mixed>
     */
    public function addKey(string $domain, string $key): array
    {
        $user = $this->identityFor($domain);
        [$type, $blob, $comment] = self::parseKey($key);
        $line = $type . ' ' . $blob . ($comment === '' ? '' : ' ' . $comment);
        $fingerprint = self::fingerprint($blob);

        $existing = $this->keys($user);
        foreach ($existing as $row) {
            if ($row['fingerprint'] === $fingerprint) {
                throw new BrokerException('That key is already installed for ' . $domain . '.', 3);
            }
        }

        $this->writeKeys($user, array_merge(
            array_map(static fn (array $r): string => $r['line'], $existing),
            [$line]
        ));

        return ['domain' => $domain, 'user' => $user, 'fingerprint' => $fingerprint, 'keys' => count($existing) + 1];
    }

    /** @return array<string,mixed> */
    public function removeKey(string $domain, string $fingerprint): array
    {
        $user = $this->identityFor($domain);
        $fingerprint = trim($fingerprint);
        $existing = $this->keys($user);
        $kept = array_values(array_filter(
            $existing,
            static fn (array $r): bool => $r['fingerprint'] !== $fingerprint
        ));
        if (count($kept) === count($existing)) {
            throw new BrokerException('No key with that fingerprint for ' . $domain . '.', 3);
        }
        $this->writeKeys($user, array_map(static fn (array $r): string => $r['line'], $kept));

        return ['domain' => $domain, 'user' => $user, 'removed' => $fingerprint, 'keys' => count($kept)];
    }

    /** @return array<string,mixed> */
    public function listKeys(string $domain): array
    {
        $user = $this->identityFor($domain);

        return [
            'domain' => $domain,
            'user' => $user,
            'path' => self::KEY_DIR . '/' . $user,
            // Never the key material itself: a fingerprint and a comment identify a key without
            // putting a credential in a log or a JSON response.
            'keys' => array_map(
                static fn (array $r): array => ['fingerprint' => $r['fingerprint'], 'type' => $r['type'], 'comment' => $r['comment']],
                $this->keys($user)
            ),
        ];
    }

    /** @return list<array{line:string,type:string,blob:string,comment:string,fingerprint:string}> */
    private function keys(string $user): array
    {
        $path = self::KEY_DIR . '/' . $user;
        if (!$this->runtime->fileExists($path)) {
            return [];
        }
        $out = [];
        foreach (explode("\n", $this->runtime->readFile($path)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            try {
                [$type, $blob, $comment] = self::parseKey($line);
            } catch (BrokerException) {
                // A line the panel cannot parse is kept verbatim rather than silently dropped: it
                // may be an operator's own, and deleting someone's access to tidy a file is worse
                // than showing a row the panel does not fully understand.
                $out[] = [
                    'line' => $line, 'type' => 'unknown', 'blob' => '',
                    'comment' => '', 'fingerprint' => 'unparsed',
                ];

                continue;
            }
            $out[] = [
                'line' => $line, 'type' => $type, 'blob' => $blob,
                'comment' => $comment, 'fingerprint' => self::fingerprint($blob),
            ];
        }

        return $out;
    }

    /** @param list<string> $lines */
    private function writeKeys(string $user, array $lines): void
    {
        $this->runtime->mkdir(self::KEY_DIR, 0755);
        $path = self::KEY_DIR . '/' . $user;
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
        // 0644 root:root. sshd refuses a key file that is group- or world-writable, and the point of
        // this location is that the account using the keys cannot write them.
        $this->runtime->writeFile($path, $body, 0644);
        $this->runtime->chmod($path, 0644);
        $this->runtime->chown($path, 'root', 'root');
    }

    /**
     * Accepts one bare public key and nothing else.
     *
     * Options are refused rather than passed through. `command=`, `environment=` and
     * `permitopen=` in an authorized_keys line change what the key can do, and a panel that
     * forwarded them would let an operator paste a line that quietly re-enabled forwarding or ran a
     * command — undoing the restrictions the Match block exists to impose. An operator who needs
     * that is editing sshd's files directly, not going through here.
     *
     * @return array{0:string,1:string,2:string}
     */
    public static function parseKey(string $key): array
    {
        $key = trim(preg_replace('/\s+/', ' ', $key) ?? '');
        if ($key === '') {
            throw new BrokerException('Provide a public key.', 2);
        }
        if (str_contains($key, "\n") || str_contains($key, "\r")) {
            throw new BrokerException('Provide one key, on one line.', 2);
        }
        if (str_starts_with($key, '-----BEGIN')) {
            throw new BrokerException(
                'That looks like a *private* key. Paste the public one (.pub) — and treat the key you '
                . 'just pasted as compromised.',
                2
            );
        }

        $parts = explode(' ', $key);
        $allowed = [
            'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384',
            'ecdsa-sha2-nistp521', 'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com',
        ];
        if (!in_array($parts[0], $allowed, true)) {
            throw new BrokerException(
                'Unsupported or option-bearing key line. Paste a bare public key beginning with one '
                . 'of: ' . implode(', ', $allowed) . '.',
                2
            );
        }
        $blob = $parts[1] ?? '';
        if ($blob === '' || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $blob) !== 1) {
            throw new BrokerException('The key body is not valid base64.', 2);
        }
        $decoded = base64_decode($blob, true);
        if ($decoded === false || strlen($decoded) < 16) {
            throw new BrokerException('The key body could not be decoded.', 2);
        }
        // The blob announces its own type first; a mismatch means a hand-edited line.
        $length = unpack('N', substr($decoded, 0, 4));
        $announced = substr($decoded, 4, (int) ($length[1] ?? 0));
        if ($announced !== $parts[0]) {
            throw new BrokerException('The key type does not match its body.', 2);
        }

        $comment = trim(implode(' ', array_slice($parts, 2)));
        if (strlen($comment) > 200 || preg_match('/[^\P{C}]/u', $comment) === 1) {
            throw new BrokerException('The key comment must be plain text, at most 200 characters.', 2);
        }

        return [$parts[0], $blob, $comment];
    }

    /** OpenSSH's own format, so an operator can compare it with `ssh-keygen -lf`. */
    public static function fingerprint(string $blob): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) base64_decode($blob, true), true)), '=');
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
