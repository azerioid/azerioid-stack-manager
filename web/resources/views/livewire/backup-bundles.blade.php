<section class="panel space-y-4 p-5">
    <h2 class="text-sm font-medium">Site bundles</h2>
    <p class="text-xs text-zinc-500">
        A bundle is one site: its files (the whole site directory, not only <span class="font-mono">public/</span>), its configuration,
        Docker settings and environment, Supervisor programs, cron jobs, and the databases you list here — each encrypted separately so
        any part can be restored on its own. TLS keys are not included (they are re-issued), nor mail. Restore goes back to the same domain.
    </p>
    @if ($flash)<p class="text-sm text-good">{{ $flash }}</p>@endif
    @if ($error)<p class="text-sm text-bad">{{ $error }}</p>@endif
    <div class="grid gap-3 md:grid-cols-2">
        <label class="text-xs uppercase tracking-wide text-zinc-500">Site
            <select class="field mt-1" wire:model.live="domain">
                <option value="">— choose —</option>
                @foreach ($domains as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Destination
            <select class="field mt-1" wire:model="destination">
                <option value="local">This server</option>
                <option value="spaces">Spaces</option>
            </select>
        </label>
        @if ($domain !== '')
            <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">Databases of this site
                <textarea class="field mt-1 font-mono text-sm" rows="2" wire:model="dbText" placeholder="mariadb:shop"></textarea>
                <span class="mt-1 block normal-case tracking-normal text-zinc-500">One <span class="font-mono">engine:name</span> per line (mariadb, postgresql, mongodb).</span>
            </label>
            <div class="flex gap-2 md:col-span-2">
                <button class="btn-ghost" type="button" wire:click="saveDatabases">Save databases</button>
                <button class="btn-primary" type="button" wire:click="runBundle">Back up this site now</button>
            </div>
        @endif
    </div>

    <div class="overflow-hidden rounded border border-white/5">
        <div class="flex items-center justify-between border-b border-white/5 px-4 py-2 text-xs uppercase tracking-wide text-zinc-500">
            <span>Bundles</span>
            <button type="button" class="normal-case underline" wire:click="refresh">refresh</button>
        </div>
        @forelse ($bundles as $b)
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 font-mono text-xs">
                <span>{{ $b['domain'] }} · {{ $b['created_at'] ?? $b['bundle'] }} · {{ $b['destination'] }}</span>
                <span class="text-zinc-500">{{ implode(', ', array_diff($b['parts'], ['manifest'])) }} · {{ number_format(($b['size'] ?? 0) / 1048576, 1) }} MB</span>
                @if ($b['complete'])
                    <button type="button" class="btn-ghost" wire:click="previewRestore('{{ $b['domain'] }}', '{{ $b['bundle'] }}', '{{ $b['destination'] }}')">Restore…</button>
                @else
                    <span class="text-bad">incomplete</span>
                @endif
            </div>
        @empty
            <p class="px-4 py-3 text-xs text-zinc-500">No bundles yet.</p>
        @endforelse
    </div>

    @if ($preview)
        <div class="rounded border border-warn/40 p-4">
            <p class="text-sm">Restore <span class="font-mono">{{ $preview['domain'] }}</span> from {{ $preview['bundle'] }}</p>
            <div class="mt-2 space-y-1">
                @foreach ($preview['manifest']['parts'] ?? [] as $part)
                    <label class="flex items-center gap-2 text-sm font-mono">
                        <input type="checkbox" value="{{ $part['part'] }}" wire:model="restoreParts"> {{ $part['part'] }}
                        <span class="text-xs text-zinc-500">{{ number_format(($part['plain_bytes'] ?? 0) / 1048576, 1) }} MB</span>
                    </label>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-warn">Files are restored over the live site (the current tree is kept aside as a snapshot); the configuration is validated before it is kept.</p>
            <label class="mt-2 block text-xs uppercase tracking-wide text-zinc-500">Databases that already exist are overwritten only with
                <input class="field mt-1 max-w-xs font-mono" wire:model="dbConfirm" placeholder="OVERWRITE">
            </label>
            <label class="mt-2 block text-xs uppercase tracking-wide text-zinc-500">Type {{ strtoupper($preview['domain']) }} to restore
                <input class="field mt-1 max-w-xs font-mono" wire:model="restoreConfirm">
            </label>
            <div class="mt-3 flex gap-2">
                <button class="btn-danger" type="button" wire:click="applyRestore">Restore selected parts</button>
                <button class="btn-ghost" type="button" wire:click="cancelRestore">Cancel</button>
            </div>
        </div>
    @endif
</section>
