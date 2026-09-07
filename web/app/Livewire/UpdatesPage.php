<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Updates · AZERIOID Stack Manager')]
class UpdatesPage extends Component
{
    public int $total = 0;
    public int $security = 0;
    public array $packages = [];
    public bool $rebootRequired = false;
    public array $rebootPackages = [];
    public array $certs = [];
    public string $confirm = '';
    public ?string $output = null;
    public ?string $flash = null;
    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function reload(BrokerClient $broker): void
    {
        $u = $broker->call('updates.list', [], [], null, false);
        if ($u->ok) {
            $this->total = (int) ($u->data['total'] ?? 0);
            $this->security = (int) ($u->data['security'] ?? 0);
            $this->packages = $u->data['packages'] ?? [];
        } else {
            $this->error = $u->error;
        }
        $rr = $broker->call('system.reboot-required', [], [], null, false);
        if ($rr->ok) {
            $this->rebootRequired = (bool) ($rr->data['required'] ?? false);
            $this->rebootPackages = $rr->data['packages'] ?? [];
        }
        $tls = $broker->call('tls.certs', [], [], null, false);
        $this->certs = $tls->ok ? ($tls->data['certs'] ?? []) : [];
    }

    public function applySecurity(BrokerClient $broker): void
    {
        $res = $broker->call('updates.apply.security', [], ['confirm' => $this->confirm], 900);
        $this->finishApply($broker, $res);
    }

    public function applyAll(BrokerClient $broker): void
    {
        $res = $broker->call('updates.apply.all', [], ['confirm' => $this->confirm], 900);
        $this->finishApply($broker, $res);
    }

    public function reboot(BrokerClient $broker): void
    {
        $res = $broker->call('system.reboot', [], ['confirm' => $this->confirm]);
        $this->finishApply($broker, $res);
    }

    private function finishApply(BrokerClient $broker, \App\Services\Broker\BrokerResponse $res): void
    {
        $this->confirm = '';
        $this->output = $res->ok ? (string) ($res->data['output'] ?? 'ok') : $res->error;
        $this->error = $res->ok ? null : $res->error;
        $this->flash = $res->ok ? 'Command completed.' : null;
        $this->reload($broker);
    }

    public function render()
    {
        return view('livewire.updates')->layoutData([
            'heading' => 'Updates & TLS',
            'sub' => 'apt · reboot-required · certificate expiry',
        ]);
    }
}
