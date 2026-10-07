<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <pre class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 font-mono text-xs text-bad">{{ $error }}</pre>
    @endif

    <form wire:submit="saveTelegram" class="panel grid gap-4 p-5 md:grid-cols-2">
        <h2 class="md:col-span-2 text-sm font-medium">Telegram</h2>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Bot token {{ $token_set ? '(saved, encrypted)' : '' }}
            <input class="field mt-1" type="password" wire:model="bot_token" autocomplete="off" placeholder="{{ $token_set ? '••••••••' : '123456:ABC…' }}">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Chat ID
            <input class="field mt-1" wire:model="chat_id" placeholder="-100…">
        </label>
        @error('bot_token') <p class="text-sm text-bad md:col-span-2">{{ $message }}</p> @enderror
        <div class="flex gap-2 md:col-span-2">
            <button class="btn-primary" type="submit">Save</button>
            <button class="btn-ghost" type="button" wire:click="testTelegram">Send test</button>
            <button class="btn-ghost" type="button" wire:click="installScheduler">Install scheduler cron</button>
        </div>
    </form>

    <form wire:submit="saveRules" class="panel grid gap-4 p-5 md:grid-cols-3">
        <h2 class="md:col-span-3 text-sm font-medium">Rules</h2>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="service_down"> Controlled service down</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="observed_down"> Observed service down</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="reboot_required"> Reboot required</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="tls"> TLS expiry</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="backup_stale"> Backup stale/failed</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="cron_failed"> Scheduled job failed</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="app_down"> App down (Octane/PM2/Docker)</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="ssh"> SSH anomalies</label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Disk %
            <input class="field mt-1" type="number" wire:model="disk_percent">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">RAM %
            <input class="field mt-1" type="number" wire:model="ram_percent">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Load
            <input class="field mt-1" type="number" step="0.1" wire:model="load">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">TLS days
            <input class="field mt-1" type="number" wire:model="tls_days">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Backup stale hours
            <input class="field mt-1" type="number" wire:model="backup_stale_hours">
        </label>
        <div class="md:col-span-3"><button class="btn-primary" type="submit">Save rules</button></div>
    </form>

    <section class="panel overflow-hidden">
        <div class="border-b border-white/5 px-5 py-3 text-xs uppercase tracking-wide text-zinc-500">Incidents</div>
        <div class="divide-y divide-white/5">
            @forelse ($incidents as $row)
                <div class="px-5 py-3 font-mono text-xs">
                    <span class="{{ ($row['status'] ?? '') === 'open' ? 'text-bad' : 'text-good' }}">{{ $row['status'] }}</span>
                    <span class="text-zinc-200"> {{ $row['subject'] }}</span>
                    <div class="mt-1 text-zinc-500">{{ $row['message'] }}</div>
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-zinc-500">No incidents yet. The scheduler records them every minute.</p>
            @endforelse
        </div>
    </section>
</div>
