<div @if ($live) wire:poll.2s="load" @endif class="space-y-4">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif
    <form wire:submit="load" class="flex flex-wrap items-end gap-3">
        <label class="text-xs uppercase tracking-wide text-zinc-500">Log
            <select class="field mt-1" wire:model="key" @disabled($vhostDomain !== '')>
                <option value="caddy">Web server access</option>
                <option value="mariadb">MariaDB error</option>
                <option value="php-fpm">PHP-FPM error</option>
                <option value="php-slow">PHP slowlog</option>
                <option value="panel-audit">Broker audit</option>
                <option value="auth">auth.log</option>
            </select>
        </label>
        @if ($domains !== [])
            <label class="text-xs uppercase tracking-wide text-zinc-500">Site access log
                <select class="field mt-1" wire:model="vhostDomain">
                    <option value="">— system logs —</option>
                    @foreach ($domains as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="text-xs uppercase tracking-wide text-zinc-500">Lines
            <input type="number" min="1" max="500" class="field mt-1 w-24" wire:model="lines">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Search (fixed string)
            <input class="field mt-1 w-56" wire:model="needle" placeholder="optional">
        </label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="live"> Live</label>
        <button class="btn-primary" type="submit">{{ $needle !== '' ? 'Search' : 'Tail' }}</button>
    </form>
    <div class="text-xs text-zinc-500">{{ $path }} @if ($missing) · missing on this host @endif</div>
    <pre class="panel max-h-[70vh] overflow-auto p-4 font-mono text-xs leading-5 text-zinc-300">@forelse ($entries as $line)
{{ $line }}
@empty
No lines.
@endforelse</pre>
</div>
