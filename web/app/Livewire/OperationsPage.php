<?php

namespace App\Livewire;

use App\Models\Operation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One place to see every long-running operation (B5 / request #13).
 *
 * Previously component installs lived on the Components page, panel updates on
 * Updates, backups on Backups, and everything else nowhere at all — a docker build
 * left no record whatsoever.
 */
#[Layout('layouts.app')]
#[Title('Operations · AZERIOID Stack Manager')]
class OperationsPage extends Component
{
    public string $filter = 'all';

    public ?int $selected = null;

    public ?string $flash = null;

    public ?string $error = null;

    public function select(int $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
    }

    public function cancel(int $id): void
    {
        $this->flash = null;
        $this->error = null;

        $operation = Operation::query()->find($id);
        if ($operation === null) {
            $this->error = 'Operation not found.';

            return;
        }
        if (! $operation->isCancellable()) {
            // Running operations are not interruptible: killing the broker's child
            // mid-transaction would leave dpkg or rpm half-configured, which is
            // worse than waiting for it to finish.
            $this->error = 'Only a queued operation can be cancelled; this one has already started.';

            return;
        }

        $operation->update([
            'status' => Operation::STATUS_CANCELLED,
            'step' => 'Cancelled before it started',
            'finished_at' => now(),
        ]);
        $this->flash = 'Cancelled ' . $operation->kind . ' on ' . $operation->subject_id . '.';
    }

    public function render()
    {
        $query = Operation::query()->latest();
        if ($this->filter === 'active') {
            $query->whereIn('status', Operation::ACTIVE);
        } elseif ($this->filter === 'failed') {
            $query->where('status', Operation::STATUS_FAILED);
        }

        return view('livewire.operations', [
            'operations' => $query->limit(100)->get(),
            'activeCount' => Operation::query()->whereIn('status', Operation::ACTIVE)->count(),
            'sub' => 'Long-running work: builds, backups, runtime changes, installs',
        ]);
    }
}
