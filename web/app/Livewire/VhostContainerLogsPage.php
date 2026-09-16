<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Container logs · AZERIOID Stack Manager')]
class VhostContainerLogsPage extends Component
{
    public string $domain = '';
    public string $output = '';
    public int $lines = 200;
    public ?string $error = null;
    public bool $ok = true;

    public function mount(string $domain, BrokerClient $broker): void
    {
        $this->domain = Validator::domain($domain);
        $this->refresh($broker);
    }

    public function refresh(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $res = $broker->call('vhost.docker.logs', [$this->domain], [
                'lines' => $this->lines,
            ], 60, false);
            if (! $res->ok) {
                $this->error = (string) $res->error;
                $this->ok = false;

                return;
            }
            $this->output = (string) ($res->data['output'] ?? '');
            $this->ok = (bool) ($res->data['ok'] ?? true);
            if (isset($res->data['lines'])) {
                $this->lines = (int) $res->data['lines'];
            }
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
            $this->ok = false;
        }
    }

    public function render()
    {
        return view('livewire.vhost-container-logs')->layoutData([
            'heading' => 'Container logs',
            'sub' => $this->domain !== '' ? $this->domain.' — rootless docker logs (polled)' : '',
        ]);
    }
}
