<?php

namespace App\Livewire;

use App\Jobs\RunComponentOperationJob;
use App\Models\ComponentOperation;
use App\Services\Broker\BrokerClient;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Components · AZERIOID Stack Manager')]
class ComponentsPage extends Component
{
    public array $catalog = [];

    public array $observedExtras = [];

    public string $distroLabel = '';

    public ?string $error = null;

    public ?string $flash = null;

    public ?array $activeOperation = null;

    public ?string $pendingUninstall = null;

    public bool $dumpBeforeUninstall = true;

    public ?string $pendingInstall = null;

    public string $nodeMajor = '22';

    /** @var list<array<string, string>> */
    public array $preflightRemediations = [];

    public ?string $pendingPreflightComponent = null;

    public string $pendingPreflightAction = '';

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
        $this->loadActiveOperation();
    }

    public function reload(BrokerClient $broker): void
    {
        $response = $broker->call('component.list');
        if (!$response->ok) {
            $this->error = $response->error ?? 'Could not load component registry.';

            return;
        }

        $this->error = null;
        $data = $response->data;
        $this->catalog = is_array($data['components'] ?? null) ? $data['components'] : [];
        $this->observedExtras = is_array($data['observed_extras'] ?? null) ? $data['observed_extras'] : [];
        $distro = (string) ($data['distro_id'] ?? 'unknown');
        $version = (string) ($data['distro_version'] ?? '');
        $this->distroLabel = trim($distro.' '.$version);
    }

    public function askInstall(string $componentId, BrokerClient $broker): void
    {
        $component = collect($this->catalog)->firstWhere('id', $componentId);
        $options = is_array($component['install_options'] ?? null) ? $component['install_options'] : [];
        if ($options === []) {
            $this->queueInstall($componentId, [], $broker);

            return;
        }
        $this->pendingInstall = $componentId;
        $this->nodeMajor = (string) ($options['node_major']['default'] ?? '22');
    }

    public function cancelInstall(): void
    {
        $this->pendingInstall = null;
    }

    public function confirmInstall(BrokerClient $broker): void
    {
        if ($this->pendingInstall === null) {
            return;
        }
        $options = [];
        if ($this->pendingInstall === 'nodejs') {
            $options['node_major'] = $this->nodeMajor;
        }
        $componentId = $this->pendingInstall;
        $this->pendingInstall = null;
        $this->queueInstall($componentId, $options, $broker);
    }

    public function install(string $componentId, BrokerClient $broker): void
    {
        $this->askInstall($componentId, $broker);
    }

    private function queueInstall(string $componentId, array $options, BrokerClient $broker): void
    {
        $this->flash = null;
        if ($this->hasBlockingOperation()) {
            $this->error = 'Another component operation is already queued or running.';

            return;
        }

        $preflight = $broker->call('component.preflight', [$componentId]);
        if (!$preflight->ok) {
            $this->error = $preflight->error ?? 'Preflight check failed.';

            return;
        }
        $issues = $preflight->data['issues'] ?? [];
        if (is_array($issues) && $issues !== []) {
            $this->preflightRemediations = is_array($preflight->data['remediations'] ?? null)
                ? $preflight->data['remediations']
                : [];
            $this->pendingPreflightComponent = $componentId;
            $this->pendingPreflightAction = 'install';
            $this->error = implode(' ', $issues);

            return;
        }
        $this->preflightRemediations = [];
        $this->pendingPreflightComponent = null;

        $operation = ComponentOperation::query()->create([
            'user_id' => Auth::id(),
            'component_id' => $componentId,
            'action' => 'install',
            'options' => $options !== [] ? $options : null,
            'status' => 'queued',
        ]);

        RunComponentOperationJob::dispatch($operation->id);
        $this->flash = "Queued install for {$componentId}.";
        $this->loadActiveOperation();
    }

    public function adopt(string $componentId, BrokerClient $broker): void
    {
        $this->flash = null;
        $this->error = null;
        $this->preflightRemediations = [];
        $this->pendingPreflightComponent = null;

        $preflight = $broker->call('component.preflight', [$componentId]);
        if (!$preflight->ok) {
            $this->error = $preflight->error ?? 'Preflight check failed.';

            return;
        }
        $issues = $preflight->data['issues'] ?? [];
        if (is_array($issues) && $issues !== []) {
            $this->preflightRemediations = is_array($preflight->data['remediations'] ?? null)
                ? $preflight->data['remediations']
                : [];
            $this->pendingPreflightComponent = $componentId;
            $this->pendingPreflightAction = 'adopt';
            $this->error = implode(' ', $issues);

            return;
        }

        $response = $broker->call('component.adopt', [$componentId]);
        if (!$response->ok) {
            $this->error = $response->error ?? 'Adopt failed.';

            return;
        }
        $note = $response->data['migration_note'] ?? null;
        $this->flash = is_string($note) && $note !== ''
            ? "Adopted {$componentId}. {$note}"
            : "Adopted {$componentId} — now managed by the panel.";
        $this->reload($broker);
    }

    public function releaseSitePorts(BrokerClient $broker): void
    {
        $this->flash = null;
        $this->error = null;
        $response = $broker->call('web.release-site-ports');
        if (!$response->ok) {
            $this->error = $response->error ?? 'Could not release site ports from panel Caddy.';

            return;
        }
        $port = (int) ($response->data['panel_port'] ?? 3169);
        $note = (string) ($response->data['note'] ?? '');
        if (! empty($response->data['deprecated']) || ($response->data['released'] ?? true) === false) {
            $this->flash = $note !== ''
                ? $note
                : 'Caddy already owns :80/:443 as the front router. Apache/Nginx are loopback backends — no port release is needed.';
        } else {
            $this->flash = "Released :80/:443 from panel Caddy. Panel remains on port {$port}.";
        }
        $this->preflightRemediations = [];
        $componentId = $this->pendingPreflightComponent;
        $action = $this->pendingPreflightAction;
        $this->pendingPreflightComponent = null;
        $this->pendingPreflightAction = '';
        $this->reload($broker);
        if (is_string($componentId) && $componentId !== '') {
            if ($action === 'adopt') {
                $this->adopt($componentId, $broker);
            } else {
                $this->queueInstall($componentId, [], $broker);
            }
        }
    }

    public function dismissPreflightRemediation(): void
    {
        $this->preflightRemediations = [];
        $this->pendingPreflightComponent = null;
        $this->pendingPreflightAction = '';
    }

    public function askUninstall(string $componentId): void
    {
        $this->pendingUninstall = $componentId;
        $this->dumpBeforeUninstall = true;
    }

    public function cancelUninstall(): void
    {
        $this->pendingUninstall = null;
        $this->dumpBeforeUninstall = true;
    }

    public function uninstall(BrokerClient $broker): void
    {
        if ($this->pendingUninstall === null) {
            return;
        }
        $componentId = $this->pendingUninstall;
        $this->pendingUninstall = null;
        $this->flash = null;

        if ($this->hasBlockingOperation()) {
            $this->error = 'Another component operation is already queued or running.';

            return;
        }

        if ($this->dumpBeforeUninstall && in_array($componentId, ['mariadb', 'postgresql'], true)) {
            $dump = $broker->call('db.dump', ['all'], ['engine' => $componentId], 900);
            if (!$dump->ok) {
                $this->error = $dump->error ?? 'Database dump failed before uninstall.';

                return;
            }
            $path = (string) ($dump->data['path'] ?? '');
            $this->flash = $path !== ''
                ? "Dump saved to {$path} before uninstall."
                : 'Database dump completed before uninstall.';
        }

        $operation = ComponentOperation::query()->create([
            'user_id' => Auth::id(),
            'component_id' => $componentId,
            'action' => 'uninstall',
            'status' => 'queued',
        ]);

        RunComponentOperationJob::dispatch($operation->id);
        $this->flash = "Queued uninstall for {$componentId}.";
        $this->loadActiveOperation();
    }

    public function pollOperation(BrokerClient $broker): void
    {
        $this->loadActiveOperation();
        if ($this->activeOperation === null) {
            $this->reload($broker);

            return;
        }

        $opKey = 'op-' . $this->activeOperation['id'];
        $log = $broker->call('component.operation.log', [$opKey], [], 15, audit: false);
        if ($log->ok && is_array($log->data['lines'] ?? null)) {
            ComponentOperation::query()
                ->whereKey($this->activeOperation['id'])
                ->update(['log' => implode("\n", $log->data['lines'])]);
            $this->activeOperation['log'] = implode("\n", $log->data['lines']);
        }

        $row = ComponentOperation::query()->find($this->activeOperation['id']);
        if ($row === null || !$row->isActive()) {
            $this->activeOperation = null;
            $this->reload($broker);
            if ($row?->status === 'completed') {
                $this->flash = "{$row->component_id} {$row->action} completed.";
            } elseif ($row?->status === 'failed') {
                $this->error = $row->error ?? 'Operation failed.';
            }
        }
    }

    private function hasBlockingOperation(): bool
    {
        return ComponentOperation::query()
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }

    private function loadActiveOperation(): void
    {
        $row = ComponentOperation::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        $this->activeOperation = $row ? [
            'id' => $row->id,
            'component_id' => $row->component_id,
            'action' => $row->action,
            'status' => $row->status,
            'log' => $row->log,
        ] : null;
    }

    public function render()
    {
        $system = array_values(array_filter($this->catalog, fn (array $c) => ($c['kind'] ?? '') === 'system'));
        $managed = array_values(array_filter(
            $this->catalog,
            fn (array $c) => in_array($c['kind'] ?? '', ['managed'], true) && ($c['status'] ?? '') !== 'broken'
        ));
        $observed = array_values(array_filter($this->catalog, fn (array $c) => ($c['kind'] ?? '') === 'observed'));
        $broken = array_values(array_filter($this->catalog, fn (array $c) => ($c['status'] ?? '') === 'broken'));

        return view('livewire.components', [
            'systemComponents' => $system,
            'managedComponents' => $managed,
            'observedComponents' => $observed,
            'brokenComponents' => $broken,
            'operationBusy' => $this->activeOperation !== null,
        ])->layoutData([
            'heading' => 'Components',
            'sub' => 'Install stack components from the registry',
        ]);
    }
}
