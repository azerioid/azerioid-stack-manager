<?php

namespace App\Livewire;

use App\Jobs\RunPanelUpdateJob;
use App\Models\PanelUpdateOperation;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use Illuminate\Support\Facades\Auth;
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

    public string $pkgMgr = '';

    public string $updateSource = '';

    public string $distro = '';

    /** @var array<string, mixed>|null */
    public ?array $panelUpdate = null;

    public string $panelConfirm = '';

    /** @var array<string, mixed>|null */
    public ?array $panelOperation = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
        $this->loadPanelOperation();
    }

    public function reload(BrokerClient $broker): void
    {
        $u = $broker->call('updates.list', [], [], null, false);
        if ($u->ok) {
            $this->total = (int) ($u->data['total'] ?? 0);
            $this->security = (int) ($u->data['security'] ?? 0);
            $this->packages = $u->data['packages'] ?? [];
            $this->pkgMgr = (string) ($u->data['pkg_mgr'] ?? '');
            $this->updateSource = (string) ($u->data['source'] ?? '');
            $this->distro = (string) ($u->data['distro'] ?? '');
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

        $this->reloadPanelCheck($broker);
    }

    public function reloadPanelCheck(BrokerClient $broker): void
    {
        $check = $broker->call('panel.update.check', [], [], 120, audit: false);
        if ($check->ok) {
            $this->panelUpdate = $check->data;
        } else {
            $this->panelUpdate = [
                'error' => $check->error,
                'update_available' => false,
                'up_to_date' => false,
            ];
        }
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

    public function queuePanelUpdate(BrokerClient $broker): void
    {
        $this->error = null;
        $this->flash = null;

        if (! hash_equals(PanelUpdater::CONFIRM, trim($this->panelConfirm))) {
            $this->error = 'Type '.PanelUpdater::CONFIRM.' to confirm a panel self-update.';

            return;
        }

        if (PanelUpdateOperation::query()->whereIn('status', ['queued', 'running'])->exists()) {
            $this->error = 'A panel update is already queued or running.';

            return;
        }

        if (! empty($this->panelUpdate['dirty'])) {
            $this->error = 'Refusing panel update: managed source tree has uncommitted changes. Resolve them on the host first.';

            return;
        }

        $operation = PanelUpdateOperation::query()->create([
            'user_id' => Auth::id(),
            'status' => 'queued',
            'from_commit' => $this->panelUpdate['deployed_commit'] ?? null,
            'to_commit' => $this->panelUpdate['remote_commit'] ?? null,
        ]);

        RunPanelUpdateJob::dispatch($operation->id);
        $this->panelConfirm = '';
        $this->flash = 'Panel self-update queued (background job).';
        $this->loadPanelOperation();
    }

    public function pollPanelOperation(BrokerClient $broker): void
    {
        $this->loadPanelOperation();
        if ($this->panelOperation === null) {
            $this->reloadPanelCheck($broker);

            return;
        }

        $opKey = 'panel-up-'.$this->panelOperation['id'];
        $log = $broker->call('panel.update.operation.log', [$opKey], [], 15, audit: false);
        if ($log->ok && is_array($log->data['lines'] ?? null)) {
            PanelUpdateOperation::query()
                ->whereKey($this->panelOperation['id'])
                ->update(['log' => implode("\n", $log->data['lines'])]);
            $this->panelOperation['log'] = implode("\n", $log->data['lines']);
        }

        $row = PanelUpdateOperation::query()->find($this->panelOperation['id']);
        if ($row === null || ! $row->isActive()) {
            $this->panelOperation = $row ? [
                'id' => $row->id,
                'status' => $row->status,
                'log' => $row->log,
                'error' => $row->error,
                'rolled_back' => $row->rolled_back,
                'from_commit' => $row->from_commit,
                'to_commit' => $row->to_commit,
            ] : null;
            $this->reloadPanelCheck($broker);
            if ($row?->status === 'completed') {
                $this->flash = 'Panel self-update completed'
                    .($row->to_commit ? ' → '.substr((string) $row->to_commit, 0, 7) : '')
                    .'.';
            } elseif ($row?->status === 'failed') {
                $this->error = $row->error ?? 'Panel update failed.';
                if ($row->rolled_back) {
                    $this->flash = 'Update failed; panel was rolled back to the previous commit.';
                }
            }
        }
    }

    private function loadPanelOperation(): void
    {
        $row = PanelUpdateOperation::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($row === null) {
            // Keep last finished op briefly for display if just finished — only active for polling.
            $this->panelOperation = null;

            return;
        }

        $this->panelOperation = [
            'id' => $row->id,
            'status' => $row->status,
            'log' => $row->log,
            'error' => $row->error,
            'rolled_back' => $row->rolled_back,
            'from_commit' => $row->from_commit,
            'to_commit' => $row->to_commit,
        ];
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
        $mgr = $this->pkgMgr !== '' ? $this->pkgMgr : 'apt/dnf';

        return view('livewire.updates')->layoutData([
            'heading' => 'Updates',
            'sub' => "OS packages ({$mgr}) · panel self-update · TLS — two separate systems",
        ]);
    }
}
