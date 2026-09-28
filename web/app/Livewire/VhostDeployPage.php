<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use App\Services\OperationDispatcher;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Git deploy for one site (B8, ADR A41/A53): repository, branch, post-deploy command,
 * schedule, the deploy key to register, deploy and roll back, history.
 */
#[Layout('layouts.app')]
#[Title('Git deploy · AZERIOID Stack Manager')]
class VhostDeployPage extends Component
{
    #[Locked]
    public string $domain = '';

    public string $repository = '';

    public string $branch = 'main';

    public string $preset = 'none';

    public string $command = '';

    public string $confirm = '';

    public string $scheduleMode = 'off';

    public int $scheduleHour = 3;

    public ?string $publicKey = null;

    public array $state = [];

    public bool $configured = false;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(BrokerClient $broker, string $domain): void
    {
        $this->domain = Validator::domain($domain);
        $this->load($broker);
    }

    private function load(BrokerClient $broker): void
    {
        $res = $broker->call('deploy.config', [$this->domain], [], 30, false);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $d = (array) $res->data;
        $this->configured = (bool) ($d['configured'] ?? false);
        $this->repository = (string) ($d['repository'] ?? $this->repository);
        $this->branch = (string) ($d['branch'] ?? $this->branch);
        $this->preset = (string) ($d['preset'] ?? $this->preset);
        $this->command = (string) ($d['command'] ?? '');
        $schedule = (string) ($d['schedule'] ?? 'off');
        if (str_starts_with($schedule, 'daily@')) {
            $this->scheduleMode = 'daily';
            $this->scheduleHour = (int) substr($schedule, 6);
        } else {
            $this->scheduleMode = $schedule;
        }
        $this->publicKey = $d['public_key'] ?? null;
        $this->state = (array) ($d['state'] ?? []);
    }

    public function save(BrokerClient $broker): void
    {
        $this->error = null;
        $res = $broker->call('deploy.config.set', [$this->domain], [
            'repository' => trim($this->repository),
            'branch' => trim($this->branch),
            'preset' => $this->preset,
            'command' => trim($this->command),
            'confirm' => trim($this->confirm),
            'schedule' => $this->scheduleMode === 'daily' ? 'daily@'.$this->scheduleHour : $this->scheduleMode,
        ], 60);
        $this->confirm = '';
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->flash = 'Saved. Add the deploy key below to the repository (read-only access is enough) before the first deploy.';
        $this->load($broker);
    }

    public function deployNow(OperationDispatcher $operations): void
    {
        $this->queue($operations, 'deploy.run', "Deploy of {$this->domain} queued.");
    }

    public function rollback(OperationDispatcher $operations): void
    {
        $this->queue($operations, 'deploy.rollback', "Rollback of {$this->domain} queued.");
    }

    private function queue(OperationDispatcher $operations, string $action, string $message): void
    {
        $this->error = null;
        try {
            $operations->dispatch($action, [$this->domain], ['domain' => $this->domain, 'trigger' => 'manual']);
            $this->flash = $message.' Follow it on the Operations page.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function rotateKey(BrokerClient $broker): void
    {
        $res = $broker->call('deploy.key.rotate', [$this->domain], [], 60);
        $res->ok ? $this->flash = 'New deploy key created. Replace the old one at the repository.' : $this->error = (string) $res->error;
        $this->load($broker);
    }

    public function remove(BrokerClient $broker): void
    {
        $res = $broker->call('deploy.remove', [$this->domain], [], 60);
        $res->ok ? $this->flash = 'Deploy settings, key and mirror removed. The site\'s files are unchanged.' : $this->error = (string) $res->error;
        $this->repository = '';
        $this->load($broker);
    }

    public function refresh(BrokerClient $broker): void
    {
        $this->load($broker);
    }

    public function render()
    {
        return view('livewire.vhost-deploy');
    }
}
