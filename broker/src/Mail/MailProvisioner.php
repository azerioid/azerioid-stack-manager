<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Post-install hardening for Postfix + Dovecot + OpenDKIM.
 *
 * The defaults that ship with these packages are not safe to expose: this class
 * exists so a freshly installed host is closed before it is reachable, per A36 §3.2.
 *
 * Non-negotiables enforced here:
 *  - mynetworks is loopback only, and smtpd_relay_restrictions rejects unauthorized
 *    destinations, so the host is never an open relay.
 *  - smtpd_sasl_auth_enable is NOT set in main.cf. AUTH is switched on per-service in
 *    master.cf for 587/465 only; setting it globally leaks AUTH onto port 25.
 *  - plaintext IMAP (143) stays disabled; only IMAPS 993 listens.
 */
final class MailProvisioner
{
    public const MAX_MESSAGE_BYTES = 26214400; // 25 MiB (A36 §9.10 — hardcoded, no quota UI)
    public const VMAIL_USER = 'vmail';
    public const VMAIL_GROUP = 'vmail';

    private const MANAGED_BEGIN = '# BEGIN azerioid-managed (broker-owned; edits are overwritten)';
    private const MANAGED_END = '# END azerioid-managed';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
        private readonly MailPaths $paths,
    ) {
    }

    public function provision(OperationLogger $log): void
    {
        $this->ensureVmailIdentity($log);
        $this->hardenPostfixMain($log);
        $this->writeSubmissionServices($log);
        $this->writeDovecotConfig($log);
        $this->writeOpenDkimConfig($log);
        $this->applyMaps($log);
        $this->assertPostfixConfigValid($log);
        $this->restartServices($log);
    }

    /** Dedicated non-login owner for the whole Maildir tree — mail is never owned by caddy/www-data. */
    private function ensureVmailIdentity(OperationLogger $log): void
    {
        $root = $this->paths->vmailRoot();
        $this->runtime->exec(
            ['/usr/sbin/groupadd', '-g', (string) MailMaps::VMAIL_GID, self::VMAIL_GROUP],
            null,
            30
        );
        $this->runtime->exec(
            [
                '/usr/sbin/useradd',
                '-r',
                '-g', self::VMAIL_GROUP,
                '-u', (string) MailMaps::VMAIL_UID,
                '-d', $root,
                '-s', '/usr/sbin/nologin',
                self::VMAIL_USER,
            ],
            null,
            30
        );
        if (!$this->runtime->isDir($root)) {
            $this->runtime->mkdir($root, 0750);
        }
        $this->runtime->exec(['/usr/bin/chown', '-R', self::VMAIL_USER . ':' . self::VMAIL_GROUP, $root], null, 60);
        $this->runtime->exec(['/usr/bin/chmod', '0750', $root], null, 15);
        $this->labelVmailSelinux($root, $log);
        $log->info("Ensured mail store owner {$root} (" . self::VMAIL_USER . ').');
    }

    /** On EL, /var/vmail must be mail_spool_t so dovecot_t can open Maildirs (Enforcing). */
    private function labelVmailSelinux(string $root, OperationLogger $log): void
    {
        if (!$this->runtime->fileExists('/usr/sbin/semanage') || !$this->runtime->fileExists('/usr/sbin/restorecon')) {
            return;
        }
        $pattern = rtrim($root, '/') . '(/.*)?';
        $add = $this->runtime->exec(['/usr/sbin/semanage', 'fcontext', '-a', '-t', 'mail_spool_t', $pattern], null, 30);
        if (!$add->ok()) {
            $this->runtime->exec(['/usr/sbin/semanage', 'fcontext', '-m', '-t', 'mail_spool_t', $pattern], null, 30);
        }
        $this->runtime->exec(['/usr/sbin/restorecon', '-Rv', $root], null, 60);
        $log->info("Applied SELinux mail_spool_t to {$root}.");
    }

    private function hardenPostfixMain(OperationLogger $log): void
    {
        $hostname = (new MailState($this->runtime))->hostname();
        if ($hostname === '') {
            $hostname = trim($this->runtime->exec(['/bin/hostname', '-f'], null, 15)->stdout);
        }
        $specs = (new MailMaps($this->config, $this->runtime, $this->paths))->lookupSpecs();
        $tls = (new MailTls($this->runtime, $this->paths))->resolve($hostname);
        $log->info('Mail TLS material: ' . $tls['source'] . ' (' . $tls['cert'] . ').');

        foreach ($this->mainCfSettings($hostname, $specs, $tls) as $key => $value) {
            $this->postconf($key . '=' . $value);
        }
        $log->info('Applied hardened Postfix parameters (relay restrictions, TLS, 25 MiB message cap).');
    }

    /**
     * Re-select TLS paths when the mail hostname changes (Caddy/LE preferred).
     *
     * @return array{cert:string,key:string,source:string}
     */
    public function applyTlsForHostname(string $hostname, OperationLogger $log): array
    {
        $tls = (new MailTls($this->runtime, $this->paths))->resolve($hostname);
        $this->postconf('smtpd_tls_cert_file=' . $tls['cert']);
        $this->postconf('smtpd_tls_key_file=' . $tls['key']);
        $this->writeDovecotConfig($log);
        $log->info('Mail TLS material: ' . $tls['source'] . ' (' . $tls['cert'] . ').');

        return $tls;
    }

    /**
     * @param  array{domains:string,mailboxes:string,aliases:string}  $specs
     * @param  array{cert:string,key:string,source:string}  $tls
     * @return array<string,string>
     */
    private function mainCfSettings(string $hostname, array $specs, array $tls): array
    {
        $vmailRoot = rtrim($this->paths->vmailRoot(), '/');

        return [
            'myhostname' => $hostname,
            'mydestination' => 'localhost',
            // Loopback only. Widening this is the single fastest way to become an open relay.
            'mynetworks' => '127.0.0.0/8 [::1]/128',
            'inet_interfaces' => 'all',
            'inet_protocols' => 'all',
            'biff' => 'no',
            'append_dot_mydomain' => 'no',
            'compatibility_level' => '3.6',
            'message_size_limit' => (string) self::MAX_MESSAGE_BYTES,
            'mailbox_size_limit' => '0',

            'virtual_mailbox_domains' => $specs['domains'],
            'virtual_mailbox_maps' => $specs['mailboxes'],
            'virtual_alias_maps' => $specs['aliases'],
            // No trailing slash — mailbox map values already include domain/user/.
            'virtual_mailbox_base' => $vmailRoot,
            'virtual_uid_maps' => 'static:' . MailMaps::VMAIL_UID,
            'virtual_gid_maps' => 'static:' . MailMaps::VMAIL_GID,
            'virtual_transport' => 'virtual',

            // Reject anything we are not explicitly responsible for, before any content checks.
            'smtpd_relay_restrictions' => 'permit_mynetworks reject_unauth_destination',
            'smtpd_recipient_restrictions' => 'permit_mynetworks reject_unauth_destination reject_unknown_recipient_domain',
            'smtpd_helo_required' => 'yes',
            'disable_vrfy_command' => 'yes',
            'smtpd_client_message_rate_limit' => '100',
            'anvil_rate_time_unit' => '60s',

            // SASL is wired to Dovecot but deliberately left off here; master.cf turns it
            // on for submission only so port 25 never advertises AUTH.
            'smtpd_sasl_type' => 'dovecot',
            'smtpd_sasl_path' => 'private/auth',
            'smtpd_sasl_auth_enable' => 'no',
            'smtpd_sasl_security_options' => 'noanonymous',
            'smtpd_sasl_local_domain' => '',
            'broken_sasl_auth_clients' => 'no',

            'smtpd_tls_cert_file' => $tls['cert'],
            'smtpd_tls_key_file' => $tls['key'],
            'smtpd_tls_security_level' => 'may',
            'smtpd_tls_mandatory_protocols' => '!SSLv2,!SSLv3,!TLSv1,!TLSv1.1',
            'smtpd_tls_protocols' => '!SSLv2,!SSLv3,!TLSv1,!TLSv1.1',
            'smtp_tls_security_level' => 'may',
            'smtp_tls_mandatory_protocols' => '!SSLv2,!SSLv3,!TLSv1,!TLSv1.1',

            'milter_default_action' => 'accept',
            'milter_protocol' => '6',
            // Inet localhost avoids Postfix chroot + SELinux unix-socket denials on EL.
            'smtpd_milters' => 'inet:127.0.0.1:8891',
            'non_smtpd_milters' => 'inet:127.0.0.1:8891',

            'local_recipient_maps' => '',
            'alias_maps' => 'hash:/etc/aliases',
            'smtpd_banner' => '$myhostname ESMTP',
        ];
    }

    /** Reserved for diagnostics; milter transport is inet:8891@localhost. */
    private function milterSocketSpec(): string
    {
        return 'inet:127.0.0.1:8891';
    }

    private function writeSubmissionServices(OperationLogger $log): void
    {
        $path = $this->paths->masterCf();
        if (!$this->runtime->fileExists($path)) {
            throw new BrokerException('Postfix master.cf is missing; the package did not install correctly.', 1);
        }
        $body = self::masterCfWithSubmission($this->runtime->readFile($path));
        $this->runtime->writeFile($path, $body, 0644);
        $log->info('Enabled SASL submission on 587 and 465 in master.cf (port 25 stays AUTH-free).');
    }

    /**
     * Replace any active submission/submissions service blocks with panel-owned ones.
     *
     * Pure so the "AUTH never lands on 25" invariant is unit-testable without a host.
     */
    public static function masterCfWithSubmission(string $body): string
    {
        $body = self::stripManagedBlock($body);
        $body = self::stripService($body, 'submission');
        $body = self::stripService($body, 'smtps');
        $body = self::stripService($body, 'submissions');

        $block = self::MANAGED_BEGIN . "\n"
            . "submission inet n       -       y       -       -       smtpd\n"
            . "  -o syslog_name=postfix/submission\n"
            . "  -o smtpd_tls_security_level=encrypt\n"
            . "  -o smtpd_sasl_auth_enable=yes\n"
            . "  -o smtpd_tls_auth_only=yes\n"
            . "  -o smtpd_client_restrictions=permit_sasl_authenticated,reject\n"
            . "  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject\n"
            . "  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject\n"
            . "  -o milter_macro_daemon_name=ORIGINATING\n"
            . "smtps     inet  n       -       y       -       -       smtpd\n"
            . "  -o syslog_name=postfix/smtps\n"
            . "  -o smtpd_tls_wrappermode=yes\n"
            . "  -o smtpd_sasl_auth_enable=yes\n"
            . "  -o smtpd_client_restrictions=permit_sasl_authenticated,reject\n"
            . "  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject\n"
            . "  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject\n"
            . "  -o milter_macro_daemon_name=ORIGINATING\n"
            . self::MANAGED_END . "\n";

        return rtrim($body, "\n") . "\n\n" . $block;
    }

    /** Drop an active service definition and its "  -o …" continuation lines. */
    private static function stripService(string $body, string $service): string
    {
        $out = [];
        $skipping = false;
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^' . preg_quote($service, '/') . '\s+(inet|unix)\s/', $line) === 1) {
                $skipping = true;
                continue;
            }
            if ($skipping) {
                // Continuation lines are indented; anything at column 0 starts a new service.
                if ($line === '' || preg_match('/^\s/', $line) === 1) {
                    continue;
                }
                $skipping = false;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private static function stripManagedBlock(string $body): string
    {
        $pattern = '/' . preg_quote(self::MANAGED_BEGIN, '/') . '.*?' . preg_quote(self::MANAGED_END, '/') . '\n?/s';

        return preg_replace($pattern, '', $body) ?? $body;
    }

    private function writeDovecotConfig(OperationLogger $log): void
    {
        $confd = rtrim($this->paths->dovecotConfD(), '/');
        if (!$this->runtime->isDir($confd)) {
            $this->runtime->mkdir($confd, 0755);
        }
        $usersFile = (new MailMaps($this->config, $this->runtime, $this->paths))->dovecotUsersPath();
        $vmailRoot = rtrim($this->paths->vmailRoot(), '/');
        $hostname = (new MailState($this->runtime))->hostname();
        $tls = (new MailTls($this->runtime, $this->paths))->resolve($hostname);
        $cert = $tls['cert'];
        $key = $tls['key'];
        $uid = MailMaps::VMAIL_UID;
        $gid = MailMaps::VMAIL_GID;

        // Debian 13+ ships Dovecot 2.4 with incompatible settings vs Ubuntu 24.04's 2.3.
        $body = $this->dovecotIs24()
            ? $this->dovecot24Config($usersFile, $vmailRoot, $cert, $key, $uid, $gid)
            : $this->dovecot23Config($usersFile, $vmailRoot, $cert, $key, $uid, $gid);

        $this->runtime->writeFile($confd . '/99-azerioid.conf', $body, 0644);
        // Distro 10-auth.conf includes PAM first; virtual users must not fall through to system accounts.
        $this->disableDovecotSystemAuth($confd, $log);
        $log->info('Wrote Dovecot configuration (IMAPS 993 only, SASL socket for Postfix submission).');
    }

    private function dovecotIs24(): bool
    {
        foreach (['/usr/sbin/dovecot', '/usr/bin/dovecot'] as $bin) {
            if (!$this->runtime->fileExists($bin)) {
                continue;
            }
            $out = trim($this->runtime->exec([$bin, '--version'], null, 15)->stdout);
            if (preg_match('/^(\d+)\.(\d+)/', $out, $m) === 1) {
                return ((int) $m[1] > 2) || ((int) $m[1] === 2 && (int) $m[2] >= 4);
            }
        }

        return false;
    }

    private function dovecot23Config(
        string $usersFile,
        string $vmailRoot,
        string $cert,
        string $key,
        int $uid,
        int $gid,
    ): string {
        // 143 is absent from inet_listener on purpose: IMAPS only (A36 §3.1).
        return <<<CONF
# AZERIOID Stack Manager — broker-generated; edits are overwritten
protocols = imap lmtp
listen = *, ::
disable_plaintext_auth = yes
auth_mechanisms = plain login

mail_location = maildir:{$vmailRoot}/%d/%n
mail_uid = {$uid}
mail_gid = {$gid}
first_valid_uid = {$uid}
last_valid_uid = {$uid}

passdb {
  driver = passwd-file
  args = scheme=BLF-CRYPT username_format=%u {$usersFile}
}
userdb {
  driver = passwd-file
  args = username_format=%u {$usersFile}
  default_fields = uid={$uid} gid={$gid} home={$vmailRoot}/%d/%n
}

ssl = required
ssl_cert = <{$cert}
ssl_key = <{$key}
ssl_min_protocol = TLSv1.2

service imap-login {
  inet_listener imap {
    port = 0
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}

# Postfix submission authenticates against this socket; it is the only reason
# Postfix knows about users at all.
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

CONF;
    }

    private function dovecot24Config(
        string $usersFile,
        string $vmailRoot,
        string $cert,
        string $key,
        int $uid,
        int $gid,
    ): string {
        // Dovecot 2.4: renamed SSL/auth settings, named passdb sections, mail_driver/mail_path.
        // Do not set dovecot_config_version here — the package dovecot.conf already owns it.
        return <<<CONF
# AZERIOID Stack Manager — broker-generated; edits are overwritten (Dovecot 2.4+)
protocols = imap lmtp
listen = *, ::
auth_allow_cleartext = no
auth_mechanisms = plain login

mail_driver = maildir
mail_path = {$vmailRoot}/%{user|domain}/%{user|username}
mail_home = {$vmailRoot}/%{user|domain}/%{user|username}
# Debian's 10-mail.conf defaults mail_inbox_path to /var/mail/%{user}; override so
# Postfix virtual Maildir delivery (root new/cur/tmp) is the IMAP INBOX.
mail_inbox_path = {$vmailRoot}/%{user|domain}/%{user|username}
mail_uid = {$uid}
mail_gid = {$gid}
first_valid_uid = {$uid}
last_valid_uid = {$uid}

passdb passwd-file {
  passwd_file_path = {$usersFile}
  default_password_scheme = BLF-CRYPT
}
userdb passwd-file {
  passwd_file_path = {$usersFile}
  fields {
    uid = {$uid}
    gid = {$gid}
    home = {$vmailRoot}/%{user|domain}/%{user|username}
  }
}

ssl = required
ssl_server_cert_file = {$cert}
ssl_server_key_file = {$key}
ssl_min_protocol = TLSv1.2

service imap-login {
  inet_listener imap {
    port = 0
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

CONF;
    }

    private function disableDovecotSystemAuth(string $confd, OperationLogger $log): void
    {
        $auth = rtrim($confd, '/') . '/10-auth.conf';
        if (!$this->runtime->fileExists($auth)) {
            return;
        }
        $raw = $this->runtime->readFile($auth);
        $updated = preg_replace(
            '/^\s*!include\s+auth-system\.conf\.ext\s*$/m',
            '#!include auth-system.conf.ext  # disabled by azerioid — virtual mailboxes use passwd-file only',
            $raw
        );
        if (is_string($updated) && $updated !== $raw) {
            $this->runtime->writeFile($auth, $updated, 0644);
            $log->info('Disabled Dovecot system/PAM auth include (virtual mailboxes only).');
        }
    }

    private function writeOpenDkimConfig(OperationLogger $log): void
    {
        $keysDir = rtrim($this->paths->opendkimKeys(), '/');
        // Package install often leaves /etc/opendkim as root:root 0750, which blocks the
        // opendkim user from reading keys under keys/ — signing then milter-rejects with 451.
        $opendkimEtc = dirname($keysDir);
        if ($this->runtime->isDir($opendkimEtc)) {
            $this->runtime->exec(['/usr/bin/chown', 'opendkim:opendkim', $opendkimEtc], null, 15);
            $this->runtime->exec(['/usr/bin/chmod', '0755', $opendkimEtc], null, 15);
        }
        if (!$this->runtime->isDir($keysDir)) {
            $this->runtime->mkdir($keysDir, 0755);
        }
        $this->runtime->exec(['/usr/bin/chown', '-R', 'opendkim:opendkim', $keysDir], null, 30);
        $this->runtime->exec(['/usr/bin/chmod', '0755', $keysDir], null, 15);
        // Runtime dir for PidFile (tmpfiles.d normally creates this).
        if (!$this->runtime->isDir('/run/opendkim')) {
            $this->runtime->mkdir('/run/opendkim', 0755);
        }
        $this->runtime->exec(['/usr/bin/chown', 'opendkim:opendkim', '/run/opendkim'], null, 15);

        $keyTable = $keysDir . '/key.table';
        $signingTable = $keysDir . '/signing.table';
        $trusted = $keysDir . '/trusted.hosts';
        foreach ([$keyTable, $signingTable] as $table) {
            if (!$this->runtime->fileExists($table)) {
                $this->runtime->writeFile($table, '', 0640);
            }
        }
        $this->runtime->writeFile($trusted, "127.0.0.1\nlocalhost\n::1\n", 0640);
        // writeFile runs as root; opendkim must own every table it opens (EL especially).
        foreach ([$keyTable, $signingTable, $trusted] as $table) {
            $this->runtime->exec(['/usr/bin/chown', 'opendkim:opendkim', $table], null, 15);
            $this->runtime->exec(['/usr/bin/chmod', '0640', $table], null, 15);
        }
        if ($this->runtime->fileExists('/usr/sbin/restorecon')) {
            $this->runtime->exec(['/usr/sbin/restorecon', '-Rv', $opendkimEtc], null, 30);
        }

        $body = <<<CONF
# AZERIOID Stack Manager — broker-generated; edits are overwritten
Syslog                  yes
UMask                   002
Mode                    sv
Canonicalization        relaxed/simple
SubDomains              no
OversignHeaders         From
AutoRestart             yes
AutoRestartRate         10/1h
# Inet localhost — portable across Ubuntu/Debian and SELinux Enforcing EL hosts.
Socket                  inet:8891@localhost
PidFile                 /run/opendkim/opendkim.pid
UserID                  opendkim:opendkim
KeyTable                file:{$keyTable}
SigningTable            refile:{$signingTable}
ExternalIgnoreList      refile:{$trusted}
InternalHosts           refile:{$trusted}

CONF;
        $this->runtime->writeFile($this->paths->opendkimConf(), $body, 0644);
        $log->info('Wrote OpenDKIM configuration (inet milter on 127.0.0.1:8891).');
    }

    private function applyMaps(OperationLogger $log): void
    {
        $state = new MailState($this->runtime);
        $maps = new MailMaps($this->config, $this->runtime, $this->paths);
        $result = $maps->write($state->load(), $state->passdb());
        if ($result['postmap_failed'] !== []) {
            $log->warn('postmap failed for: ' . implode(', ', $result['postmap_failed']));
        }
        $log->info('Generated virtual domain/mailbox/alias maps.');
    }

    private function assertPostfixConfigValid(OperationLogger $log): void
    {
        $check = $this->runtime->exec(['/usr/sbin/postfix', 'check'], null, 60);
        if (!$check->ok()) {
            $detail = trim($check->stderr . "\n" . $check->stdout);
            throw new BrokerException('Postfix rejected the generated configuration: ' . $detail, 1);
        }
        $log->info('postfix check passed.');
    }

    private function restartServices(OperationLogger $log): void
    {
        foreach (['opendkim', 'dovecot', 'postfix'] as $key) {
            $unit = $this->paths->unit($key);
            $this->runtime->exec(['/usr/bin/systemctl', 'enable', '--now', $unit], null, 120);
            $result = $this->runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 120);
            if (!$result->ok()) {
                $log->warn("systemctl restart {$unit} failed: " . trim($result->stderr));
            }
        }
        // Socket is created at opendkim start; ensure Postfix can open it from the chroot.
        $socket = $this->paths->opendkimSocket();
        if ($this->runtime->fileExists($socket)) {
            $this->runtime->exec(['/usr/bin/chown', 'opendkim:postfix', $socket], null, 15);
            $this->runtime->exec(['/usr/bin/chmod', '0660', $socket], null, 15);
        }
        $log->info('Mail services enabled and restarted.');
    }

    private function postconf(string $assignment): void
    {
        $this->runtime->exec(['/usr/sbin/postconf', '-e', $assignment], null, 30);
    }
}
