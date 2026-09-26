{{-- B5 / request #13 — one place for every long-running operation. Polls only
     while something is active, so an idle panel makes no requests. --}}
<div class="space-y-6" @if ($activeCount > 0) wire:poll.3s @endif>
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif

    <div class="panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-medium">Operations</h2>
                <p class="mt-1 text-xs text-zinc-500">
                    @if ($activeCount > 0)
                        {{ $activeCount }} in progress — this page refreshes itself while work is running.
                    @else
                        Nothing running.
                    @endif
                </p>
            </div>
            <div class="flex gap-1 text-xs">
                @foreach (['all' => 'All', 'active' => 'Active', 'failed' => 'Failed'] as $key => $label)
                    <button type="button"
                            class="rounded px-2 py-1 {{ $filter === $key ? 'bg-brass-400/20 text-brass-400' : 'text-zinc-400 hover:bg-ink-600' }}"
                            wire:click="$set('filter', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if ($operations->isEmpty())
            <p class="mt-4 text-sm text-zinc-500">
                No operations recorded yet. Builds, backups, runtime changes and installs appear here once started.
            </p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-zinc-500">
                        <tr>
                            <th class="px-2 py-2 font-medium">What</th>
                            <th class="px-2 py-2 font-medium">Subject</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 font-medium">Step</th>
                            <th class="px-2 py-2 font-medium">Started</th>
                            <th class="px-2 py-2 font-medium">Took</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($operations as $op)
                            <tr wire:key="op-{{ $op->id }}" class="align-top">
                                <td class="px-2 py-2 font-mono text-zinc-200">{{ $op->kind }}</td>
                                <td class="px-2 py-2 font-mono text-zinc-400">{{ $op->subject_id }}</td>
                                <td class="px-2 py-2">
                                    @php($tone = match ($op->status) {
                                        'completed' => 'text-good',
                                        'failed' => 'text-bad',
                                        'cancelled' => 'text-zinc-500',
                                        default => 'text-brass-400',
                                    })
                                    <span class="{{ $tone }}">{{ $op->status }}</span>
                                </td>
                                <td class="px-2 py-2 text-zinc-400">{{ $op->step ?? '—' }}</td>
                                <td class="px-2 py-2 text-zinc-500">{{ $op->started_at?->diffForHumans() ?? '—' }}</td>
                                <td class="px-2 py-2 text-zinc-500">
                                    @if ($op->durationSeconds() !== null){{ $op->durationSeconds() }}s @else — @endif
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <button type="button" class="text-brass-400 hover:underline" wire:click="select({{ $op->id }})">
                                        {{ $selected === $op->id ? 'Hide' : 'Details' }}
                                    </button>
                                    @if ($op->isCancellable())
                                        <button type="button" class="ml-2 text-bad hover:underline"
                                                wire:click="cancel({{ $op->id }})"
                                                wire:confirm="Cancel this queued operation?">Cancel</button>
                                    @endif
                                </td>
                            </tr>
                            @if ($selected === $op->id)
                                <tr wire:key="op-detail-{{ $op->id }}">
                                    <td colspan="7" class="bg-ink-900/60 px-4 py-3">
                                        <dl class="grid gap-x-6 gap-y-1 text-[11px] sm:grid-cols-2">
                                            <div><dt class="inline text-zinc-500">action:</dt> <dd class="inline font-mono text-zinc-300">{{ $op->broker_action }}</dd></div>
                                            <div><dt class="inline text-zinc-500">subject:</dt> <dd class="inline font-mono text-zinc-300">{{ $op->subject_type }}/{{ $op->subject_id }}</dd></div>
                                            @if (! empty($op->options))
                                                <div class="sm:col-span-2">
                                                    <dt class="inline text-zinc-500">options:</dt>
                                                    <dd class="inline font-mono text-zinc-300">{{ json_encode($op->options) }}</dd>
                                                </div>
                                            @endif
                                        </dl>
                                        @if ($op->error)
                                            <pre class="mt-3 max-h-40 overflow-auto rounded border border-bad/30 bg-bad/5 p-2 font-mono text-[11px] text-bad">{{ $op->error }}</pre>
                                        @endif
                                        @if ($op->log)
                                            <pre class="mt-3 max-h-64 overflow-auto font-mono text-[11px] text-zinc-300">{{ $op->log }}</pre>
                                        @endif
                                        @if (! $op->error && ! $op->log)
                                            <p class="mt-3 text-[11px] text-zinc-500">No output recorded.</p>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="text-xs text-zinc-500">
        A queued operation can be cancelled. One already running cannot: interrupting it would
        leave the package manager or the container build half-finished, which is worse than
        letting it end. Records are pruned after 180 days by <code>azerioid:maintenance</code>.
    </p>
</div>
