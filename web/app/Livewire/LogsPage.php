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

    public function mount(BrokerClient $broker): void
    {
        $this->load($broker);
    }

    public function load(BrokerClient $broker): void
    {
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
