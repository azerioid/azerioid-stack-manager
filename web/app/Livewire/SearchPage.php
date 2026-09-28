<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Elasticsearch health, indices and the elastic user's password (B7, ADR A54).
 */
#[Layout('layouts.app')]
#[Title('Search · AZERIOID Stack Manager')]
class SearchPage extends Component
{
    public ?array $status = null;

    public array $indices = [];

    public bool $installed = true;

    public ?string $password = null;

    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $this->refresh($broker);
    }

    public function refresh(BrokerClient $broker): void
    {
        $this->error = null;
        $status = $broker->call('search.status', [], [], 60, false);
        if (! $status->ok) {
            $this->installed = ! str_contains((string) $status->error, 'not installed');
            $this->error = $this->installed ? (string) $status->error : null;

            return;
        }
        $this->installed = true;
        $this->status = (array) $status->data;
        $indices = $broker->call('search.indices', [], [], 60, false);
        $this->indices = $indices->ok ? (array) ($indices->data['indices'] ?? []) : [];
    }

    public function resetPassword(BrokerClient $broker): void
    {
        $res = $broker->call('search.password.reset', [], [], 180);
        if (! $res->ok) {
            $this->error = (string) $res->error;

            return;
        }
        // Shown once; not kept in the component after the page is left.
        $this->password = (string) ($res->data['password'] ?? '');
    }

    public function render()
    {
        return view('livewire.search');
    }
}
