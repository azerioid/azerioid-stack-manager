<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Logs · AZERIOID Stack Manager')]
class LogsPage extends Component
{
    public string $key = 'caddy';
    public int $lines = 200;
    public array $entries = [];
    public ?string $path = null;
    public bool $missing = false;
    public ?string $error = null;
    public string $needle = '';
    public bool $live = false;

    /** A82: when set, show this vhost's access log instead of a system log. */
    public string $vhostDomain = '';

    /** Managed domains for the per-site access-log picker. */
    public array $domains = [];

    public function mount(BrokerClient $broker): void
    {
        $res = $broker->call('vhost.list', [], [], 30, false);
        if ($res->ok && is_array($res->data)) {
            $this->domains = array_values(array_filter(array_map(
                static fn ($v) => is_array($v) ? (string) ($v['domain'] ?? '') : '',
                (array) ($res->data['vhosts'] ?? [])
            )));
        }
        $this->load($broker);
    }

    public function load(BrokerClient $broker): void
    {
        // A82: per-vhost access log (path derived from the domain in the broker).
        if ($this->vhostDomain !== '') {
            $stdin = ['lines' => $this->lines];
            if ($this->needle !== '') {
                $stdin['needle'] = $this->needle;
            }
            $res = $broker->call('logs.vhost', [$this->vhostDomain], $stdin);
            if (! $res->ok) {
                $this->error = $res->error;

                return;
            }
            $this->error = null;
            $this->entries = $res->data['lines'] ?? [];
            $this->path = $res->data['path'] ?? null;
            $this->missing = (bool) ($res->data['missing'] ?? false);

            return;
        }
        if ($this->needle !== '') {
            $res = $broker->call('logs.search', [$this->key, $this->needle]);
            if (! $res->ok) {
                $this->error = $res->error;
                return;
            }
            $this->error = null;
            $this->entries = $res->data['lines'] ?? [];
            $this->path = $res->data['path'] ?? null;
            $this->missing = (bool) ($res->data['missing'] ?? false);
            return;
        }
        $res = $broker->call('logs.tail', [$this->key, (string) $this->lines]);
        if (! $res->ok) {
            $this->error = $res->error;
            return;
        }
        $this->error = null;
        $this->entries = $res->data['lines'] ?? [];
        $this->path = $res->data['path'] ?? null;
        $this->missing = (bool) ($res->data['missing'] ?? false);
    }

    public function render()
    {
        return view('livewire.logs')->layoutData([
            'heading' => 'Logs',
            'sub' => 'read-only tails · path allowlist enforced in the broker',
        ]);
    }
}
