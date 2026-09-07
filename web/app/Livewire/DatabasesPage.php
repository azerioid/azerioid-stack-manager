<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use App\Support\Format;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Databases · AZERIOID Stack Manager')]
class DatabasesPage extends Component
{
    public array $databases = [];

    public array $engines = [];

    public ?string $activeEngine = null;

    public string $selectedEngine = '';

    public string $name = '';

    public string $user = '';

    public ?string $revealedPassword = null;

    public ?string $error = null;

    public ?string $flash = null;

    public ?string $confirmDelete = null;

    public string $confirmTyped = '';

    public ?string $resetUser = null;

    public bool $showForm = false;

    public function mount(BrokerClient $broker): void
    {
        $this->loadEngines($broker);
        $this->reload($broker);
    }

    public function updatedSelectedEngine(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function create(BrokerClient $broker): void
    {
        $this->error = null;
        $this->revealedPassword = null;
        if ($this->selectedEngine === '') {
            $this->error = 'Install MariaDB or PostgreSQL from Components before creating databases.';

            return;
        }
        try {
            $name = Validator::dbName($this->name);
            $user = Validator::userName($this->user !== '' ? $this->user : $this->name);
            $password = Format::password();
            Validator::password($password);
            $res = $broker->call('db.add', [$name, $user], [
                'password' => $password,
                'engine' => $this->selectedEngine,
            ]);
            if (! $res->ok) {
                $this->error = (string) $res->error;

                return;
            }
            $this->revealedPassword = $password;
            $this->flash = "Created database {$name}. Copy the password now — it will not be shown again.";
            $this->reset('name', 'user', 'showForm');
            $this->reload($broker);
        } catch (\Throwable $e) {
            $this->revealedPassword = null;
            $this->error = $e->getMessage();
        }
    }

    public function delete(BrokerClient $broker): void
    {
        if ($this->confirmDelete === null || $this->confirmTyped !== $this->confirmDelete) {
            $this->error = 'Type the database name to confirm deletion.';

            return;
        }
        $res = $broker->call('db.del', [Validator::dbName($this->confirmDelete)], [
            'engine' => $this->selectedEngine,
        ]);
        $this->error = $res->ok ? null : $res->error;
        $this->flash = $res->ok ? 'Database dropped.' : null;
        $this->revealedPassword = null;
        $this->confirmDelete = null;
        $this->confirmTyped = '';
        $this->reload($broker);
    }

    public function startReset(string $user): void
    {
        $this->resetUser = $user;
        $this->revealedPassword = null;
    }

    public function confirmReset(BrokerClient $broker): void
    {
        if ($this->resetUser === null) {
            return;
        }
        $password = Format::password();
        Validator::password($password);
        $res = $broker->call('db.resetpw', [Validator::userName($this->resetUser)], [
            'password' => $password,
            'engine' => $this->selectedEngine,
        ]);
        $this->error = $res->ok ? null : $res->error;
        $this->flash = $res->ok ? 'Password reset. Copy it now.' : null;
        $this->revealedPassword = $res->ok ? $password : null;
        $this->resetUser = null;
        $this->reload($broker);
    }

    private function loadEngines(BrokerClient $broker): void
    {
        try {
            $data = $broker->call('db.engine')->dataOrFail();
            $this->engines = is_array($data['engines'] ?? null) ? $data['engines'] : [];
            $this->activeEngine = is_string($data['active'] ?? null) ? $data['active'] : null;
            $configured = array_values(array_filter(
                $this->engines,
                fn (array $e) => ! empty($e['configured'])
            ));
            $this->selectedEngine = $this->activeEngine
                ?? ($configured[0]['id'] ?? '');
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function reload(BrokerClient $broker): void
    {
        if ($this->selectedEngine === '') {
            $this->databases = [];

            return;
        }
        try {
            $data = $broker->call('db.list', [], ['engine' => $this->selectedEngine])->dataOrFail();
            $this->databases = $data['databases'] ?? [];
            $this->error = null;
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $engineLabel = collect($this->engines)->firstWhere('id', $this->selectedEngine)['label'] ?? 'Database';

        return view('livewire.databases', [
            'format' => Format::class,
            'engineLabel' => $engineLabel,
            'configuredEngines' => array_values(array_filter($this->engines, fn (array $e) => ! empty($e['configured']))),
        ])->layoutData([
            'heading' => 'Databases',
            'sub' => $engineLabel.' · passwords are one-time reveal',
        ]);
    }
}
