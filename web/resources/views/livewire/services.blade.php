<div class="space-y-6" wire:poll.5s="reload">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">
            <pre class="max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs leading-5">{{ $error }}</pre>
        </div>
    @endif
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($controlled as $svc)
            <div class="panel p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="font-mono text-sm">{{ $svc['unit'] }}</div>
                        <div class="text-xs text-zinc-500">{{ $svc['description'] }} · {{ $svc['active_state'] ?? '' }} · {{ $svc['sub_state'] ?? '' }} · pid {{ $svc['main_pid'] ?? 0 }}</div>
                    </div>
                    <span class="led {{ ($svc['running'] ?? false) ? 'led-on' : 'led-bad' }}"></span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button class="btn-ghost text-xs" wire:click="ask('start', '{{ $svc['unit'] }}')">Start</button>
                    <button class="btn-ghost text-xs" wire:click="ask('stop', '{{ $svc['unit'] }}')">Stop</button>
                    <button class="btn-ghost text-xs" wire:click="ask('restart', '{{ $svc['unit'] }}')">Restart</button>
                    <button class="btn-ghost text-xs" wire:click="showRaw('{{ $svc['unit'] }}')">status + journal</button>
                </div>
                @if (!empty($svc['journal']))
                    <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap rounded border border-bad/20 bg-ink-950/60 p-2 font-mono text-[10px] leading-4 text-zinc-400">{{ $svc['journal'] }}</pre>
                @endif
            </div>
        @endforeach
    </div>

    @if ($observed !== [])
    <div>
        <div class="mb-2 text-xs uppercase tracking-wide text-zinc-500">Observed (no control)</div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($observed as $svc)
                <div class="panel p-4">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-sm">{{ $svc['unit'] }}</span>
                        <span class="led {{ ($svc['running'] ?? false) ? 'led-on' : 'led-off' }}"></span>
                    </div>
                    <div class="mt-1 font-mono text-xs text-zinc-400">{{ $svc['active_state'] ?? '' }} · {{ $svc['sub_state'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    @if ($pending)
        <div class="panel border border-warn/40 p-5">
            <p class="text-sm">Confirm <span class="font-mono text-warn">{{ $pendingAction }}</span> on <span class="font-mono">{{ $pending }}</span>.</p>
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="run">Confirm</button>
                <button class="btn-ghost" wire:click="$set('pending', null)">Cancel</button>
            </div>
        </div>
    @endif

    @if ($raw !== null)
        <pre class="panel overflow-x-auto p-4 font-mono text-xs text-zinc-300">{{ $raw }}</pre>
    @endif
</div>
