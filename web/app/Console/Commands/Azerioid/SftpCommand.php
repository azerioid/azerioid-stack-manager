<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

/**
 * `azerioid sftp …` (A48 / request #11).
 *
 * Full parity with the panel, which matters more than usual here: this configures sshd, and an
 * operator who has just locked themselves out of the panel still has a shell. Every guarantee —
 * drop-in only, `sshd -t` before reload, reload never restart — lives in the broker, so this command
 * cannot skip any of them.
 */
class SftpCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:sftp
        {action : status|configure|unconfigure|enable|disable|keys|key-add|key-del}
        {domain? : vhost domain, for enable/disable/keys/key-add/key-del}
        {--key= : public key for key-add (or pipe it on stdin)}
        {--fingerprint= : SHA256:… for key-del}
        {--json : JSON output}';

    protected $description = 'Per-vhost SFTP: sshd drop-in, group membership and authorized keys';

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        try {
            return match ($action) {
                'status' => $this->status(),
                'configure' => $this->simple('sftp.configure', 'SFTP configured; sshd reloaded.'),
                'unconfigure' => $this->simple('sftp.unconfigure', 'Panel-managed SFTP removed; sshd reloaded.'),
                'enable' => $this->perDomain('sftp.enable', 'SFTP enabled for'),
                'disable' => $this->perDomain('sftp.disable', 'SFTP disabled for'),
                'keys' => $this->keys(),
                'key-add' => $this->keyAdd(),
                'key-del' => $this->keyDel(),
                default => $this->usage(),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function usage(): int
    {
        $this->error('Usage: azerioid sftp status|configure|unconfigure|enable|disable|keys|key-add|key-del [domain]');

        return self::INVALID;
    }

    private function status(): int
    {
        $data = $this->brokerData('sftp.status', [], [], 30, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $this->line('configured: ' . (($data['configured'] ?? false) ? 'yes' : 'no'));
        $this->line('drop-in:    ' . (string) ($data['drop_in'] ?? ''));
        $this->line('group:      ' . (string) ($data['group'] ?? ''));
        $this->line('service:    ' . (string) ($data['service'] ?? ''));
        $sites = (array) ($data['sites'] ?? []);
        $this->line('enabled:    ' . ($sites === [] ? 'none' : implode(', ', $sites)));
        if (($data['include_present'] ?? true) === false) {
            $this->warn('This host\'s sshd_config does not include sshd_config.d/*.conf, so a drop-in '
                . 'would be ignored. SFTP cannot be managed from here until that line exists.');
        }

        return self::SUCCESS;
    }

    private function simple(string $action, string $message): int
    {
        $res = $this->brokerCall($action, [], [], 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }
        $this->info($message);

        return self::SUCCESS;
    }

    private function perDomain(string $action, string $message): int
    {
        $domain = $this->domain();
        if ($domain === null) {
            return $this->usage();
        }
        $res = $this->brokerCall($action, [$domain], [], 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }
        $this->info($message . ' ' . $domain . ' (runs as ' . (string) ($res->data['user'] ?? '') . ').');
        if ($action === 'sftp.enable' && ($res->data['keys'] ?? null) === null) {
            $this->line('Install a key before anyone can connect: azerioid sftp key-add ' . $domain
                . ' --key="ssh-ed25519 …"');
        }

        return self::SUCCESS;
    }

    private function keys(): int
    {
        $domain = $this->domain();
        if ($domain === null) {
            return $this->usage();
        }
        $data = $this->brokerData('sftp.key.list', [$domain], [], 30, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $keys = (array) ($data['keys'] ?? []);
        if ($keys === []) {
            $this->line('No keys installed for ' . $domain . '; nobody can connect yet.');

            return self::SUCCESS;
        }
        $this->line('keys are stored at ' . (string) ($data['path'] ?? '') . ' (root-owned)');
        foreach ($keys as $key) {
            $this->line(sprintf(
                '%-14s %-50s %s',
                (string) ($key['type'] ?? ''),
                (string) ($key['fingerprint'] ?? ''),
                (string) ($key['comment'] ?? '')
            ));
        }

        return self::SUCCESS;
    }

    private function keyAdd(): int
    {
        $domain = $this->domain();
        if ($domain === null) {
            return $this->usage();
        }
        $key = trim((string) ($this->option('key') ?? ''));
        if ($key === '' && ! stream_isatty(STDIN)) {
            // Piping is the natural way to do this: `cat ~/.ssh/id_ed25519.pub | azerioid sftp key-add …`
            $key = trim((string) stream_get_contents(STDIN));
        }
        if ($key === '') {
            $this->error('Provide a public key with --key=… or on stdin.');

            return self::INVALID;
        }

        $res = $this->brokerCall('sftp.key.add', [$domain], ['key' => $key], 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }
        $this->info('Key installed for ' . $domain . ': ' . (string) ($res->data['fingerprint'] ?? ''));

        return self::SUCCESS;
    }

    private function keyDel(): int
    {
        $domain = $this->domain();
        $fingerprint = trim((string) ($this->option('fingerprint') ?? ''));
        if ($domain === null || $fingerprint === '') {
            $this->error('Usage: azerioid sftp key-del <domain> --fingerprint=SHA256:…');

            return self::INVALID;
        }
        $res = $this->brokerCall('sftp.key.del', [$domain, $fingerprint], [], 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }
        $this->info('Key removed from ' . $domain . '.');

        return self::SUCCESS;
    }

    private function domain(): ?string
    {
        $domain = trim((string) ($this->argument('domain') ?? ''));

        return $domain === '' ? null : $domain;
    }
}
