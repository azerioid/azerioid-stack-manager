<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use App\Support\Format;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mail page (ADR A36 §7).
 *
 * The health strip is the point of this page: outbound delivery status is
 * mandatory, and when port 25 is blocked the Configure-relay CTA sits directly
 * beside it rather than behind an "advanced" disclosure.
 */
#[Layout('layouts.app')]
#[Title('Mail · AZERIOID Stack Manager')]
class MailPage extends Component
{
    public array $status = [];

    public array $domains = [];

    public array $mailboxes = [];

    public array $aliases = [];

    public array $dnsRecords = [];

    public array $queue = [];

    public array $logLines = [];

    public bool $installed = false;

    public ?string $error = null;

    public ?string $flash = null;

    /** Shown exactly once after create/reset; never persisted to the component state store. */
    public ?string $revealedPassword = null;

    public ?string $revealedFor = null;

    public string $hostname = '';

    public string $newMailboxAddress = '';

    public string $newAliasAddress = '';

    public string $newAliasDestination = '';

    public bool $aliasConfirmExternal = false;

    public string $dnsDomain = '';

    public string $enableDomain = '';

    public bool $showRelayForm = false;

    public string $relayHost = '';

    public string $relayPort = '587';

    public string $relayUsername = '';

    public string $relayPassword = '';

    public string $relayTls = 'starttls';

    public string $testFrom = '';

    public string $testTo = '';

    public string $testSubject = '';

    #[Locked]
    public ?string $pendingMailboxDelete = null;

    public bool $dropMailOnDelete = false;

    #[Locked]
    public ?string $pendingDomainDisable = null;

    public bool $dropMailOnDisable = false;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function reload(BrokerClient $broker): void
    {
        $status = $broker->call('mail.status', [], [], 30, false);
        if (! $status->ok) {
            $this->error = $status->error ?? 'Could not read mail status.';

            return;
        }
        $this->status = is_array($status->data) ? $status->data : [];
        $this->installed = (bool) ($this->status['installed'] ?? false);
        $this->hostname = (string) ($this->status['hostname'] ?? '');
        $this->domains = is_array($this->status['domains'] ?? null) ? $this->status['domains'] : [];

        if (! $this->installed) {
            return;
        }
        $this->loadMailboxes($broker);
        $this->loadAliases($broker);
        if ($this->dnsDomain === '' && $this->domains !== []) {
            $this->dnsDomain = (string) ($this->domains[0]['domain'] ?? '');
        }
        if ($this->dnsDomain !== '') {
            $this->loadDns($broker);
        }
    }

    // ------------------------------------------------------------- hostname

    public function saveHostname(BrokerClient $broker): void
    {
        $this->resetMessages();
        try {
            $hostname = Validator::mailHostname($this->hostname);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }
        $res = $broker->call('mail.hostname.set', [], ['hostname' => $hostname], 60);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = "Mail hostname set to {$hostname}. Publish an A record for it and set matching reverse DNS at your VPS provider.";
        $this->reload($broker);
    }

    // -------------------------------------------------------------- domains

    public function enableDomainMail(BrokerClient $broker): void
    {
        $this->resetMessages();
        $domain = strtolower(trim($this->enableDomain));
        if ($domain === '') {
            $this->error = 'Choose a vhost domain to enable mail for.';

            return;
        }
        $res = $broker->call('mail.domain.enable', [$domain], [], 180);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->enableDomain = '';
        $this->dnsDomain = $domain;
        $this->flash = "Mail enabled for {$domain}. Publish the DNS records below, then check the live status badges.";
        $this->reload($broker);
    }

    public function askDisableDomain(string $domain): void
    {
        $this->resetMessages();
        $this->pendingDomainDisable = $domain;
        $this->dropMailOnDisable = false;
    }

    public function cancelDisableDomain(): void
    {
        $this->pendingDomainDisable = null;
        $this->dropMailOnDisable = false;
    }

    public function disableDomainMail(BrokerClient $broker): void
    {
        $domain = $this->pendingDomainDisable;
        if ($domain === null) {
            return;
        }
        $this->resetMessages();
        $stdin = $this->dropMailOnDisable
            ? ['drop_mail' => true, 'confirm' => Validator::DROP_MAIL_CONFIRM]
            : [];
        $res = $broker->call('mail.domain.disable', [$domain], $stdin, 120);
        $this->pendingDomainDisable = null;
        $this->dropMailOnDisable = false;
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = "Mail disabled for {$domain}.";
        $this->reload($broker);
    }

    // ------------------------------------------------------------ mailboxes

    public function addMailbox(BrokerClient $broker): void
    {
        $this->resetMessages();
        try {
            $address = Validator::mailAddress($this->newMailboxAddress);
            [$localPart, $domain] = explode('@', $address, 2);
            $password = Format::password();
            Validator::password($password);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }
        $res = $broker->call('mail.mailbox.add', [$localPart, $domain], ['password' => $password], 60);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->newMailboxAddress = '';
        $this->revealedPassword = $password;
        $this->revealedFor = $address;
        $this->flash = "Created {$address}. Copy the password now — it is not stored in readable form.";
        $this->reload($broker);
    }

    public function resetMailboxPassword(BrokerClient $broker, string $address): void
    {
        $this->resetMessages();
        $password = Format::password();
        $res = $broker->call('mail.mailbox.passwd', [$address], ['password' => $password], 60);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->revealedPassword = $password;
        $this->revealedFor = $address;
        $this->flash = "Password reset for {$address}.";
    }

    public function toggleMailbox(BrokerClient $broker, string $address, bool $disable): void
    {
        $this->resetMessages();
        $res = $broker->call($disable ? 'mail.mailbox.disable' : 'mail.mailbox.enable', [$address], [], 60);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = $address . ($disable ? ' disabled. Stored mail is untouched.' : ' enabled.');
        $this->loadMailboxes($broker);
    }

    public function askDeleteMailbox(string $address): void
    {
        $this->resetMessages();
        $this->pendingMailboxDelete = $address;
        $this->dropMailOnDelete = false;
    }

    public function cancelDeleteMailbox(): void
    {
        $this->pendingMailboxDelete = null;
        $this->dropMailOnDelete = false;
    }

    public function deleteMailbox(BrokerClient $broker): void
    {
        $address = $this->pendingMailboxDelete;
        if ($address === null) {
            return;
        }
        $this->resetMessages();
        $stdin = $this->dropMailOnDelete
            ? ['drop_mail' => true, 'confirm' => Validator::DROP_MAIL_CONFIRM]
            : [];
        $res = $broker->call('mail.mailbox.del', [$address], $stdin, 60);
        $this->pendingMailboxDelete = null;
        $this->dropMailOnDelete = false;
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = "Deleted {$address}.";
        $this->reload($broker);
    }

    // --------------------------------------------------------------- aliases

    public function addAlias(BrokerClient $broker): void
    {
        $this->resetMessages();
        try {
            $address = Validator::mailAddress($this->newAliasAddress);
            $destination = Validator::mailAddress($this->newAliasDestination);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }
        $stdin = $this->aliasConfirmExternal ? ['confirm_external' => true] : [];
        $res = $broker->call('mail.alias.add', [$address, $destination], $stdin, 60);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->newAliasAddress = '';
        $this->newAliasDestination = '';
        $this->aliasConfirmExternal = false;
        $this->flash = "Alias {$address} → {$destination} created.";
        $this->loadAliases($broker);
    }

    public function deleteAlias(BrokerClient $broker, string $address): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.alias.del', [$address], [], 60);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = "Deleted alias {$address}.";
        $this->loadAliases($broker);
    }

    // ------------------------------------------------------------- DNS/DKIM

    public function selectDnsDomain(BrokerClient $broker, string $domain): void
    {
        $this->resetMessages();
        $this->dnsDomain = $domain;
        $this->loadDns($broker);
    }

    public function rotateDkim(BrokerClient $broker, string $domain): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.dkim.rotate', [$domain], [], 180);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->dnsDomain = $domain;
        $this->flash = "Rotated the DKIM key for {$domain}. Publish the new TXT record below — mail signed with the old key fails once it is gone.";
        $this->loadDns($broker);
    }

    // ------------------------------------------------------------- smarthost

    public function openRelayForm(): void
    {
        $this->resetMessages();
        $this->showRelayForm = true;
        $relay = $this->status['smarthost'] ?? null;
        if (is_array($relay)) {
            $this->relayHost = (string) ($relay['host'] ?? '');
            $this->relayPort = (string) ($relay['port'] ?? 587);
            $this->relayUsername = (string) ($relay['username'] ?? '');
            $this->relayTls = (string) ($relay['tls'] ?? 'starttls');
        }
    }

    public function closeRelayForm(): void
    {
        $this->showRelayForm = false;
        $this->reset('relayPassword');
    }

    public function saveRelay(BrokerClient $broker): void
    {
        $this->resetMessages();
        if (trim($this->relayPassword) === '') {
            $this->error = 'Enter the relay password. It is written only to a root-only Postfix credentials file.';

            return;
        }
        $res = $broker->call('mail.smarthost.set', [], [
            'host' => $this->relayHost,
            'port' => (int) $this->relayPort,
            'username' => $this->relayUsername,
            'password' => $this->relayPassword,
            'tls' => $this->relayTls,
        ], 60);
        $this->reset('relayPassword');
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->showRelayForm = false;
        $this->flash = 'Relay configured. SPF for each domain now needs your provider’s include — check the DNS list below.';
        $this->reload($broker);
    }

    public function testRelay(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.smarthost.test', [], [], 30, false);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $detail = (string) ($res->data['detail'] ?? '');
        if ($res->data['ok'] ?? false) {
            $this->flash = $detail . ' (' . (int) ($res->data['latency_ms'] ?? 0) . ' ms)';
        } else {
            $this->error = $detail;
        }
    }

    public function clearRelay(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.smarthost.clear', [], ['confirm' => 'CLEAR-RELAY'], 60);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = 'Relay cleared. Outbound mail goes direct to recipient MX — if port 25 is blocked here, it will queue and bounce.';
        $this->reload($broker);
    }

    // ------------------------------------------------------- probes, queue

    public function refreshProbe(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.probe.outbound25', [], [], 30, false);
        if ($res->ok && is_array($res->data)) {
            $this->status['outbound25'] = $res->data;
            $this->status['delivery_message'] = (string) ($res->data['message'] ?? '');
            $this->status['relay_cta'] = ($res->data['open'] ?? true) === false
                && ($this->status['smarthost'] ?? null) === null;
        }
    }

    public function runRelaySelftest(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.relay.selftest', [], [], 30, false);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        if ($res->data['passed'] ?? false) {
            $this->flash = (string) $res->data['detail'];
        } else {
            $this->error = (string) $res->data['detail'];
        }
    }

    public function loadQueue(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.queue', [], [], 60, false);
        $this->queue = $res->ok && is_array($res->data) ? $res->data : [];
        if (! $res->ok) {
            $this->error = (string) $res->error;
        }
    }

    public function flushQueue(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.queue', [], ['flush' => true, 'confirm' => 'FLUSH-QUEUE'], 120);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->queue = is_array($res->data) ? $res->data : [];
        $this->flash = 'Deferred mail flushed — Postfix is retrying delivery now.';
    }

    public function loadLogs(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.logs', [], ['lines' => 80], 30, false);
        $this->logLines = $res->ok && is_array($res->data['lines'] ?? null) ? $res->data['lines'] : [];
        if (! $res->ok) {
            $this->error = (string) $res->error;
        }
    }

    public function sendTest(BrokerClient $broker): void
    {
        $this->resetMessages();
        $res = $broker->call('mail.test.send', [$this->testFrom, $this->testTo], [
            'subject' => $this->testSubject,
        ], 90);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = 'Test message queued via the ' . (string) ($res->data['delivery_mode'] ?? 'direct')
            . ' path. Check the queue and log below to confirm it left the server.';
    }

    public function dismissPassword(): void
    {
        $this->revealedPassword = null;
        $this->revealedFor = null;
    }

    private function loadMailboxes(BrokerClient $broker): void
    {
        $res = $broker->call('mail.mailbox.list', [], [], null, false);
        $this->mailboxes = $res->ok && is_array($res->data['mailboxes'] ?? null) ? $res->data['mailboxes'] : [];
    }

    private function loadAliases(BrokerClient $broker): void
    {
        $res = $broker->call('mail.alias.list', [], [], null, false);
        $this->aliases = $res->ok && is_array($res->data['aliases'] ?? null) ? $res->data['aliases'] : [];
    }

    private function loadDns(BrokerClient $broker): void
    {
        $res = $broker->call('mail.dns.records', [$this->dnsDomain], [], 60, false);
        $this->dnsRecords = $res->ok && is_array($res->data['records'] ?? null) ? $res->data['records'] : [];
    }

    private function resetMessages(): void
    {
        $this->error = null;
        $this->flash = null;
    }

    public function render(BrokerClient $broker)
    {
        $vhostDomains = [];
        $vhosts = $broker->call('vhost.list', [], ['probe_certs' => false], 30, false);
        if ($vhosts->ok && is_array($vhosts->data['vhosts'] ?? null)) {
            $enabled = array_column($this->domains, 'enabled', 'domain');
            foreach ($vhosts->data['vhosts'] as $vhost) {
                $domain = strtolower((string) ($vhost['domain'] ?? ''));
                if ($domain === '' || ! empty($vhost['readonly']) || ! empty($enabled[$domain])) {
                    continue;
                }
                $vhostDomains[] = $domain;
            }
            sort($vhostDomains);
        }

        return view('livewire.mail', [
            'vhostDomains' => array_values(array_unique($vhostDomains)),
            'outbound' => is_array($this->status['outbound25'] ?? null) ? $this->status['outbound25'] : [],
            'relay' => is_array($this->status['smarthost'] ?? null) ? $this->status['smarthost'] : null,
        ])->layoutData([
            'heading' => 'Mail',
            'sub' => 'Postfix · Dovecot · OpenDKIM — mailboxes, DNS, and outbound delivery',
        ]);
    }
}
