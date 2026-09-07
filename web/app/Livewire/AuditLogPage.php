<?php

namespace App\Livewire;

use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Audit · AZERIOID Stack Manager')]
class AuditLogPage extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.audit', [
            'logs' => AuditLog::query()->with('user')->latest()->paginate(30),
        ])->layoutData([
            'heading' => 'Audit log',
            'sub' => 'Every privileged action, from both the web tier and the broker',
        ]);
    }
}
