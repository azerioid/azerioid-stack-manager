<section class="panel space-y-4 p-5">
    <h2 class="text-sm font-medium">Schedules per target</h2>
    <p class="text-xs text-zinc-500">
        Each target runs on its own schedule and keeps its archives for its own number of days (or the default below).
        The newest copy of every target is always kept, however old.
    </p>
    @if ($flash)<p class="text-sm text-good">{{ $flash }}</p>@endif
    @if ($error)<p class="text-sm text-bad">{{ $error }}</p>@endif
    <div class="overflow-hidden rounded border border-white/5">
        @forelse ($schedules as $s)
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 font-mono text-xs">
                <span class="{{ $s['enabled'] ? '' : 'text-zinc-500 line-through' }}">
                    {{ $s['target_type'] }} {{ $s['target'] }}{{ $s['engine'] ? ' ('.$s['engine'].')' : '' }} → {{ $s['destination'] }},
                    {{ $s['cadence'] }}{{ $s['cadence'] === 'weekly' ? ' day '.$s['weekday'] : '' }} at {{ $s['hour'] }}:00,
                    keep {{ $s['retention_days'] ?? $defaultRetentionDays }} days
                </span>
                <span class="text-zinc-500">{{ $s['last_status'] ?? 'not run yet' }}{{ $s['last_error'] ? ': '.\Illuminate\Support\Str::limit($s['last_error'], 80) : '' }}</span>
                <span class="flex gap-2">
                    <button type="button" class="underline" wire:click="toggle({{ $s['id'] }})">{{ $s['enabled'] ? 'pause' : 'resume' }}</button>
                    <button type="button" class="text-bad underline" wire:click="delete({{ $s['id'] }})" wire:confirm="Remove this schedule? Archives it made are kept.">remove</button>
                </span>
            </div>
        @empty
            <p class="px-4 py-3 text-xs text-zinc-500">No per-target schedules.</p>
        @endforelse
    </div>
    <div class="grid gap-3 md:grid-cols-4">
        <label class="text-xs uppercase tracking-wide text-zinc-500">What
            <select class="field mt-1" wire:model.live="targetType">
                <option value="vhost">Site bundle</option>
                <option value="db">Database</option>
                <option value="files">Site files</option>
                <option value="caddy">Web server config</option>
            </select>
        </label>
        @if ($targetType !== 'caddy')
            <label class="text-xs uppercase tracking-wide text-zinc-500">{{ $targetType === 'db' ? 'Database' : ($targetType === 'files' ? 'Site directory' : 'Domain') }}
                <input class="field mt-1 font-mono text-sm" wire:model="target">
            </label>
        @endif
        @if ($targetType === 'db')
            <label class="text-xs uppercase tracking-wide text-zinc-500">Engine
                <select class="field mt-1" wire:model="engine">
                    <option value="">default</option><option value="mariadb">MariaDB</option><option value="postgresql">PostgreSQL</option><option value="mongodb">MongoDB</option>
                </select>
            </label>
        @endif
        <label class="text-xs uppercase tracking-wide text-zinc-500">Destination
            <select class="field mt-1" wire:model="destination"><option value="local">This server</option><option value="spaces">Spaces</option></select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Cadence
            <select class="field mt-1" wire:model.live="cadence"><option value="daily">Daily</option><option value="weekly">Weekly</option></select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Hour (0–23)
            <input class="field mt-1" type="number" min="0" max="23" wire:model="hour">
        </label>
        @if ($cadence === 'weekly')
            <label class="text-xs uppercase tracking-wide text-zinc-500">Weekday (0 = Sunday)
                <input class="field mt-1" type="number" min="0" max="6" wire:model="weekday">
            </label>
        @endif
        <label class="text-xs uppercase tracking-wide text-zinc-500">Keep days (empty = default)
            <input class="field mt-1" type="number" min="1" max="3650" wire:model="retentionDays">
        </label>
        <div class="flex items-end"><button class="btn-primary" type="button" wire:click="add">Add schedule</button></div>
    </div>
    <div class="flex items-end gap-2">
        <label class="text-xs uppercase tracking-wide text-zinc-500">Default retention (days)
            <input class="field mt-1 max-w-[8rem]" type="number" min="1" max="3650" wire:model="defaultRetentionDays">
        </label>
        <button class="btn-ghost" type="button" wire:click="saveRetention">Save</button>
    </div>
</section>
