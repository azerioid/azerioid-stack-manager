<?php

namespace App\Livewire;

use App\Console\Commands\RunScheduledBackup;
use App\Models\BackupJob;
use App\Models\Setting;
use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Backups · AZERIOID Stack Manager')]
class BackupsPage extends Component
{
    public string $endpoint = '';
    public string $region = '';
    public string $bucket = '';
    public string $access_key = '';
    public string $secret = '';
    public bool $keys_set = false;
    public string $passphrase = '';
    public bool $pass_set = false;
    public string $db = 'all';
    public string $site = '';
    public bool $include_fpm = false;
    public array $objects = [];
    public array $history = [];
    public string $restore_key = '';
    public string $restore_target = '';
    public string $restore_site = '';
    public string $restore_confirm = '';
    public bool $restore_force = false;
    public bool $restore_overwrite = false;
    public ?array $preview = null;
    public ?string $flash = null;
    public ?string $error = null;
    public bool $schedule_enabled = false;
    public int $schedule_hour = 3;
    public string $cadence = 'daily';
    public int $keep = 14;

    public function mount(BrokerClient $broker): void
    {
        $this->endpoint = (string) Setting::get('spaces.endpoint', 'https://fra1.digitaloceanspaces.com');
        $this->region = (string) Setting::get('spaces.region', 'fra1');
        $this->bucket = (string) Setting::get('spaces.bucket', '');
        $this->keys_set = Setting::secretIsSet('spaces.access_key') && Setting::secretIsSet('spaces.secret');
        $this->pass_set = Setting::secretIsSet('backup.passphrase');
        $sched = Setting::get('backup.schedule', []);
        if (is_array($sched)) {
            $this->schedule_enabled = (bool) ($sched['enabled'] ?? false);
            $this->schedule_hour = (int) ($sched['hour'] ?? 3);
            $this->cadence = (string) ($sched['cadence'] ?? 'daily');
            $this->keep = (int) ($sched['keep'] ?? 14);
        }
        $this->history = BackupJob::query()->latest()->limit(20)->get()->toArray();
        $this->refreshList($broker);
    }

    public function saveCredentials(): void
    {
        $this->validate([
            'endpoint' => ['required', 'url'],
            'region' => ['required', 'regex:/^[a-z0-9-]{2,32}$/'],
            'bucket' => ['required', 'regex:/^[A-Za-z0-9.-]{3,63}$/'],
        ]);
        Setting::put('spaces.endpoint', $this->endpoint);
        Setting::put('spaces.region', $this->region);
        Setting::put('spaces.bucket', $this->bucket);
        if ($this->access_key !== '') {
            Setting::putSecret('spaces.access_key', $this->access_key);
            $this->access_key = '';
        }
        if ($this->secret !== '') {
            Setting::putSecret('spaces.secret', $this->secret);
            $this->secret = '';
        }
        if ($this->passphrase !== '') {
            if (strlen($this->passphrase) < 16) {
                $this->addError('passphrase', 'Passphrase must be at least 16 characters.');
                return;
            }
            Setting::putSecret('backup.passphrase', $this->passphrase);
            $this->passphrase = '';
            $this->pass_set = true;
        }
        $this->keys_set = Setting::secretIsSet('spaces.access_key');
        $this->flash = 'Backup credentials saved encrypted.';
    }

    public function saveSchedule(): void
    {
        Setting::put('backup.schedule', [
            'enabled' => $this->schedule_enabled,
            'hour' => $this->schedule_hour,
            'cadence' => $this->cadence,
            'keep' => $this->keep,
            'databases' => ['all'],
        ]);
        $this->flash = 'Schedule saved.';
    }

    public function testSpaces(BrokerClient $broker): void
    {
        $stdin = $this->stdin();
        if ($stdin === null) {
            $this->error = 'Save Spaces credentials first.';
            return;
        }
        $res = $broker->call('spaces.test', [], $stdin);
        $this->flash = $res->ok ? 'Spaces connection ok.' : null;
        $this->error = $res->ok ? null : $res->error;
    }

    public function runDb(BrokerClient $broker): void
    {
        $this->run($broker, 'backup.db', [$this->db], 'db', $this->db);
    }

    public function runFiles(BrokerClient $broker): void
    {
        $this->run($broker, 'backup.files', [$this->site], 'files', $this->site);
    }

    public function runCaddy(BrokerClient $broker): void
    {
        $this->run($broker, 'backup.caddy', [], 'caddy', 'caddy', ['include_fpm' => $this->include_fpm]);
    }

    public function restoreDb(BrokerClient $broker): void
    {
        $stdin = $this->stdin();
        if ($stdin === null) {
            $this->error = 'Credentials missing.';
            return;
        }
        $stdin['target'] = $this->restore_target;
        $stdin['key'] = $this->restore_key;
        $stdin['overwrite'] = $this->restore_overwrite;
        $stdin['confirm'] = $this->restore_confirm;
        $res = $broker->call('backup.restore.db', [$this->restore_key], $stdin, 900);
        $this->flash = $res->ok ? 'Restored into '.$this->restore_target : null;
        $this->error = $res->ok ? null : $res->error;
    }

    public function previewFiles(BrokerClient $broker): void
    {
        $stdin = $this->stdin();
        if ($stdin === null) {
            $this->error = 'Credentials missing.';
            return;
        }
        $stdin['site'] = $this->restore_site;
        $stdin['key'] = $this->restore_key;
        $stdin['apply'] = false;
        $res = $broker->call('backup.restore.files', [$this->restore_key], $stdin, 900);
        $this->preview = $res->ok ? $res->data : null;
        $this->error = $res->ok ? null : $res->error;
        $this->flash = $res->ok ? 'Staged. Review the listing, then apply.' : null;
    }

    public function applyFiles(BrokerClient $broker): void
    {
        $stdin = $this->stdin();
        if ($stdin === null) {
            return;
        }
        $stdin['site'] = $this->restore_site;
        $stdin['apply'] = true;
        $stdin['force'] = $this->restore_force;
        $stdin['confirm'] = $this->restore_confirm;
        $res = $broker->call('backup.restore.files', [$this->restore_key], $stdin, 900);
        $this->flash = $res->ok ? 'Files applied.' : null;
        $this->error = $res->ok ? null : $res->error;
    }

    /** @param array<int,string> $args */
    private function run(BrokerClient $broker, string $action, array $args, string $kind, string $name, array $extra = []): void
    {
        $stdin = $this->stdin();
        if ($stdin === null) {
            $this->error = 'Save Spaces keys and a 16+ char passphrase first.';
            return;
        }
        $job = BackupJob::query()->create(['kind' => $kind, 'name' => $name, 'status' => 'running']);
        $start = microtime(true);
        $res = $broker->call($action, $args, $stdin + $extra, 900);
        $job->forceFill([
            'status' => $res->ok ? 'ok' : 'failed',
            'object_key' => $res->ok ? ($res->data['key'] ?? null) : null,
            'size' => $res->ok ? ($res->data['size'] ?? null) : null,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            'error' => $res->ok ? null : $res->error,
        ])->save();
        $this->flash = $res->ok ? 'Backup uploaded.' : null;
        $this->error = $res->ok ? null : $res->error;
        $this->history = BackupJob::query()->latest()->limit(20)->get()->toArray();
        $this->refreshList($broker);
    }

    private function refreshList(BrokerClient $broker): void
    {
        $stdin = $this->stdin();
        if ($stdin === null) {
            return;
        }
        $res = $broker->call('backup.list', [], $stdin, 60, false);
        $this->objects = $res->ok ? ($res->data['objects'] ?? []) : [];
    }

    /** @return array<string,mixed>|null */
    private function stdin(): ?array
    {
        $spaces = RunScheduledBackup::spacesStdin();
        $pass = Setting::getSecret('backup.passphrase');
        if ($spaces === null || $pass === null) {
            return null;
        }
        return ['spaces' => $spaces, 'passphrase' => $pass];
    }

    public function render()
    {
        return view('livewire.backups')->layoutData([
            'heading' => 'Backups',
            'sub' => 'encrypted · Spaces · restore to a new name / staging',
        ]);
    }
}
