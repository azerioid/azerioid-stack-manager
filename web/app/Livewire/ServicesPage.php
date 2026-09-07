<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Services · AZERIOID Stack Manager')]
class ServicesPage extends Component
{
    public array $controlled = [];
    public array $observed = [];
    public ?string $raw = null;
    public ?string $rawUnit = null;
    public ?string $error = null;
    public ?string $flash = null;
    public ?string $pending = null;
    public string $pendingAction = '';

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function reload(BrokerClient $broker): void
    {
        try {
            $data = $broker->call('status.all')->dataOrFail();
            $this->controlled = $data['controlled'] ?? [];
            $this->observed = $data['observed'] ?? [];
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function ask(string $action, string $unit): void
    {
        $this->pendingAction = $action;
        $this->pending = $unit;
    }

    public function run(BrokerClient $broker): void
    {
        if ($this->pending === null || $this->pendingAction === '') {
            return;
        }
        $action = match ($this->pendingAction) {
            'start' => 'service.start',
            'stop' => 'service.stop',
            'restart' => 'service.restart',
            default => null,
        };
        if ($action === null) {
            return;
        }
        $res = $broker->call($action, [Validator::service($this->pending, array_column($this->controlled, 'unit'))]);
        $this->error = $res->ok ? null : $res->error;
        $this->flash = $res->ok ? "{$this->pendingAction} {$this->pending} completed." : null;
        $this->pending = null;
        $this->pendingAction = '';
        $this->reload($broker);
    }

    public function showRaw(BrokerClient $broker, string $unit): void
    {
        $res = $broker->call('service.status', [$unit]);
        $this->rawUnit = $unit;
        if (! $res->ok) {
            $this->raw = $res->error;
            return;
        }
        $raw = (string) ($res->data['raw'] ?? '');
        $journal = trim((string) ($res->data['journal'] ?? ''));
        $this->raw = $journal === ''
            ? $raw
            : $raw."\n\n--- journalctl -xeu {$unit} ---\n".$journal;
    }

    public function render()
    {
        return view('livewire.services')->layoutData([
            'heading' => 'Services',
            'sub' => 'Only panel-managed units can be started or stopped',
        ]);
    }
}
