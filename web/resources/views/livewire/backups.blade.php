<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <pre class="max-h-48 overflow-auto rounded-md border border-bad/40 bg-bad/10 px-4 py-3 font-mono text-xs text-bad">{{ $error }}</pre>
    @endif

    <form wire:submit="saveCredentials" class="panel grid gap-4 p-5 md:grid-cols-2">
        <h2 class="md:col-span-2 text-sm font-medium">DigitalOcean Spaces (encrypted at rest)</h2>
        <p class="md:col-span-2 text-sm text-zinc-400">Optional for cloud offsite copies. Local backups only need the archive passphrase below.</p>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Endpoint
            <input class="field mt-1" wire:model="endpoint">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Region
            <input class="field mt-1" wire:model="region">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Bucket
            <input class="field mt-1" wire:model="bucket">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Access key {{ $keys_set ? '(saved)' : '' }}
            <input class="field mt-1" type="password" wire:model="access_key" autocomplete="off">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Secret
            <input class="field mt-1" type="password" wire:model="secret" autocomplete="off">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">Archive passphrase {{ $pass_set ? '(saved)' : '' }}
            <input class="field mt-1" type="password" wire:model="passphrase" autocomplete="off" placeholder="min 16 chars — encrypts local and Spaces archives">
        </label>
        @error('passphrase') <p class="text-sm text-bad md:col-span-2">{{ $message }}</p> @enderror
        <div class="flex flex-wrap gap-2 md:col-span-2">
            <button class="btn-primary" type="submit">Save Spaces + passphrase</button>
            <button class="btn-ghost" type="button" wire:click="savePassphraseOnly">Save passphrase only</button>
            <button class="btn-ghost" type="button" wire:click="testSpaces">Test Spaces</button>
        </div>
    </form>

    <form wire:submit="saveSchedule" class="panel grid gap-4 p-5 md:grid-cols-4">
        <h2 class="md:col-span-4 text-sm font-medium">Schedule (Spaces)</h2>
        <label class="flex items-center gap-2 text-sm md:col-span-4"><input type="checkbox" wire:model="schedule_enabled"> Enable scheduled Spaces backups</label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Hour (UTC)
            <input class="field mt-1" type="number" min="0" max="23" wire:model="schedule_hour">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Cadence
            <select class="field mt-1" wire:model="cadence"><option value="daily">daily</option><option value="weekly">weekly</option></select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Keep last N per kind
            <input class="field mt-1" type="number" min="1" max="365" wire:model="keep">
        </label>
        <div><button class="btn-primary" type="submit">Save schedule</button></div>
    </form>

    <div class="panel grid gap-4 p-5 md:grid-cols-3">
        <div>
            <label class="text-xs uppercase tracking-wide text-zinc-500">Database
                <input class="field mt-1" wire:model="db" placeholder="all or name">
            </label>
            <div class="mt-3 flex flex-wrap gap-2">
                <button class="btn-primary" type="button" wire:click="runDbLocal">Backup DB now (local)</button>
                <button class="btn-ghost" type="button" wire:click="runDb">Backup DB to Spaces</button>
            </div>
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-zinc-500">Site (under /data/www)
                <input class="field mt-1" wire:model="site" placeholder="example.com">
            </label>
            <div class="mt-3 flex flex-wrap gap-2">
                <button class="btn-primary" type="button" wire:click="runFilesLocal">Backup files (local)</button>
                <button class="btn-ghost" type="button" wire:click="runFiles">Backup files to Spaces</button>
            </div>
        </div>
        <div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="include_fpm"> Include PHP-FPM pools</label>
            <button class="btn-ghost mt-3" type="button" wire:click="runCaddy">Backup web config to Spaces</button>
        </div>
        <p class="md:col-span-3 text-sm text-zinc-500">Local archives are AES-encrypted with the same passphrase, stored under <span class="font-mono">/var/lib/azerioid-panel/backups/</span>, and pruned to the Keep last N setting after each run.</p>
    </div>

    <section class="panel overflow-hidden">
        <div class="border-b border-white/5 px-5 py-3 text-xs uppercase tracking-wide text-zinc-500">Local backups</div>
        @forelse ($localObjects as $o)
            <div class="flex justify-between px-5 py-2 font-mono text-xs">
                <span>{{ $o['key'] }}</span>
                <span class="text-zinc-500">{{ $o['size'] }} · {{ $o['kind'] }}</span>
            </div>
        @empty
            <p class="px-5 py-4 text-sm text-zinc-500">No local backups yet.</p>
        @endforelse
    </section>

    <section class="panel overflow-hidden">
        <div class="border-b border-white/5 px-5 py-3 text-xs uppercase tracking-wide text-zinc-500">Objects in Spaces</div>
        @forelse ($objects as $o)
            <div class="flex justify-between px-5 py-2 font-mono text-xs">
                <span>{{ $o['key'] }}</span>
                <span class="text-zinc-500">{{ $o['size'] }} · {{ $o['kind'] }}</span>
            </div>
        @empty
            <p class="px-5 py-4 text-sm text-zinc-500">No Spaces objects listed (credentials optional for local-only).</p>
        @endforelse
    </section>

    <div class="panel grid gap-4 p-5 md:grid-cols-2">
        <h2 class="md:col-span-2 text-sm font-medium">Restore</h2>
        <p class="md:col-span-2 text-sm text-zinc-400">DB restore defaults to a <strong>new</strong> database name. File restore stages first, then moves. Restoring over a read-only (reverse-proxy) vhost requires force plus typing the domain in uppercase. Overwriting an existing database requires typing <span class="font-mono">OVERWRITE</span>.</p>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Source
            <select class="field mt-1" wire:model="restore_destination">
                <option value="local">Local disk</option>
                <option value="spaces">Spaces</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Object key / path
            <input class="field mt-1" wire:model="restore_key" placeholder="/var/lib/azerioid-panel/backups/... or azerioid/db/...">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">New DB name
            <input class="field mt-1" wire:model="restore_target" placeholder="site_restore_1">
        </label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="restore_overwrite"> Overwrite existing DB</label>
        <div><button class="btn-ghost" type="button" wire:click="restoreDb">Restore DB</button></div>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Site name
            <input class="field mt-1" wire:model="restore_site">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Confirm (OVERWRITE / SITE.EXAMPLE.COM)
            <input class="field mt-1" wire:model="restore_confirm">
        </label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="restore_force"> Force overwrite of a read-only vhost</label>
        <div class="flex gap-2">
            <button class="btn-ghost" type="button" wire:click="previewFiles">Stage / preview</button>
            <button class="btn-danger" type="button" wire:click="applyFiles" wire:confirm="Apply staged files onto the live tree?">Apply files</button>
        </div>
        @if ($preview)
            <pre class="md:col-span-2 max-h-48 overflow-auto font-mono text-[10px] text-zinc-400">{{ implode("\n", $preview['preview'] ?? []) }}</pre>
        @endif
    </div>

    <section class="panel overflow-hidden">
        <div class="border-b border-white/5 px-5 py-3 text-xs uppercase tracking-wide text-zinc-500">History</div>
        @foreach ($history as $h)
            <div class="flex justify-between px-5 py-2 font-mono text-xs">
                <span class="{{ ($h['status'] ?? '') === 'ok' ? 'text-good' : 'text-bad' }}">{{ $h['kind'] }} {{ $h['name'] }}</span>
                <span class="text-zinc-500">{{ $h['status'] }} · {{ $h['size'] }}</span>
            </div>
        @endforeach
    </section>
</div>
