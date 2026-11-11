<div wire:poll.4s="refresh" class="space-y-6">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">
            <pre class="max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs leading-5">{{ $error }}</pre>
        </div>
    @endif
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    @foreach ($status['warnings'] ?? [] as $w)
        <div class="panel border border-warn/40 p-5">
            <div class="text-sm font-medium text-warn">{{ $w['title'] }}</div>
            <p class="mt-1 text-sm text-zinc-400">{{ $w['body'] }}</p>
            <button type="button" wire:click="bindMariadbLocalhost" wire:confirm="This restarts MariaDB and will drop any remote clients. Continue?" class="btn-primary mt-3">
                Bind MariaDB to 127.0.0.1
            </button>
        </div>
    @endforeach

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (array_merge($status['controlled'] ?? [], $status['observed'] ?? []) as $svc)
            <div class="panel p-4">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm text-zinc-200">{{ $svc['unit'] }}</span>
                    <span class="led {{ ($svc['running'] ?? false) ? 'led-on' : 'led-bad' }}"></span>
                </div>
                <div class="mt-2 text-xs uppercase tracking-wide text-zinc-500">{{ $svc['description'] ?? $svc['sub_state'] ?? '' }}</div>
                <div class="mt-1 font-mono text-xs text-zinc-400">{{ $svc['active_state'] ?? '' }} · {{ $svc['sub_state'] ?? '' }}</div>
                @if (!empty($svc['controllable']))
                    <button type="button" class="btn-ghost mt-3 text-xs" wire:click="restartService(@js($svc['unit']))" wire:confirm="Restart {{ $svc['unit'] }}?">Restart</button>
                @else
                    <div class="mt-3 font-mono text-[10px] uppercase tracking-wide text-zinc-600">observed · no control</div>
                @endif
                @if (!empty($svc['journal']))
                    <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap rounded border border-bad/20 bg-ink-950/60 p-2 font-mono text-[10px] leading-4 text-zinc-400">{{ $svc['journal'] }}</pre>
                @endif
            </div>
        @endforeach
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <div class="panel p-5">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Load</div>
            <div class="mt-2 font-mono text-2xl text-zinc-100">{{ number_format($metrics['loadavg']['1'] ?? 0, 2) }}</div>
            <div class="mt-1 font-mono text-xs text-zinc-500">{{ number_format($metrics['loadavg']['5'] ?? 0, 2) }} / {{ number_format($metrics['loadavg']['15'] ?? 0, 2) }}</div>
        </div>
        <div class="panel p-5">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Memory</div>
            <div class="mt-2 font-mono text-2xl text-zinc-100">{{ $format::bytes((int) ($metrics['memory']['used'] ?? 0)) }}</div>
            <div class="mt-1 font-mono text-xs text-zinc-500">of {{ $format::bytes((int) ($metrics['memory']['total'] ?? 0)) }}</div>
        </div>
        <div class="panel p-5">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Stack versions</div>
            <dl class="mt-2 space-y-1 font-mono text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500">{{ $versions['web']['label'] ?? 'Caddy' }}</dt><dd>{{ $versions['web']['version'] ?? $versions['caddy']['version'] ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">MariaDB</dt><dd>{{ $versions['mariadb']['version'] ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">PHP</dt><dd>{{ $versions['php']['version'] ?? '—' }}</dd></div>
            </dl>
        </div>
    </section>

    <section class="panel p-5">
        <div class="mb-3 text-xs uppercase tracking-wide text-zinc-500">Disks</div>
        <div class="space-y-3">
            @forelse ($metrics['disks'] ?? [] as $d)
                <div>
                    <div class="mb-1 flex justify-between font-mono text-xs text-zinc-400">
                        <span>{{ $d['mount'] }}</span>
                        <span>{{ $d['use_percent'] }} · {{ $format::bytes((int) $d['available']) }} free</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-ink-950">
                        <div class="h-full bg-brass-500" style="width: {{ $d['use_percent'] }}"></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-zinc-500">No disk data.</p>
            @endforelse
        </div>
    </section>

    <section class="panel p-5">
        <div class="mb-3 flex items-center justify-between">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Load sparkline (last samples)</div>
            <a href="{{ route('alerts') }}" class="text-xs text-brass-400">{{ $openIncidents }} open alerts</a>
        </div>
        <div class="flex h-16 items-end gap-px">
            @forelse ($samples as $s)
                @php $h = min(100, (float) ($s['load1'] ?? 0) * 20); @endphp
                <div class="flex-1 bg-brass-500/80" style="height: {{ $h }}%" title="{{ $s['load1'] ?? 0 }}"></div>
            @empty
                <p class="text-sm text-zinc-500">No samples yet — enable the scheduler cron on Alerts.</p>
            @endforelse
        </div>
    </section>

    <section class="panel overflow-hidden">
        <div class="flex items-center justify-between border-b border-white/5 px-5 py-3">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Recent audit</div>
            <a href="{{ route('audit') }}" class="text-xs text-brass-400">View all</a>
        </div>
        <div class="divide-y divide-white/5">
            @forelse ($audit as $row)
                <div class="flex items-center justify-between px-5 py-2 font-mono text-xs">
                    <span class="{{ $row['ok'] ? 'text-good' : 'text-bad' }}">{{ $row['action'] }}</span>
                    <span class="text-zinc-500">{{ $row['created_at'] }}</span>
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-zinc-500">No privileged actions yet.</p>
            @endforelse
        </div>
    </section>

    <button type="button" class="btn-ghost" wire:click="restartAll" wire:confirm="Restart the web server, MariaDB, and every PHP-FPM pool?">Restart stack</button>
</div>
