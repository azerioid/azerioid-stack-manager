<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Processes · AZERIOID Stack Manager')]
class ProcessesPage extends Component
{
    public array $programs = [];
    public array $vhosts = [];
    public bool $supervisorInstalled = false;
    public ?string $error = null;
    public ?string $flash = null;

    public string $formMode = '';
    public string $name = '';
    public string $command = '';
    public string $directory = '';
    public bool $autostart = true;
    public bool $autorestart = true;
    public string $vhostDomain = '';
    public string $upstreamPort = '3000';

    public ?string $editingName = null;
    public ?string $viewingLogs = null;
    public string $logStdout = '';
    public string $logStderr = '';
    public ?string $pendingAction = null;
    public ?string $pendingName = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function reload(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $status = $broker->call('component.status', ['supervisor']);
            $this->supervisorInstalled = ($status->data['status'] ?? '') === 'active'
                || ($status->data['kind'] ?? '') === 'managed' && ($status->data['status'] ?? '') !== 'not_installed';
        } catch (BrokerCallException) {
            $this->supervisorInstalled = false;
        }

        try {
            $this->vhosts = array_values(array_filter(
                $broker->call('vhost.list')->dataOrFail()['vhosts'] ?? [],
                fn ($v) => empty($v['readonly'])
            ));
        } catch (BrokerCallException $e) {
            $this->vhosts = [];
            $this->error = $e->getMessage();
        }

        if (!$this->supervisorInstalled) {
            $this->programs = [];

            return;
        }

        try {
            $this->programs = $broker->call('supervisor.program.list')->dataOrFail()['programs'] ?? [];
        } catch (BrokerCallException $e) {
            $this->programs = [];
            $this->error = $e->getMessage();
        }
    }

    public function openFreeform(): void
    {
        $this->resetForm();
        $this->formMode = 'freeform';
        $this->directory = SupervisedUser::APPS_DIR;
    }

    public function openVhostTied(): void
    {
        $this->resetForm();
        $this->formMode = 'vhost';
    }

    public function updatedVhostDomain(): void
    {
        if ($this->vhostDomain === '') {
            return;
        }
        try {
            $domain = Validator::domain($this->vhostDomain);
            $this->directory = rtrim((string) config('azerioid.www_root'), '/') . '/' . $domain;
        } catch (\Throwable) {
        }
    }

    public function create(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $payload = [
                'name' => Validator::supervisorProgramName($this->name),
                'command' => Validator::supervisorCommand($this->command),
                'directory' => Validator::supervisedDirectory(
                    $this->directory,
                    (string) config('azerioid.www_root'),
                    new \AzerioidPanel\Broker\FakeRuntime()
                ),
                'autostart' => $this->autostart,
                'autorestart' => $this->autorestart,
            ];
            if ($this->formMode === 'vhost' && $this->vhostDomain !== '') {
                $payload['vhost_domain'] = Validator::domain($this->vhostDomain);
            }
            $res = $broker->call('supervisor.program.create', [], $payload);
            if (!$res->ok) {
                $this->error = (string) $res->error;

                return;
            }
            $this->flash = 'Created process ' . $payload['name'] . '.';
            if ($this->formMode === 'vhost' && $this->vhostDomain !== '' && $this->upstreamPort !== '') {
                $this->createProxyVhost($broker, $payload['vhost_domain'], (int) $this->upstreamPort);
            }
            $this->resetForm();
            $this->reload($broker);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function startEdit(string $name): void
    {
        foreach ($this->programs as $program) {
            if (($program['name'] ?? '') !== $name) {
                continue;
            }
            $this->editingName = $name;
            $this->formMode = ($program['vhost_domain'] ?? null) ? 'vhost' : 'freeform';
            $this->name = $name;
            $this->command = (string) ($program['command'] ?? '');
            $this->directory = (string) ($program['directory'] ?? '');
            $this->autostart = (bool) ($program['autostart'] ?? true);
            $this->autorestart = (bool) ($program['autorestart'] ?? true);
            $this->vhostDomain = (string) ($program['vhost_domain'] ?? '');

            return;
        }
    }

    public function saveEdit(BrokerClient $broker): void
    {
        if ($this->editingName === null) {
            return;
        }
        $this->error = null;
        try {
            $payload = [
                'command' => Validator::supervisorCommand($this->command),
                'directory' => Validator::supervisedDirectory(
                    $this->directory,
                    (string) config('azerioid.www_root'),
                    new \AzerioidPanel\Broker\FakeRuntime()
                ),
                'autostart' => $this->autostart,
                'autorestart' => $this->autorestart,
            ];
            if ($this->formMode === 'vhost') {
                $payload['vhost_domain'] = $this->vhostDomain !== ''
                    ? Validator::domain($this->vhostDomain)
                    : null;
            }
            $res = $broker->call('supervisor.program.update', [$this->editingName], $payload);
            if (!$res->ok) {
                $this->error = (string) $res->error;

                return;
            }
            $this->flash = 'Updated ' . $this->editingName . '.';
            $this->resetForm();
            $this->reload($broker);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function askControl(string $action, string $name): void
    {
        $this->pendingAction = $action;
        $this->pendingName = $name;
    }

    public function runControl(BrokerClient $broker): void
    {
        if ($this->pendingName === null || $this->pendingAction === null) {
            return;
        }
        $action = match ($this->pendingAction) {
            'start' => 'supervisor.program.start',
            'stop' => 'supervisor.program.stop',
            'restart' => 'supervisor.program.restart',
            'delete' => 'supervisor.program.delete',
            default => null,
        };
        if ($action === null) {
            return;
        }
        $res = $broker->call($action, [$this->pendingName]);
        $this->error = $res->ok ? null : (string) $res->error;
        $this->flash = $res->ok ? ucfirst($this->pendingAction) . ' ' . $this->pendingName . ' completed.' : null;
        $this->pendingAction = null;
        $this->pendingName = null;
        $this->reload($broker);
    }

    public function showLogs(BrokerClient $broker, string $name): void
    {
        $res = $broker->call('supervisor.program.logs', [$name], ['lines' => 100]);
        if (!$res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        $this->viewingLogs = $name;
        $this->logStdout = (string) ($res->data['stdout'] ?? '');
        $this->logStderr = (string) ($res->data['stderr'] ?? '');
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->formMode = '';
        $this->editingName = null;
        $this->reset('name', 'command', 'directory', 'vhostDomain', 'upstreamPort');
        $this->autostart = true;
        $this->autorestart = true;
        $this->upstreamPort = '3000';
    }

    private function createProxyVhost(BrokerClient $broker, string $domain, int $port): void
    {
        if ($port < 1 || $port > 65535) {
            return;
        }
        $upstream = Validator::localUpstream('127.0.0.1:' . $port);
        $root = rtrim((string) config('azerioid.www_root'), '/') . '/' . $domain;
        $existing = array_column($this->vhosts, 'domain');
        if (in_array($domain, $existing, true)) {
            return;
        }
        $res = $broker->call('vhost.add', [$domain, $root, 'proxy', $upstream]);
        if ($res->ok) {
            $this->flash .= ' Created proxy vhost ' . $domain . ' → ' . $upstream . '.';
        } else {
            $this->error = trim(($this->error ?? '') . ' Process created but vhost failed: ' . $res->error);
        }
    }

    public function render()
    {
        return view('livewire.processes')->layoutData([
            'heading' => 'Processes',
            'sub' => 'Supervisor-managed programs run as ' . SupervisedUser::USERNAME . ' — never root.',
        ]);
    }
}
