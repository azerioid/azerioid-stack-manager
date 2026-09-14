<?php

namespace App\Console\Commands\Azerioid;

use App\Support\Format;
use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

/**
 * `azerioid mail …` — full parity with the Mail page (ADR A36 §9.2).
 *
 * `mail status` must report the same direct-vs-blocked wording as the UI health
 * strip, for operators who never open the panel.
 */
class MailCommand extends Command
{
    protected $signature = 'azerioid:mail
        {action : status|hostname|domain|mailbox|alias|dns|dkim|smarthost|probe|selftest|queue|logs|test}
        {subcommand? : show|set|list|enable|disable|add|del|passwd|rotate|clear|records|send}
        {--domain= : Domain name}
        {--hostname= : Mail hostname (hostname set)}
        {--address= : Mailbox or alias address}
        {--destination= : Alias destination address}
        {--host= : Relay host (smarthost set)}
        {--port= : Relay port (smarthost set, default 587)}
        {--username= : Relay username (smarthost set)}
        {--tls=starttls : Relay TLS mode: starttls|wrapper}
        {--from= : Sender mailbox (test send)}
        {--to= : Recipient address (test send)}
        {--subject= : Subject (test send)}
        {--lines=100 : Log lines to tail}
        {--flush : Flush deferred mail (queue)}
        {--drop-mail : Also delete stored mail (domain disable / mailbox del)}
        {--confirm-external : Allow an alias to forward off this server}
        {--confirm= : Typed confirmation for destructive operations}
        {--json : JSON output}';

    protected $description = 'Manage the mail component via broker mail.* actions';

    use CallsBroker;

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status(),
            'hostname' => $this->hostname(),
            'domain' => $this->domain(),
            'mailbox' => $this->mailbox(),
            'alias' => $this->alias(),
            'dns' => $this->dns(),
            'dkim' => $this->dkim(),
            'smarthost' => $this->smarthost(),
            'probe' => $this->probe(),
            'selftest' => $this->selftest(),
            'queue' => $this->queue(),
            'logs' => $this->logs(),
            'test' => $this->testSend(),
            default => $this->invalid('Unknown mail action. Use: status|hostname|domain|mailbox|alias|dns|dkim|smarthost|probe|selftest|queue|logs|test'),
        };
    }

    private function status(): int
    {
        try {
            $data = $this->brokerData('mail.status', [], [], 30, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->line('Installed: ' . (($data['installed'] ?? false) ? 'yes' : 'no'));
        $this->line('Mail hostname: ' . ((string) ($data['hostname'] ?? '') ?: '(not set — required before enabling a domain)'));
        $this->line('Server IP: ' . ((string) ($data['server_ip'] ?? '') ?: 'unknown'));
        foreach ((array) ($data['services'] ?? []) as $name => $unit) {
            $this->line(sprintf('  %-9s %s', $name, (string) ($unit['active_state'] ?? 'unknown')));
        }
        $this->newLine();

        // The mandated §7.4 copy, verbatim from the broker.
        $message = (string) ($data['delivery_message'] ?? '');
        if (($data['outbound25']['open'] ?? false) === true) {
            $this->info($message);
        } else {
            $this->warn($message);
        }
        $smarthost = $data['smarthost'] ?? null;
        $this->line('Outbound mode: ' . (string) ($data['delivery_mode'] ?? 'direct'));
        if (is_array($smarthost)) {
            $this->line('Relay: ' . (string) $smarthost['host'] . ':' . (string) $smarthost['port']
                . ' as ' . (string) $smarthost['username'] . ' (' . (string) $smarthost['tls'] . ')');
        } elseif (! empty($data['relay_cta'])) {
            $this->warn('Configure a relay:  azerioid mail smarthost set --host=… --port=587 --username=…');
        }

        $ptr = is_array($data['ptr'] ?? null) ? $data['ptr'] : [];
        if (($ptr['checked'] ?? false) && ! ($ptr['matches'] ?? false)) {
            $this->warn((string) ($ptr['note'] ?? ''));
        }
        $firewall = is_array($data['firewall'] ?? null) ? $data['firewall'] : [];
        if (($firewall['missing_ports'] ?? []) !== []) {
            $this->warn('Firewall (' . (string) ($firewall['backend'] ?? '') . ') is not allowing: '
                . implode(', ', (array) $firewall['missing_ports']));
        }
        $this->newLine();
        $this->line('Domains: ' . count((array) ($data['domains'] ?? []))
            . ' · mailboxes: ' . (string) ($data['mailbox_count'] ?? 0)
            . ' · aliases: ' . (string) ($data['alias_count'] ?? 0));

        return self::SUCCESS;
    }

    private function hostname(): int
    {
        $sub = $this->sub('show');
        try {
            if ($sub === 'show') {
                $data = $this->brokerData('mail.hostname.show', [], [], null, false);

                return $this->wantsJson()
                    ? $this->emitData($data)
                    : $this->say((string) ($data['hostname'] ?? '') ?: '(not set)');
            }
            $hostname = Validator::mailHostname((string) $this->option('hostname'));
            $data = $this->brokerData('mail.hostname.set', [], ['hostname' => $hostname]);
            $this->info('Mail hostname set to ' . (string) $data['hostname'] . '.');
            $this->line('Publish an A record for it and set matching reverse DNS at your VPS provider.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function domain(): int
    {
        $sub = $this->sub('list');
        try {
            if ($sub === 'list') {
                $data = $this->brokerData('mail.domain.list', [], [], null, false);
                if ($this->wantsJson()) {
                    return $this->emitData($data);
                }
                $rows = [];
                foreach ((array) ($data['domains'] ?? []) as $row) {
                    $rows[] = [
                        (string) $row['domain'],
                        ! empty($row['enabled']) ? 'yes' : 'no',
                        (string) $row['dkim_selector'],
                        (string) $row['mailbox_count'],
                        (string) $row['alias_count'],
                    ];
                }

                return $this->emitTable(['domain', 'mail', 'dkim selector', 'mailboxes', 'aliases'], $rows);
            }

            $domain = Validator::domain((string) $this->option('domain'));
            if ($sub === 'enable') {
                $data = $this->brokerData('mail.domain.enable', [$domain], [], 180);
                $this->info("Mail enabled for {$domain} (DKIM selector {$data['dkim_selector']}).");
                $this->warn((string) ($data['delivery_message'] ?? ''));
                $this->line('Publish these records, then run: azerioid mail dns --domain=' . $domain);
                $this->renderRecords((array) ($data['dns'] ?? []));

                return self::SUCCESS;
            }
            if ($sub !== 'disable') {
                return $this->invalid('Unknown mail domain action. Use: list|enable|disable');
            }
            $stdin = [];
            if ($this->option('drop-mail')) {
                $stdin['drop_mail'] = true;
                $stdin['confirm'] = $this->requireConfirm(Validator::DROP_MAIL_CONFIRM);
            }
            $this->brokerData('mail.domain.disable', [$domain], $stdin);
            $this->info("Mail disabled for {$domain}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function mailbox(): int
    {
        $sub = $this->sub('list');
        try {
            return match ($sub) {
                'list' => $this->mailboxList(),
                'add' => $this->mailboxAdd(),
                'passwd' => $this->mailboxPasswd(),
                'disable' => $this->mailboxToggle(true),
                'enable' => $this->mailboxToggle(false),
                'del', 'delete', 'rm' => $this->mailboxDelete(),
                default => $this->invalid('Unknown mail mailbox action. Use: list|add|passwd|disable|enable|del'),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function mailboxList(): int
    {
        $domain = trim((string) $this->option('domain'));
        $data = $this->brokerData('mail.mailbox.list', [], $domain !== '' ? ['domain' => $domain] : [], null, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $rows = [];
        foreach ((array) ($data['mailboxes'] ?? []) as $row) {
            $rows[] = [(string) $row['address'], ! empty($row['disabled']) ? 'disabled' : 'active', (string) $row['created_at']];
        }

        return $this->emitTable(['address', 'state', 'created'], $rows);
    }

    private function mailboxAdd(): int
    {
        $address = Validator::mailAddress((string) $this->option('address'));
        [$localPart, $domain] = explode('@', $address, 2);
        // Generated here and shown once, exactly like database passwords — never argv.
        $password = Format::password();
        Validator::password($password);

        $this->brokerData('mail.mailbox.add', [$localPart, $domain], ['password' => $password], 60);
        $this->line("Created mailbox {$address}.");
        $this->line('One-time password (will not be shown again):');
        $this->line($password);
        $this->newLine();
        $this->line('IMAP: ' . $this->imapHint() . ':993 (SSL/TLS) · SMTP submission: port 587 (STARTTLS) or 465 (SSL)');

        return self::SUCCESS;
    }

    private function mailboxPasswd(): int
    {
        $address = Validator::mailAddress((string) $this->option('address'));
        $password = Format::password();
        Validator::password($password);

        $this->brokerData('mail.mailbox.passwd', [$address], ['password' => $password], 60);
        $this->line("Reset password for {$address}.");
        $this->line('One-time password (will not be shown again):');
        $this->line($password);

        return self::SUCCESS;
    }

    private function mailboxToggle(bool $disable): int
    {
        $address = Validator::mailAddress((string) $this->option('address'));
        $this->brokerData($disable ? 'mail.mailbox.disable' : 'mail.mailbox.enable', [$address], [], 60);
        $this->info($address . ($disable ? ' disabled (mail retained).' : ' enabled.'));

        return self::SUCCESS;
    }

    private function mailboxDelete(): int
    {
        $address = Validator::mailAddress((string) $this->option('address'));
        $stdin = [];
        if ($this->option('drop-mail')) {
            $stdin['drop_mail'] = true;
            $stdin['confirm'] = $this->requireConfirm(Validator::DROP_MAIL_CONFIRM);
        }
        $data = $this->brokerData('mail.mailbox.del', [$address], $stdin, 60);
        $this->info("Deleted mailbox {$address}."
            . (($data['maildir_removed'] ?? false) ? ' Stored mail removed.' : ' Stored mail was left on disk.'));

        return self::SUCCESS;
    }

    private function alias(): int
    {
        $sub = $this->sub('list');
        try {
            if ($sub === 'list') {
                $domain = trim((string) $this->option('domain'));
                $data = $this->brokerData('mail.alias.list', [], $domain !== '' ? ['domain' => $domain] : [], null, false);
                if ($this->wantsJson()) {
                    return $this->emitData($data);
                }
                $rows = [];
                foreach ((array) ($data['aliases'] ?? []) as $row) {
                    $rows[] = [(string) $row['address'], (string) $row['destination'], ! empty($row['external']) ? 'external' : 'local'];
                }

                return $this->emitTable(['alias', 'destination', 'scope'], $rows);
            }
            $address = Validator::mailAddress((string) $this->option('address'));
            if ($sub === 'add') {
                $destination = Validator::mailAddress((string) $this->option('destination'));
                $stdin = $this->option('confirm-external') ? ['confirm_external' => true] : [];
                $data = $this->brokerData('mail.alias.add', [$address, $destination], $stdin, 60);
                $this->info("Alias {$address} → {$destination}"
                    . (($data['external'] ?? false) ? ' (forwards off this server).' : '.'));

                return self::SUCCESS;
            }
            if (! in_array($sub, ['del', 'delete', 'rm'], true)) {
                return $this->invalid('Unknown mail alias action. Use: list|add|del');
            }
            $this->brokerData('mail.alias.del', [$address], [], 60);
            $this->info("Deleted alias {$address}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function dns(): int
    {
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            $data = $this->brokerData('mail.dns.records', [$domain], [], 60, false);
            if ($this->wantsJson()) {
                return $this->emitData($data);
            }
            $this->line('Outbound mode: ' . (string) ($data['delivery_mode'] ?? 'direct')
                . ' (SPF below matches this mode).');
            $this->renderRecords((array) ($data['records'] ?? []));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function dkim(): int
    {
        if ($this->sub('rotate') !== 'rotate') {
            return $this->invalid('Unknown mail dkim action. Use: rotate');
        }
        try {
            $domain = Validator::domain((string) $this->option('domain'));
            $data = $this->brokerData('mail.dkim.rotate', [$domain], [], 180);
            $this->info("Rotated DKIM key for {$domain} (selector {$data['dkim_selector']}).");
            $this->warn('Publish the new TXT record before the old key stops signing:');
            $this->line($data['dkim_selector'] . '._domainkey.' . $domain . '  TXT  ' . (string) $data['record']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function smarthost(): int
    {
        $sub = $this->sub('show');
        try {
            return match ($sub) {
                'show' => $this->smarthostShow(),
                'set' => $this->smarthostSet(),
                'test' => $this->smarthostTest(),
                'clear' => $this->smarthostClear(),
                default => $this->invalid('Unknown mail smarthost action. Use: show|set|test|clear'),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function smarthostShow(): int
    {
        $data = $this->brokerData('mail.smarthost.show', [], [], null, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $relay = $data['smarthost'] ?? null;
        if (! is_array($relay)) {
            return $this->say('No relay configured (direct MX delivery).');
        }
        $this->line('Host: ' . (string) $relay['host'] . ':' . (string) $relay['port']);
        $this->line('Username: ' . (string) $relay['username']);
        $this->line('TLS: ' . (string) $relay['tls']);
        $this->line('Password stored: ' . (($relay['password_set'] ?? false) ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function smarthostSet(): int
    {
        $host = Validator::smarthostHost((string) $this->option('host'));
        $port = Validator::port((string) ($this->option('port') ?: '587'));
        $username = trim((string) $this->option('username'));
        if ($username === '') {
            throw new \RuntimeException('--username is required.');
        }
        $password = $this->relayPassword();

        $this->brokerData('mail.smarthost.set', [], [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'tls' => (string) $this->option('tls'),
        ], 60);
        $this->info("Relay configured: {$host}:{$port} as {$username}.");
        $this->line('Update SPF for each domain — run: azerioid mail dns --domain=<domain>');

        return self::SUCCESS;
    }

    private function smarthostTest(): int
    {
        $data = $this->brokerData('mail.smarthost.test', [], [], 30, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $detail = (string) ($data['detail'] ?? '');
        if ($data['ok'] ?? false) {
            $this->info($detail . ' (' . (string) ($data['latency_ms'] ?? 0) . ' ms)');

            return self::SUCCESS;
        }
        $this->error($detail);

        return self::FAILURE;
    }

    private function smarthostClear(): int
    {
        $confirm = $this->requireConfirm('CLEAR-RELAY');
        $this->brokerData('mail.smarthost.clear', [], ['confirm' => $confirm], 60);
        $this->info('Relay cleared. Outbound mail now goes direct to recipient MX.');
        $this->warn('If outbound port 25 is blocked on this host, mail will queue and eventually bounce.');

        return self::SUCCESS;
    }

    private function probe(): int
    {
        try {
            $data = $this->brokerData('mail.probe.outbound25', [], [], 30, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $message = (string) ($data['message'] ?? '');
        if ($data['open'] ?? false) {
            $this->info($message);
        } else {
            $this->warn($message);
        }
        $this->line('Probed ' . (string) $data['host'] . ':' . (string) $data['port']
            . ' in ' . (string) $data['latency_ms'] . ' ms.');

        return self::SUCCESS;
    }

    private function selftest(): int
    {
        try {
            $data = $this->brokerData('mail.relay.selftest', [], [], 30, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        if ($data['passed'] ?? false) {
            $this->info((string) $data['detail']);

            return self::SUCCESS;
        }
        $this->error((string) $data['detail']);

        return self::FAILURE;
    }

    private function queue(): int
    {
        try {
            $stdin = [];
            if ($this->option('flush')) {
                $stdin = ['flush' => true, 'confirm' => $this->requireConfirm('FLUSH-QUEUE')];
            }
            $data = $this->brokerData('mail.queue', [], $stdin, 90, (bool) $this->option('flush'));
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $this->line('Queued: ' . (string) ($data['total'] ?? 0)
            . ' · active: ' . (string) ($data['active'] ?? 0)
            . ' · deferred: ' . (string) ($data['deferred'] ?? 0)
            . ' · held: ' . (string) ($data['held'] ?? 0));
        foreach (array_slice((array) ($data['sample'] ?? []), 0, 20) as $line) {
            $this->line('  ' . (string) $line);
        }

        return self::SUCCESS;
    }

    private function logs(): int
    {
        try {
            $data = $this->brokerData('mail.logs', [], ['lines' => (int) $this->option('lines')], 30, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $this->line('# ' . (string) ($data['path'] ?? ''));
        foreach ((array) ($data['lines'] ?? []) as $line) {
            $this->line((string) $line);
        }

        return self::SUCCESS;
    }

    private function testSend(): int
    {
        if ($this->sub('send') !== 'send') {
            return $this->invalid('Unknown mail test action. Use: send');
        }
        try {
            $from = Validator::mailAddress((string) $this->option('from'));
            $to = Validator::mailAddress((string) $this->option('to'));
            $data = $this->brokerData('mail.test.send', [$from, $to], [
                'subject' => (string) $this->option('subject'),
            ], 90);
            $this->info("Test message queued from {$from} to {$to} via the {$data['delivery_mode']} path.");
            $this->line((string) $data['detail']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    /** Relay passwords never touch argv: environment or interactive prompt only. */
    private function relayPassword(): string
    {
        $password = (string) (getenv('AZERIOID_RELAY_PASSWORD') ?: getenv('RELAY_PASSWORD') ?: '');
        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Relay password (not echoed, not stored in shell history)');
        }
        if (trim($password) === '') {
            throw new \RuntimeException(
                'Set AZERIOID_RELAY_PASSWORD in the environment, or run interactively. Passwords are never accepted as arguments.'
            );
        }

        return $password;
    }

    private function requireConfirm(string $expected): string
    {
        $got = trim((string) $this->option('confirm'));
        if ($got === '') {
            throw new \RuntimeException("This operation requires --confirm={$expected}.");
        }

        return Validator::typedConfirm($got, $expected);
    }

    /** @param list<array<string,mixed>> $records */
    private function renderRecords(array $records): void
    {
        $rows = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $rows[] = [
                (string) ($record['type'] ?? ''),
                (string) ($record['name'] ?? ''),
                (string) ($record['value'] ?? ''),
                (string) ($record['state'] ?? '—'),
            ];
        }
        $this->table(['type', 'name', 'value', 'live check'], $rows);
    }

    private function imapHint(): string
    {
        try {
            $data = $this->brokerData('mail.hostname.show', [], [], null, false);

            return (string) ($data['hostname'] ?? '') ?: 'your mail hostname';
        } catch (\Throwable) {
            return 'your mail hostname';
        }
    }

    private function sub(string $default): string
    {
        $sub = strtolower(trim((string) $this->argument('subcommand')));

        return $sub !== '' ? $sub : $default;
    }

    private function say(string $line): int
    {
        $this->line($line);

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
