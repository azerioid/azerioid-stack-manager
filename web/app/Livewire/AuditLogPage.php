<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A81: the audit / activity feed. Every privileged action from both tiers is already
 * captured in audit_logs (web actions and broker call results); this adds a
 * who / what / when / result filter over it. The whole panel is admin-only (one
 * root-equivalent operator, A39), so the auth group already gates this page.
 */
#[Layout('layouts.app')]
#[Title('Audit · AZERIOID Stack Manager')]
class AuditLogPage extends Component
{
    use WithPagination;

    /** Filters are URL-bound so a filtered view can be shared or bookmarked. */
    #[Url(as: 'q')]
    public string $action = '';

    #[Url]
    public string $result = ''; // '' = any, 'ok', 'failed'

    #[Url]
    public string $user = ''; // user_id, or '' = any

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Any filter change returns to the first page. */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('action', 'result', 'user', 'from', 'to');
        $this->resetPage();
    }

    public function render()
    {
        $logs = AuditLog::query()
            ->with('user')
            ->when($this->action !== '', fn ($q) => $q->where('action', 'like', '%'.$this->action.'%'))
            ->when($this->result === 'ok', fn ($q) => $q->where('ok', true))
            ->when($this->result === 'failed', fn ($q) => $q->where('ok', false))
            ->when($this->user !== '', fn ($q) => $q->where('user_id', $this->user))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest()
            ->paginate(30);

        return view('livewire.audit', [
            'logs' => $logs,
            'users' => User::orderBy('email')->get(['id', 'email']),
            'hasFilters' => $this->action !== '' || $this->result !== '' || $this->user !== '' || $this->from !== '' || $this->to !== '',
        ])->layoutData([
            'heading' => 'Audit log',
            'sub' => 'Every privileged action, from both the web tier and the broker',
        ]);
    }
}
