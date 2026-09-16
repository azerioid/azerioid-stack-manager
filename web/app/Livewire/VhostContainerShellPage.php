<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Container shell · AZERIOID Stack Manager')]
class VhostContainerShellPage extends Component
{
    public string $domain = '';
    public ?string $sessionId = null;
    public ?string $wsPath = null;
    public ?string $username = null;
    public ?string $root = null;
    public ?string $container = null;
    public int $idleSeconds = 1200;
    public ?string $error = null;

    public function mount(string $domain, BrokerClient $broker): void
    {
        $this->domain = Validator::domain($domain);
        $this->startSession($broker);
    }

    public function startSession(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $res = $broker->call('terminal.session.start', [$this->domain], [
                'mode' => 'container',
                'admin_user_id' => (string) auth()->id(),
                'source_ip' => (string) request()->ip(),
            ]);
            if (! $res->ok) {
                $this->error = (string) $res->error;

                return;
            }
            $this->sessionId = (string) ($res->data['session_id'] ?? '');
            $this->wsPath = (string) ($res->data['ws_path'] ?? '');
            $this->username = (string) ($res->data['username'] ?? '');
            $this->root = (string) ($res->data['root'] ?? '');
            $this->container = isset($res->data['container']) ? (string) $res->data['container'] : null;
            $this->idleSeconds = (int) ($res->data['idle_seconds'] ?? 1200);
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function stop(BrokerClient $broker): void
    {
        if ($this->sessionId === null) {
            return;
        }
        $broker->call('terminal.session.stop', [$this->sessionId]);
        $this->sessionId = null;
        $this->redirectRoute('vhosts', navigate: true);
    }

    public function render()
    {
        $sub = $this->domain !== ''
            ? $this->domain.' — container shell (rootless; not the host Terminal)'
            : '';
        if ($this->container) {
            $sub .= ' · '.$this->container;
        }

        return view('livewire.vhost-terminal')->layoutData([
            'heading' => 'Container shell',
            'sub' => $sub,
        ]);
    }
}
