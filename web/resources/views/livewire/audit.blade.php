<div class="space-y-4">
    <div class="panel flex flex-wrap items-end gap-3 p-4">
        <label class="text-xs uppercase tracking-wide text-zinc-500">Action
            <input class="field mt-1 font-mono text-sm" wire:model.live.debounce.300ms="action" placeholder="vhost.add">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Result
            <select class="field mt-1" wire:model.live="result">
                <option value="">Any</option>
                <option value="ok">Succeeded</option>
                <option value="failed">Failed</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">User
            <select class="field mt-1" wire:model.live="user">
                <option value="">Anyone</option>
                @foreach ($users as $u)
                    <option value="{{ $u->id }}">{{ $u->email }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">From
            <input type="date" class="field mt-1" wire:model.live="from">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">To
            <input type="date" class="field mt-1" wire:model.live="to">
        </label>
        @if ($hasFilters)
            <button class="btn-ghost" type="button" wire:click="clearFilters">Clear</button>
        @endif
    </div>

    <div class="panel overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead class="font-mono text-[11px] uppercase tracking-wide text-zinc-500">
            <tr>
                <th class="px-4 py-3">When</th>
                <th class="px-4 py-3">Action</th>
                <th class="px-4 py-3">OK</th>
                <th class="px-4 py-3">User</th>
                <th class="px-4 py-3">IP</th>
                <th class="px-4 py-3">Error</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-white/5">
            @forelse ($logs as $log)
                <tr>
                    <td class="px-4 py-2 font-mono text-xs text-zinc-400">{{ $log->created_at }}</td>
                    <td class="px-4 py-2 font-mono">{{ $log->action }}</td>
                    <td class="px-4 py-2 {{ $log->ok ? 'text-good' : 'text-bad' }}">{{ $log->ok ? 'yes' : 'no' }}</td>
                    <td class="px-4 py-2 text-xs">{{ $log->user?->email ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ $log->ip }}</td>
                    <td class="px-4 py-2 text-xs text-zinc-500">{{ $log->error }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">{{ $hasFilters ? 'No audit entries match these filters.' : 'Audit log is empty.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="p-4">{{ $logs->links() }}</div>
    </div>
</div>
