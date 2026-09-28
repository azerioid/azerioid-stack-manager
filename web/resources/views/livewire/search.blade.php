<div class="space-y-4">
    <h1 class="text-lg">Search (Elasticsearch)</h1>
    @if (! $installed)
        <section class="panel p-5 text-sm text-zinc-400">
            Elasticsearch is not installed. Install it from <a href="/components" class="underline">Components</a> — it needs at least
            2 GB of physical RAM (swap does not count) and runs on <span class="font-mono">127.0.0.1:9200</span> only, with a password.
        </section>
    @else
        @if ($error)<div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>@endif
        @if ($status)
            <section class="panel grid gap-3 p-5 text-sm md:grid-cols-4">
                <div><span class="block text-xs uppercase text-zinc-500">Cluster</span>
                    <span class="{{ $status['status'] === 'green' ? 'text-good' : ($status['status'] === 'yellow' ? 'text-warn' : 'text-bad') }}">{{ $status['status'] }}</span>
                    <span class="text-zinc-500">· {{ $status['version'] }}</span></div>
                <div><span class="block text-xs uppercase text-zinc-500">Heap</span>{{ $status['heap_used_percent'] ?? '?' }}% of {{ $status['heap_max_mb'] ?? '?' }} MB</div>
                <div><span class="block text-xs uppercase text-zinc-500">Shards</span>{{ $status['active_shards'] }} active, {{ $status['unassigned_shards'] }} unassigned</div>
                <div><span class="block text-xs uppercase text-zinc-500">Connect</span><span class="font-mono">{{ $status['url'] }}</span> as <span class="font-mono">elastic</span></div>
                <p class="text-xs text-zinc-500 md:col-span-4">Single node: replicas cannot be placed, so an index with replicas reports yellow. That is expected here.</p>
            </section>
        @endif
        <section class="panel overflow-hidden">
            <div class="flex items-center justify-between border-b border-white/5 px-5 py-3 text-xs uppercase tracking-wide text-zinc-500">
                <span>Indices</span><button type="button" class="normal-case underline" wire:click="refresh">refresh</button>
            </div>
            @forelse ($indices as $i)
                <div class="flex justify-between px-5 py-2 font-mono text-xs">
                    <span>{{ $i['name'] }}</span>
                    <span class="text-zinc-500">{{ $i['health'] }} · {{ number_format($i['docs']) }} docs · {{ number_format(((int) $i['size']) / 1048576, 1) }} MB</span>
                </div>
            @empty
                <p class="px-5 py-3 text-xs text-zinc-500">No indices.</p>
            @endforelse
        </section>
        <section class="panel space-y-2 p-5">
            <h2 class="text-sm font-medium">Password for the elastic user</h2>
            @if ($password)
                <p class="text-xs text-warn">Shown once. Update your applications, then leave this page.</p>
                <pre class="rounded bg-black/30 p-3 font-mono text-sm">{{ $password }}</pre>
            @else
                <button class="btn-ghost" type="button" wire:click="resetPassword" wire:confirm="Set a new password? Applications using the old one stop connecting.">Set a new password</button>
            @endif
        </section>
    @endif
</div>
