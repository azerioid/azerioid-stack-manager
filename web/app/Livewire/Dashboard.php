<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Overview · AZERIOID Stack Manager')]
class Dashboard extends Component
{
    public array $status = [];
    public array $metrics = [];
    public array $versions = [];
    public array $audit = [];
    public array $samples = [];
    public int $openIncidents = 0;
    public ?string $flash = null;
    public ?string $error = null;
    public ?string $confirmService = null;
    public bool $confirmAll = false;

    public function mount(BrokerClient $broker): void
    {
        $this->refresh($broker);
    }

    public function refresh(BrokerClient $broker): void
    {
        try {
            $this->status = $broker->call('status.all', [], [], null, false)->dataOrFail();
            $this->metrics = $broker->call('metrics.system', [], [], null, false)->dataOrFail();
            $this->versions = $broker->call('version.all', [], [], null, false)->dataOrFail();
            $this->audit = \App\Models\AuditLog::query()->latest()->limit(8)->get()->toArray();
            $this->samples = \App\Models\MetricSample::query()->orderByDesc('sampled_at')->limit(48)->get()->reverse()->values()->toArray();
            $this->openIncidents = \App\Models\AlertIncident::query()->where('status', 'open')->count();
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function restartService(BrokerClient $broker, string $unit): void
    {
        $allowed = [];
        foreach ($this->status['controlled'] ?? [] as $row) {
            if (! empty($row['controllable']) && isset($row['unit'])) {
                $allowed[] = (string) $row['unit'];
            }
        }
        if (! in_array($unit, $allowed, true)) {
            $this->error = 'Service is not in the panel control allowlist.';
            $this->confirmService = null;

            return;
        }
        $this->mutate($broker, 'service.restart', [$unit]);
        $this->confirmService = null;
    }

    public function restartAll(BrokerClient $broker): void
    {
        $errors = [];
        foreach (array_column($this->status['controlled'] ?? [], 'unit') as $unit) {
            if (in_array($unit, ['caddy', 'apache2', 'httpd', 'mariadb'], true)) {
                $res = $broker->call('service.restart', [$unit]);
                if (! $res->ok) {
                    $errors[] = $unit.":\n".($res->error ?: 'restart failed');
                }
            }
        }
        $php = $this->versions['php']['installed'] ?? [];
        foreach ($php as $ver) {
            $unit = 'php'.$ver.'-fpm';
            $res = $broker->call('service.restart', [$unit]);
            if (! $res->ok) {
                $errors[] = $unit.":\n".($res->error ?: 'restart failed');
            }
        }
        $this->error = $errors === [] ? null : implode("\n\n", $errors);
        $this->flash = $errors === [] ? 'Stack restart completed.' : null;
        $this->confirmAll = false;
        $this->refresh($broker);
    }

    public function bindMariadbLocalhost(BrokerClient $broker): void
    {
        $this->mutate($broker, 'mariadb.bind.fix');
    }

    private function mutate(BrokerClient $broker, string $action, array $args = []): void
    {
        $res = $broker->call($action, $args);
        if ($res->ok) {
            $this->flash = $action . ' succeeded.';
            $this->error = null;
        } else {
            $this->error = $res->error;
            $this->flash = null;
        }
        $this->refresh($broker);
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'format' => Format::class,
        ])->layoutData([
            'heading' => 'Overview',
            'sub' => ($this->metrics['hostname'] ?? 'host') . ' · up ' . Format::duration((float) ($this->metrics['uptime_seconds'] ?? 0)),
        ]);
    }
}
