<?php

namespace App\Livewire;

use App\Console\Commands\RunScheduledBackup;
use App\Models\Setting;
use App\Services\Broker\BrokerClient;
use App\Services\OperationDispatcher;
use AzerioidPanel\Broker\Validator;
use Livewire\Component;

/**
 * Vhost bundles (B6, ADR A52): which databases belong to a site, run a bundle, list bundles,
 * preview and restore them — whole or in parts, same domain only.
 */
class BackupBundles extends Component
{
    /** @var list<string> */
    public array $domains = [];

    public string $domain = '';

    /** One "engine:name" per line, e.g. mariadb:shop */
    public string $dbText = '';

    public string $destination = 'local';

    /** @var list<array<string,mixed>> */
    public array $bundles = [];

    public ?array $preview = null;

    /** @var list<string> */
    public array $restoreParts = [];

    public string $restoreConfirm = '';

    public string $dbConfirm = '';

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $res = $broker->call('vhost.list', [], [], 60, false);
        foreach ($res->ok ? (array) ($res->data['vhosts'] ?? []) : [] as $v) {
            if (empty($v['readonly']) && ($v['domain'] ?? '') !== '') {
                $this->domains[] = (string) $v['domain'];
            }
        }
        $this->refresh($broker);
    }

    public function updatedDomain(BrokerClient $broker): void
    {
        $this->error = null;
        $this->dbText = '';
        if ($this->domain === '') {
            return;
        }
        $res = $broker->call('backup.vhost.settings', [$this->domain], [], 30, false);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->dbText = implode("\n", array_map(
            static fn (array $d): string => $d['engine'].':'.$d['name'],
            (array) ($res->data['databases'] ?? [])
        ));
    }

    public function saveDatabases(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($this->domain);
            $rows = [];
            foreach (preg_split('/\r?\n/', $this->dbText) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (! str_contains($line, ':')) {
                    throw new \RuntimeException("Database lines are engine:name, e.g. mariadb:shop — got \"{$line}\".");
                }
                [$engine, $name] = explode(':', $line, 2);
                $rows[] = ['engine' => trim($engine), 'name' => trim($name)];
            }
            $res = $broker->call('backup.vhost.settings.set', [$domain], ['databases' => $rows], 30);
            if (! $res->ok) {
                throw new \RuntimeException((string) $res->error);
            }
            $this->flash = "Saved: {$domain} bundles include ".count($rows).' database(s).';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function runBundle(OperationDispatcher $operations): void
    {
        $this->error = null;
        try {
            $domain = Validator::domain($this->domain);
            $operations->dispatch('backup.vhost.run', [$domain], $this->secrets($this->destination) + ['domain' => $domain]);
            $this->flash = "Bundle of {$domain} queued. Follow it on the Operations page.";
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function refresh(BrokerClient $broker): void
    {
        $this->bundles = [];
        foreach (['local', 'spaces'] as $dest) {
            if ($dest === 'spaces' && RunScheduledBackup::spacesStdin() === null) {
                continue;
            }
            $stdin = ['destination' => $dest] + ($dest === 'spaces' ? ['spaces' => RunScheduledBackup::spacesStdin()] : []);
            $res = $broker->call('backup.vhost.list', [], $stdin, 60, false);
            foreach ($res->ok ? (array) ($res->data['bundles'] ?? []) : [] as $b) {
                $this->bundles[] = $b + ['destination' => $dest];
            }
        }
    }

    public function previewRestore(BrokerClient $broker, string $domain, string $bundle, string $destination): void
    {
        $this->error = null;
        $this->preview = null;
        try {
            $res = $broker->call('backup.vhost.restore', [$domain], $this->secrets($destination) + ['bundle' => $bundle], 120);
            if (! $res->ok) {
                throw new \RuntimeException((string) $res->error);
            }
            $this->preview = ['domain' => $domain, 'bundle' => $bundle, 'destination' => $destination, 'manifest' => $res->data['manifest'] ?? []];
            $this->restoreParts = array_values((array) ($res->data['would_restore'] ?? []));
            $this->restoreConfirm = '';
            $this->dbConfirm = '';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function applyRestore(OperationDispatcher $operations): void
    {
        $this->error = null;
        try {
            if ($this->preview === null) {
                throw new \RuntimeException('Preview a bundle first.');
            }
            $domain = Validator::domain((string) $this->preview['domain']);
            if (strtoupper($domain) !== trim($this->restoreConfirm)) {
                throw new \RuntimeException('Type '.strtoupper($domain).' to confirm the restore.');
            }
            if ($this->restoreParts === []) {
                throw new \RuntimeException('Choose at least one part to restore.');
            }
            $operations->dispatch('backup.vhost.restore', [$domain], $this->secrets((string) $this->preview['destination']) + [
                'domain' => $domain,
                'bundle' => $this->preview['bundle'],
                'parts' => array_values($this->restoreParts),
                'apply' => true,
                'confirm' => strtoupper($domain),
                'db_confirm' => trim($this->dbConfirm),
            ]);
            $this->flash = "Restore of {$domain} from {$this->preview['bundle']} queued. Follow it on the Operations page.";
            $this->preview = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancelRestore(): void
    {
        $this->preview = null;
        $this->restoreParts = [];
    }

    /** @return array<string,mixed> */
    private function secrets(string $destination): array
    {
        $pass = Setting::getSecret('backup.passphrase');
        if ($pass === null) {
            throw new \RuntimeException('Set the backup passphrase first.');
        }
        $stdin = ['passphrase' => $pass, 'destination' => $destination];
        if ($destination === 'spaces') {
            $stdin['spaces'] = RunScheduledBackup::spacesStdin() ?? throw new \RuntimeException('Spaces credentials are incomplete.');
        }

        return $stdin;
    }

    public function render()
    {
        return view('livewire.backup-bundles');
    }
}
