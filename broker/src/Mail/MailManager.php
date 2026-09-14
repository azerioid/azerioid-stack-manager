<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Secrets;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Orchestration for every `mail.*` broker action.
 *
 * Tenancy rule (A36 §9.1): a mail domain must already exist as a panel-managed
 * vhost, and mail is scoped 1:1 to it. That is what makes "delete the vhost"
 * refusable rather than quietly destructive.
 */
final class MailManager
{
    private const DKIM_KEY_BITS = 2048;

    private readonly MailPaths $paths;

    private readonly MailState $state;

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
        ?OsRelease $os = null,
    ) {
        $this->paths = MailPaths::for($runtime, $config, $os);
        $this->state = new MailState($runtime);
    }

    public function assertInstalled(): void
    {
        $managed = ManagedManifest::load($this->runtime, $this->config->managedComponentsPath);
        if (!$managed->has('mail')) {
            throw new BrokerException('Mail is not installed. Install it from Components first.', 3);
        }
    }

    // ---------------------------------------------------------------- status

    /** @return array<string,mixed> */
    public function status(bool $probe = true): array
    {
        $managed = ManagedManifest::load($this->runtime, $this->config->managedComponentsPath);
        $installed = $managed->has('mail');
        $state = $this->state->load();
        $smarthost = (new MailSmarthost($this->config, $this->runtime, $this->paths))->describe();

        $services = [];
        foreach (['postfix', 'dovecot', 'opendkim'] as $key) {
            $unit = $this->paths->unit($key);
            $services[$key] = Systemd::show($this->runtime, $unit);
        }

        $outbound = $probe
            ? (new MailProbe())->outbound25()
            : ['open' => null, 'message' => '', 'delivery_mode_hint' => ''];
        $deliveryMode = $smarthost !== null ? 'smarthost' : 'direct';

        return [
            'installed' => $installed,
            'hostname' => $state['hostname'],
            'hostname_set' => $state['hostname'] !== '',
            'server_ip' => $this->serverIp(),
            'ptr' => $this->ptrCheck($state['hostname']),
            'services' => $services,
            'domains' => $this->domainSummaries(),
            'mailbox_count' => count($state['mailboxes']),
            'alias_count' => count($state['aliases']),
            'delivery_mode' => $deliveryMode,
            'smarthost' => $smarthost,
            'outbound25' => $outbound,
            // Mandated copy (A36 §7.4) — identical in the UI health strip and `azerioid mail status`.
            'delivery_message' => $outbound['message'] ?? '',
            'relay_cta' => ($outbound['open'] ?? true) === false && $smarthost === null,
            'firewall' => (new MailFirewall($this->runtime))->status(),
            'max_message_bytes' => MailProvisioner::MAX_MESSAGE_BYTES,
            'alerts_via_local_mail' => $state['alerts_via_local_mail'],
        ];
    }

    /** @return array{open:bool,message:string,...} */
    public function probeOutbound25(): array
    {
        return (new MailProbe())->outbound25();
    }

    /** @return array<string,mixed> */
    public function relaySelftest(): array
    {
        $this->assertInstalled();
        $hostname = $this->state->hostname();
        if ($hostname === '') {
            throw new BrokerException('Set the mail hostname before running the relay self-test.', 3);
        }

        return (new MailProbe())->relaySelftest($hostname, 8, $this->serverIp());
    }

    // -------------------------------------------------------------- hostname

    /** @return array{hostname:string} */
    public function showHostname(): array
    {
        return ['hostname' => $this->state->hostname()];
    }

    /** @return array{hostname:string,postfix_reloaded:bool,tls_source?:string} */
    public function setHostname(string $hostname): array
    {
        $hostname = Validator::mailHostname($hostname);
        $this->state->mutate(static function (array $state) use ($hostname): array {
            $state['hostname'] = $hostname;

            return $state;
        });

        $reloaded = false;
        $tlsSource = null;
        if (ManagedManifest::load($this->runtime, $this->config->managedComponentsPath)->has('mail')) {
            $this->runtime->exec(['/usr/sbin/postconf', '-e', 'myhostname=' . $hostname], null, 30);
            $logDir = MailState::DIR;
            if (!$this->runtime->isDir($logDir)) {
                $this->runtime->mkdir($logDir, 0750);
            }
            $log = new OperationLogger($this->runtime, $logDir . '/hostname.log');
            $tls = (new MailProvisioner($this->config, $this->runtime, $this->paths))
                ->applyTlsForHostname($hostname, $log);
            $tlsSource = $tls['source'];
            $this->runtime->exec(
                ['/usr/bin/systemctl', 'reload', $this->paths->unit('postfix')],
                null,
                60
            );
            $this->runtime->exec(
                ['/usr/bin/systemctl', 'reload', $this->paths->unit('dovecot')],
                null,
                60
            );
            $reloaded = true;
        }

        $out = ['hostname' => $hostname, 'postfix_reloaded' => $reloaded];
        if ($tlsSource !== null) {
            $out['tls_source'] = $tlsSource;
        }

        return $out;
    }

    // ---------------------------------------------------------------- domains

    /** @return array{domains:list<array<string,mixed>>} */
    public function listDomains(): array
    {
        return ['domains' => $this->domainSummaries()];
    }

    /** @return array<string,mixed> */
    public function enableDomain(string $domain): array
    {
        $this->assertInstalled();
        $domain = Validator::domain($domain);
        $hostname = $this->state->hostname();
        if ($hostname === '') {
            throw new BrokerException('Set the mail hostname before enabling mail for a domain.', 3);
        }
        $this->assertPanelVhost($domain);

        // Probe first (A36 §7.4) so the operator learns about a provider block
        // before investing effort in DNS and DKIM steps.
        $outbound = (new MailProbe())->outbound25();
        $selector = $this->generateDkimKey($domain);

        $this->state->mutate(function (array $state) use ($domain, $selector): array {
            $existing = is_array($state['domains'][$domain] ?? null) ? $state['domains'][$domain] : [];
            $state['domains'][$domain] = $existing + ['dkim_selector' => $selector, 'enabled_at' => $this->runtime->now()];
            $state['domains'][$domain]['enabled'] = true;
            $state['domains'][$domain]['dkim_selector'] = $selector;

            return $state;
        });
        $this->regenerate();

        return [
            'domain' => $domain,
            'enabled' => true,
            'dkim_selector' => $selector,
            'outbound25' => $outbound,
            'delivery_message' => $outbound['message'],
            'dns' => $this->dnsRecords($domain, false)['records'],
        ];
    }

    /** @return array<string,mixed> */
    public function disableDomain(string $domain, bool $dropMail, string $confirm): array
    {
        $this->assertInstalled();
        $domain = Validator::domain($domain);
        $footprint = $this->state->domainMailFootprint($domain);
        if (!$footprint['mail_enabled']) {
            throw new BrokerException("Mail is not enabled for {$domain}.", 3);
        }
        if ($footprint['mailboxes'] !== [] && !$dropMail) {
            throw new BrokerException(
                "{$domain} has " . count($footprint['mailboxes']) . ' mailbox(es). '
                . 'Pass drop_mail=true with confirm=' . Validator::DROP_MAIL_CONFIRM . ' to disable and remove them.',
                3
            );
        }
        if ($dropMail) {
            Validator::typedConfirm($confirm, Validator::DROP_MAIL_CONFIRM);
        }

        $this->state->mutate(static function (array $state) use ($domain, $dropMail): array {
            $state['domains'][$domain]['enabled'] = false;
            if (!$dropMail) {
                return $state;
            }
            unset($state['domains'][$domain]);
            foreach (array_keys($state['mailboxes']) as $address) {
                if ((string) ($state['mailboxes'][$address]['domain'] ?? '') === $domain) {
                    unset($state['mailboxes'][$address]);
                }
            }
            foreach (array_keys($state['aliases']) as $address) {
                if ((string) ($state['aliases'][$address]['domain'] ?? '') === $domain) {
                    unset($state['aliases'][$address]);
                }
            }

            return $state;
        });

        if ($dropMail) {
            $hashes = $this->state->passdb();
            foreach (array_keys($hashes) as $address) {
                if (str_ends_with($address, '@' . $domain)) {
                    unset($hashes[$address]);
                }
            }
            $this->state->savePassdb($hashes);
            $this->removeMaildirTree($domain);
        }
        $this->regenerate();

        return ['domain' => $domain, 'enabled' => false, 'mail_dropped' => $dropMail];
    }

    /**
     * Remove every trace of a domain's mail. Callers (vhost delete) have already
     * taken the typed confirmation, so this does not ask again.
     *
     * @return array{domain:string,mailboxes_removed:int,aliases_removed:int}
     */
    public function purgeDomain(string $domain): array
    {
        $domain = Validator::domain($domain);
        $footprint = $this->state->domainMailFootprint($domain);
        if (!$footprint['has_data']) {
            return ['domain' => $domain, 'mailboxes_removed' => 0, 'aliases_removed' => 0];
        }

        $this->state->mutate(static function (array $state) use ($domain): array {
            unset($state['domains'][$domain]);
            foreach (array_keys($state['mailboxes']) as $address) {
                if ((string) ($state['mailboxes'][$address]['domain'] ?? '') === $domain) {
                    unset($state['mailboxes'][$address]);
                }
            }
            foreach (array_keys($state['aliases']) as $address) {
                if ((string) ($state['aliases'][$address]['domain'] ?? '') === $domain) {
                    unset($state['aliases'][$address]);
                }
            }

            return $state;
        });

        $hashes = $this->state->passdb();
        foreach (array_keys($hashes) as $address) {
            if (str_ends_with($address, '@' . $domain)) {
                unset($hashes[$address]);
            }
        }
        $this->state->savePassdb($hashes);
        $this->removeMaildirTree($domain);

        if (ManagedManifest::load($this->runtime, $this->config->managedComponentsPath)->has('mail')) {
            $this->regenerate();
        }

        return [
            'domain' => $domain,
            'mailboxes_removed' => count($footprint['mailboxes']),
            'aliases_removed' => count($footprint['aliases']),
        ];
    }

    // -------------------------------------------------------------- mailboxes

    /** @return array{mailboxes:list<array<string,mixed>>} */
    public function listMailboxes(?string $domain = null): array
    {
        $rows = [];
        foreach ($this->state->load()['mailboxes'] as $address => $meta) {
            if ($domain !== null && (string) ($meta['domain'] ?? '') !== $domain) {
                continue;
            }
            $rows[] = [
                'address' => (string) $address,
                'domain' => (string) ($meta['domain'] ?? ''),
                'local_part' => (string) ($meta['local_part'] ?? ''),
                'disabled' => (bool) ($meta['disabled'] ?? false),
                'created_at' => (string) ($meta['created_at'] ?? ''),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['address'], $b['address']));

        return ['mailboxes' => $rows];
    }

    /**
     * Password is generated here or supplied on stdin — never argv — and returned
     * once so the caller can show it. Only the hash is persisted.
     *
     * @return array{address:string,password:string,domain:string}
     */
    public function addMailbox(string $localPart, string $domain, string $password = ''): array
    {
        $this->assertInstalled();
        $domain = Validator::domain($domain);
        $localPart = Validator::mailLocalPart($localPart);
        $this->assertDomainEnabled($domain);

        $address = $localPart . '@' . $domain;
        $state = $this->state->load();
        if (isset($state['mailboxes'][$address])) {
            throw new BrokerException("Mailbox {$address} already exists.", 3);
        }
        if (isset($state['aliases'][$address])) {
            throw new BrokerException("{$address} is already an alias.", 3);
        }

        $password = $password !== '' ? Validator::password($password) : Secrets::generatePassword();
        $this->storeHash($address, $password);
        $this->state->mutate(function (array $state) use ($address, $domain, $localPart): array {
            $state['mailboxes'][$address] = [
                'domain' => $domain,
                'local_part' => $localPart,
                'disabled' => false,
                'created_at' => $this->runtime->now(),
            ];

            return $state;
        });
        $this->createMaildir($domain, $localPart);
        $this->regenerate();

        return ['address' => $address, 'password' => $password, 'domain' => $domain];
    }

    /** @return array{address:string,password:string} */
    public function resetMailboxPassword(string $address, string $password = ''): array
    {
        $this->assertInstalled();
        $address = Validator::mailAddress($address);
        $this->assertMailboxExists($address);

        $password = $password !== '' ? Validator::password($password) : Secrets::generatePassword();
        $this->storeHash($address, $password);
        $this->regenerate();

        return ['address' => $address, 'password' => $password];
    }

    /** @return array{address:string,disabled:bool} */
    public function setMailboxDisabled(string $address, bool $disabled): array
    {
        $this->assertInstalled();
        $address = Validator::mailAddress($address);
        $this->assertMailboxExists($address);
        $this->state->mutate(static function (array $state) use ($address, $disabled): array {
            $state['mailboxes'][$address]['disabled'] = $disabled;

            return $state;
        });
        $this->regenerate();

        return ['address' => $address, 'disabled' => $disabled];
    }

    /** @return array{address:string,deleted:bool,maildir_removed:bool} */
    public function deleteMailbox(string $address, bool $dropMail, string $confirm): array
    {
        $this->assertInstalled();
        $address = Validator::mailAddress($address);
        $this->assertMailboxExists($address);
        if ($dropMail) {
            Validator::typedConfirm($confirm, Validator::DROP_MAIL_CONFIRM);
        }

        $meta = $this->state->load()['mailboxes'][$address];
        $this->state->mutate(static function (array $state) use ($address): array {
            unset($state['mailboxes'][$address]);

            return $state;
        });
        $hashes = $this->state->passdb();
        unset($hashes[$address]);
        $this->state->savePassdb($hashes);

        $removed = false;
        if ($dropMail) {
            $dir = rtrim($this->paths->vmailRoot(), '/') . '/'
                . (string) ($meta['domain'] ?? '') . '/' . (string) ($meta['local_part'] ?? '');
            $removed = $this->removeTree($dir);
        }
        $this->regenerate();

        return ['address' => $address, 'deleted' => true, 'maildir_removed' => $removed];
    }

    // ----------------------------------------------------------------- aliases

    /** @return array{aliases:list<array<string,mixed>>} */
    public function listAliases(?string $domain = null): array
    {
        $rows = [];
        foreach ($this->state->load()['aliases'] as $address => $meta) {
            if ($domain !== null && (string) ($meta['domain'] ?? '') !== $domain) {
                continue;
            }
            $rows[] = [
                'address' => (string) $address,
                'domain' => (string) ($meta['domain'] ?? ''),
                'destination' => (string) ($meta['destination'] ?? ''),
                'external' => (bool) ($meta['external'] ?? false),
                'created_at' => (string) ($meta['created_at'] ?? ''),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['address'], $b['address']));

        return ['aliases' => $rows];
    }

    /** @return array{address:string,destination:string,external:bool} */
    public function addAlias(string $address, string $destination, bool $confirmExternal): array
    {
        $this->assertInstalled();
        $address = Validator::mailAddress($address);
        $destination = Validator::mailAddress($destination);
        $domain = explode('@', $address, 2)[1];
        $this->assertDomainEnabled($domain);

        $state = $this->state->load();
        if (isset($state['mailboxes'][$address])) {
            throw new BrokerException("{$address} is already a mailbox.", 3);
        }
        if (isset($state['aliases'][$address])) {
            throw new BrokerException("Alias {$address} already exists.", 3);
        }

        // Forwarding off-site relays mail the panel cannot see; make that a choice.
        $external = !isset($state['mailboxes'][$destination]);
        if ($external && !$confirmExternal) {
            throw new BrokerException(
                "{$destination} is not a mailbox on this server. "
                . 'Pass confirm_external=true to forward mail to an external address.',
                3
            );
        }

        $this->state->mutate(function (array $state) use ($address, $destination, $domain, $external): array {
            $state['aliases'][$address] = [
                'domain' => $domain,
                'destination' => $destination,
                'external' => $external,
                'created_at' => $this->runtime->now(),
            ];

            return $state;
        });
        $this->regenerate();

        return ['address' => $address, 'destination' => $destination, 'external' => $external];
    }

    /** @return array{address:string,deleted:bool} */
    public function deleteAlias(string $address): array
    {
        $this->assertInstalled();
        $address = Validator::mailAddress($address);
        if (!isset($this->state->load()['aliases'][$address])) {
            throw new BrokerException("Alias {$address} does not exist.", 3);
        }
        $this->state->mutate(static function (array $state) use ($address): array {
            unset($state['aliases'][$address]);

            return $state;
        });
        $this->regenerate();

        return ['address' => $address, 'deleted' => true];
    }

    // --------------------------------------------------------------- DNS/DKIM

    /** @return array{domain:string,records:list<array<string,mixed>>,delivery_mode:string} */
    public function dnsRecords(string $domain, bool $verify = true): array
    {
        $domain = Validator::domain($domain);
        $state = $this->state->load();
        $meta = is_array($state['domains'][$domain] ?? null) ? $state['domains'][$domain] : [];
        $selector = (string) ($meta['dkim_selector'] ?? 'azerioid');
        $smarthost = (new MailSmarthost($this->config, $this->runtime, $this->paths))->describe();

        $records = MailDns::records(
            $domain,
            $state['hostname'],
            $this->serverIp(),
            $smarthost,
            $selector,
            $this->dkimPublicRecord($domain, $selector),
        );
        $dns = new MailDns($this->runtime);

        return [
            'domain' => $domain,
            'records' => $verify ? $dns->verify($records) : $records,
            'delivery_mode' => $smarthost !== null ? 'smarthost' : 'direct',
        ];
    }

    /** @return array{domain:string,dkim_selector:string,record:string} */
    public function rotateDkim(string $domain): array
    {
        $this->assertInstalled();
        $domain = Validator::domain($domain);
        $this->assertDomainEnabled($domain);
        $selector = $this->generateDkimKey($domain);
        $this->state->mutate(static function (array $state) use ($domain, $selector): array {
            $state['domains'][$domain]['dkim_selector'] = $selector;

            return $state;
        });
        $this->runtime->exec(['/usr/bin/systemctl', 'restart', $this->paths->unit('opendkim')], null, 60);
        $this->runtime->exec(['/usr/bin/systemctl', 'reload', $this->paths->unit('postfix')], null, 60);

        return [
            'domain' => $domain,
            'dkim_selector' => $selector,
            'record' => $this->dkimPublicRecord($domain, $selector),
        ];
    }

    // ---------------------------------------------------------- queue and logs

    /** @return array<string,mixed> */
    public function queue(bool $flush = false, string $confirm = ''): array
    {
        $this->assertInstalled();
        if ($flush) {
            Validator::typedConfirm($confirm, 'FLUSH-QUEUE');
            $this->runtime->exec(['/usr/sbin/postqueue', '-f'], null, 60);
        }

        return self::parseQueue(
            $this->runtime->exec(['/usr/sbin/postqueue', '-p'], null, 30)->stdout,
            $flush
        );
    }

    /**
     * `postqueue -p` marks active entries with `*` and held entries with `!`;
     * everything else waiting in the queue is deferred.
     *
     * @return array{flushed:bool,total:int,active:int,held:int,deferred:int,empty:bool,sample:list<string>}
     */
    public static function parseQueue(string $output, bool $flushed = false): array
    {
        $body = trim($output);
        $total = 0;
        $active = 0;
        $held = 0;
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^([0-9A-F]{6,})([*!]?)\s/', $line, $m) !== 1) {
                continue;
            }
            $total++;
            if ($m[2] === '*') {
                $active++;
            } elseif ($m[2] === '!') {
                $held++;
            }
        }

        return [
            'flushed' => $flushed,
            'total' => $total,
            'active' => $active,
            'held' => $held,
            'deferred' => $total - $active - $held,
            'empty' => $total === 0,
            'sample' => array_slice(array_values(array_filter(explode("\n", $body), static fn (string $l): bool => trim($l) !== '')), 0, 40),
        ];
    }

    /** @return array{path:string,lines:list<string>} */
    public function logs(int $lines = 100): array
    {
        $lines = Validator::lineCount($lines, 500);
        $path = $this->paths->mailLog();
        if ($path === '' || !$this->runtime->fileExists($path)) {
            $journal = $this->runtime->exec(
                ['/usr/bin/journalctl', '-u', $this->paths->unit('postfix'), '-n', (string) $lines, '--no-pager'],
                null,
                30
            );

            return ['path' => 'journal:' . $this->paths->unit('postfix'), 'lines' => $this->redact($journal->stdout)];
        }
        $tail = $this->runtime->exec(['/usr/bin/tail', '-n', (string) $lines, $path], null, 30);

        return ['path' => $path, 'lines' => $this->redact($tail->stdout)];
    }

    /** @return array{sent:bool,from:string,to:string,detail:string,delivery_mode:string} */
    public function testSend(string $from, string $to, string $subject): array
    {
        $this->assertInstalled();
        $from = Validator::mailAddress($from);
        $to = Validator::mailAddress($to);
        $this->assertMailboxExists($from);
        $subject = trim($subject) !== '' ? trim($subject) : 'AZERIOID Stack Manager test message';
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new BrokerException('Subject must be a single line.', 2);
        }

        $smarthost = (new MailSmarthost($this->config, $this->runtime, $this->paths))->describe();
        $mode = $smarthost !== null ? 'smarthost' : 'direct';
        $body = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\n\r\n"
            . "Test message from AZERIOID Stack Manager.\r\nOutbound path: {$mode}.\r\n";

        $result = $this->runtime->exec(['/usr/sbin/sendmail', '-f', $from, '-i', $to], $body, 60);
        if (!$result->ok()) {
            throw new BrokerException('sendmail rejected the test message: ' . trim($result->stderr), 1);
        }

        return [
            'sent' => true,
            'from' => $from,
            'to' => $to,
            'detail' => 'Queued for delivery. Check the queue and mail log to confirm it left the server.',
            'delivery_mode' => $mode,
        ];
    }

    // ---------------------------------------------------------------- internals

    /** Regenerate every map and reload the services that consume them. */
    public function regenerate(): array
    {
        $maps = new MailMaps($this->config, $this->runtime, $this->paths);
        $result = $maps->write($this->state->load(), $this->state->passdb());
        $this->writeDkimTables();
        $this->runtime->exec(['/usr/bin/systemctl', 'reload', $this->paths->unit('postfix')], null, 60);
        $this->runtime->exec(['/usr/bin/systemctl', 'reload', $this->paths->unit('dovecot')], null, 60);
        $this->runtime->exec(['/usr/bin/systemctl', 'restart', $this->paths->unit('opendkim')], null, 60);

        return $result;
    }

    /** @return array{alerts_via_local_mail:bool} */
    public function setAlertsViaLocalMail(bool $enabled): array
    {
        $this->state->mutate(static function (array $state) use ($enabled): array {
            $state['alerts_via_local_mail'] = $enabled;

            return $state;
        });

        return ['alerts_via_local_mail' => $enabled];
    }

    private function assertPanelVhost(string $domain): void
    {
        $vhosts = WebServers::for($this->config)->listVhosts($this->runtime, $this->config);
        foreach ($vhosts as $vhost) {
            if (strtolower((string) ($vhost['domain'] ?? '')) === $domain) {
                return;
            }
            foreach ((array) ($vhost['domains'] ?? []) as $alias) {
                if (strtolower(trim((string) $alias)) === $domain) {
                    return;
                }
            }
        }

        throw new BrokerException(
            "{$domain} is not a panel-managed vhost. Mail domains are scoped to existing vhosts — create the site first.",
            3
        );
    }

    private function assertDomainEnabled(string $domain): void
    {
        if (!(bool) (($this->state->load()['domains'][$domain]['enabled'] ?? false))) {
            throw new BrokerException("Mail is not enabled for {$domain}.", 3);
        }
    }

    private function assertMailboxExists(string $address): void
    {
        if (!isset($this->state->load()['mailboxes'][$address])) {
            throw new BrokerException("Mailbox {$address} does not exist.", 3);
        }
    }

    private function storeHash(string $address, string $password): void
    {
        $result = $this->runtime->exec(['/usr/bin/doveadm', 'pw', '-s', 'BLF-CRYPT'], $password . "\n" . $password . "\n", 30);
        $hash = trim($result->stdout);
        if (!$result->ok() || $hash === '') {
            throw new BrokerException('Could not hash the mailbox password (doveadm unavailable).', 1);
        }
        $hashes = $this->state->passdb();
        $hashes[$address] = $hash;
        $this->state->savePassdb($hashes);
    }

    private function createMaildir(string $domain, string $localPart): void
    {
        $root = rtrim($this->paths->vmailRoot(), '/');
        $domainDir = $root . '/' . $domain;
        $base = $domainDir . '/' . $localPart;
        foreach (['', '/cur', '/new', '/tmp'] as $sub) {
            $this->runtime->mkdir($base . $sub, 0700);
        }
        // Domain dir is created by mkdir as root:0700 — vmail must traverse it for delivery/IMAP.
        $this->runtime->exec(
            ['/usr/bin/chown', '-R', MailProvisioner::VMAIL_USER . ':' . MailProvisioner::VMAIL_GROUP, $domainDir],
            null,
            30
        );
        $this->runtime->exec(['/usr/bin/chmod', '0750', $domainDir], null, 15);
        $this->runtime->exec(['/usr/bin/chmod', '-R', '0700', $base], null, 15);
        if ($this->runtime->fileExists('/usr/sbin/restorecon')) {
            $this->runtime->exec(['/usr/sbin/restorecon', '-Rv', $domainDir], null, 30);
        }
    }

    private function removeMaildirTree(string $domain): bool
    {
        return $this->removeTree(rtrim($this->paths->vmailRoot(), '/') . '/' . $domain);
    }

    /** Deleting mail is irreversible, so the path is re-derived and bounded here. */
    private function removeTree(string $path): bool
    {
        $root = rtrim($this->paths->vmailRoot(), '/');
        $normalized = Validator::normalizeAbsolute($path);
        if ($root === '' || $normalized === $root || !str_starts_with($normalized . '/', $root . '/')) {
            return false;
        }

        return $this->runtime->exec(['/bin/rm', '-rf', $normalized], null, 120)->ok();
    }

    /** @return list<array<string,mixed>> */
    private function domainSummaries(): array
    {
        $state = $this->state->load();
        $rows = [];
        foreach ($state['domains'] as $domain => $meta) {
            $domain = (string) $domain;
            $rows[] = [
                'domain' => $domain,
                'enabled' => (bool) ($meta['enabled'] ?? false),
                'dkim_selector' => (string) ($meta['dkim_selector'] ?? ''),
                'enabled_at' => (string) ($meta['enabled_at'] ?? ''),
                'mailbox_count' => count($this->state->mailboxesForDomain($domain)),
                'alias_count' => count($this->state->aliasesForDomain($domain)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['domain'], $b['domain']));

        return $rows;
    }

    /** Date-stamped selectors let a rotation publish alongside the old key. */
    private function generateDkimKey(string $domain): string
    {
        $selector = 'az' . substr(preg_replace('/\D/', '', $this->runtime->now()) ?? '', 0, 8);
        if (strlen($selector) < 4) {
            $selector = 'azerioid';
        }
        $dir = rtrim($this->paths->opendkimKeys(), '/') . '/' . $domain;
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0700);
        }
        $result = $this->runtime->exec(
            [
                '/usr/sbin/opendkim-genkey',
                '-b', (string) self::DKIM_KEY_BITS,
                '-d', $domain,
                '-s', $selector,
                '-D', $dir,
            ],
            null,
            120
        );
        if (!$result->ok()) {
            throw new BrokerException('Could not generate a DKIM key for ' . $domain . '.', 1);
        }
        // The private key forges mail as the domain if it leaks (A36 §5.2) — root/opendkim only.
        $this->runtime->exec(['/usr/bin/chown', '-R', 'opendkim:opendkim', $dir], null, 30);
        $this->runtime->exec(['/usr/bin/chmod', '0600', $dir . '/' . $selector . '.private'], null, 15);
        $this->writeDkimTables();

        return $selector;
    }

    private function writeDkimTables(): void
    {
        $keysDir = rtrim($this->paths->opendkimKeys(), '/');
        $keyLines = [];
        $signingLines = [];
        foreach ($this->state->load()['domains'] as $domain => $meta) {
            if (!(bool) ($meta['enabled'] ?? false)) {
                continue;
            }
            $domain = (string) $domain;
            $selector = (string) ($meta['dkim_selector'] ?? '');
            if ($selector === '') {
                continue;
            }
            $keyLines[] = "{$selector}._domainkey.{$domain} {$domain}:{$selector}:{$keysDir}/{$domain}/{$selector}.private";
            $signingLines[] = "*@{$domain} {$selector}._domainkey.{$domain}";
        }
        sort($keyLines);
        sort($signingLines);
        $this->runtime->writeFile($keysDir . '/key.table', implode("\n", $keyLines) . "\n", 0640);
        $this->runtime->writeFile($keysDir . '/signing.table', implode("\n", $signingLines) . "\n", 0640);
        $this->runtime->exec(['/usr/bin/chown', 'opendkim:opendkim', $keysDir . '/key.table'], null, 15);
        $this->runtime->exec(['/usr/bin/chown', 'opendkim:opendkim', $keysDir . '/signing.table'], null, 15);
    }

    /** opendkim-genkey writes a BIND zone fragment; the UI needs the bare TXT value. */
    private function dkimPublicRecord(string $domain, string $selector): string
    {
        if ($selector === '') {
            return '';
        }
        $path = rtrim($this->paths->opendkimKeys(), '/') . '/' . $domain . '/' . $selector . '.txt';
        if (!$this->runtime->fileExists($path)) {
            return '';
        }
        $raw = $this->runtime->readFile($path);
        if (preg_match_all('/"([^"]*)"/', $raw, $matches) < 1) {
            return '';
        }

        return implode('', $matches[1]);
    }

    private function serverIp(): string
    {
        if ($this->config->panelPublicIp !== null && $this->config->panelPublicIp !== '') {
            return $this->config->panelPublicIp;
        }
        $result = $this->runtime->exec(['/bin/sh', '-c', "ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'"], null, 15);
        $ip = trim($result->stdout);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : '';
    }

    /** @return array{checked:bool,matches:bool,observed:string,note:string} */
    private function ptrCheck(string $hostname): array
    {
        $ip = $this->serverIp();
        if ($ip === '' || $hostname === '') {
            return ['checked' => false, 'matches' => false, 'observed' => '', 'note' => 'Set the mail hostname to check reverse DNS.'];
        }
        $result = $this->runtime->exec(['/usr/bin/dig', '+short', '-x', $ip], null, 15);
        $observed = rtrim(trim(explode("\n", trim($result->stdout))[0] ?? ''), '.');
        if ($observed === '') {
            return ['checked' => true, 'matches' => false, 'observed' => '', 'note' => 'No PTR record found for ' . $ip . '.'];
        }

        return [
            'checked' => true,
            'matches' => strcasecmp($observed, $hostname) === 0,
            'observed' => $observed,
            'note' => strcasecmp($observed, $hostname) === 0
                ? ''
                : "Reverse DNS for {$ip} is {$observed}, not {$hostname}. Set it at your VPS provider.",
        ];
    }

    /** Mail logs carry recipient addresses and subjects; keep addresses, drop bodies. */
    private function redact(string $output): array
    {
        $lines = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $line = preg_replace('/(sasl_username=)\S+/', '$1<redacted>', $line) ?? $line;
            $line = preg_replace('/(password=)\S+/i', '$1<redacted>', $line) ?? $line;
            $lines[] = strlen($line) > 400 ? substr($line, 0, 397) . '…' : $line;
        }

        return $lines;
    }
}
