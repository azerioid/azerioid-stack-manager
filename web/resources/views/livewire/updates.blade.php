<div
    class="space-y-6"
    @if ($panelOperation && in_array($panelOperation['status'] ?? '', ['queued', 'running'], true))
        wire:poll.2s="pollPanelOperation"
    @endif
>
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <pre class="max-h-64 overflow-auto rounded-md border border-bad/40 bg-bad/10 px-4 py-3 font-mono text-xs text-bad">{{ $error }}</pre>
    @endif

    {{-- Panel self-update (distinct from OS packages) --}}
    <section class="panel space-y-4 p-5">
        <div>
            <h2 class="text-sm font-medium text-zinc-100">Panel self-update</h2>
            <p class="mt-1 text-sm text-zinc-400">
                Updates the AZERIOID Stack Manager itself (git channel <span class="font-mono">origin/main</span>),
                not host OS packages. Runs as a background job with rollback to the previous commit on failure.
                No stable/tag channel yet.
            </p>
        </div>

        @if (!empty($panelUpdate['error']))
            <pre class="max-h-40 overflow-auto rounded-md border border-bad/40 bg-bad/10 px-3 py-2 font-mono text-xs text-bad">{{ $panelUpdate['error'] }}</pre>
        @else
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Deployed</div>
                    <div class="mt-1 font-mono text-sm">
                        {{ $panelUpdate['deployed_commit_short'] ?? '—' }}
                        @if (!empty($panelUpdate['version']))
                            <span class="text-zinc-500">· v{{ $panelUpdate['version'] }}</span>
                        @endif
                    </div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">origin/main</div>
                    <div class="mt-1 font-mono text-sm">{{ $panelUpdate['remote_commit_short'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Status</div>
                    <div class="mt-1 font-mono text-sm {{ !empty($panelUpdate['update_available']) ? 'text-warn' : 'text-good' }}">
                        @if (!empty($panelUpdate['dirty']))
                            dirty source — refused
                        @elseif (!empty($panelUpdate['up_to_date']))
                            up to date
                        @elseif (!empty($panelUpdate['update_available']))
                            update available
                        @else
                            —
                        @endif
                    </div>
                </div>
            </div>

            @if (!empty($panelUpdate['log_summary']))
                <div>
                    <div class="mb-1 text-xs uppercase tracking-wide text-zinc-500">Would change</div>
                    <pre class="max-h-40 overflow-auto rounded-md border border-white/5 bg-black/20 px-3 py-2 font-mono text-[11px] text-zinc-300">{{ implode("\n", $panelUpdate['log_summary']) }}</pre>
                </div>
            @endif
        @endif

        @if ($panelOperation && in_array($panelOperation['status'] ?? '', ['queued', 'running'], true))
            <div class="rounded-md border border-white/10 bg-black/20 p-3">
                <div class="text-xs uppercase tracking-wide text-zinc-500">
                    Job {{ $panelOperation['status'] }} · #{{ $panelOperation['id'] }}
                </div>
                <pre class="mt-2 max-h-48 overflow-auto font-mono text-[11px] text-zinc-300">{{ $panelOperation['log'] ?: 'Waiting for broker log…' }}</pre>
            </div>
        @else
            <form class="space-y-3" onsubmit="return false;">
                <p class="text-sm text-zinc-400">
                    Type <span class="font-mono text-zinc-200">{{ \AzerioidPanel\Broker\Panel\PanelUpdater::CONFIRM }}</span>, then apply.
                    Dirty working trees are refused. Failures roll back to the prior COMMIT.
                </p>
                <input class="field max-w-md" wire:model="panelConfirm" placeholder="PANEL-UPDATE">
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-ghost" wire:click="reloadPanelCheck">Refresh check</button>
                    <button
                        type="button"
                        class="btn-primary"
                        wire:click="queuePanelUpdate"
                        wire:confirm="Apply panel self-update from origin/main? The panel will reload its own PHP-FPM/queue after deploy."
                        @disabled(empty($panelUpdate['update_available']) || !empty($panelUpdate['dirty']) || !empty($panelUpdate['error']))
                    >Apply panel update</button>
                </div>
            </form>
        @endif
    </section>

    <p class="text-sm text-zinc-400">
        Counts pending <strong class="font-medium text-zinc-200">OS package updates</strong> on this host
        ({{ $pkgMgr !== '' ? $pkgMgr : 'package manager' }}{{ $updateSource !== '' ? ' · '.$updateSource : '' }}{{ $distro !== '' ? ' · '.$distro : '' }}).
        Separate from panel self-update above.
    </p>

    <section class="grid gap-4 sm:grid-cols-3">
        <div class="panel p-5">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Pending OS packages</div>
            <div class="mt-2 font-mono text-2xl">{{ $total }}</div>
        </div>
        <div class="panel p-5">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Security</div>
            <div class="mt-2 font-mono text-2xl {{ $security ? 'text-warn' : 'text-good' }}">{{ $security }}</div>
        </div>
        <div class="panel p-5">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Reboot required</div>
            <div class="mt-2 font-mono text-2xl {{ $rebootRequired ? 'text-warn' : 'text-good' }}">{{ $rebootRequired ? 'yes' : 'no' }}</div>
            @if ($rebootPackages)
                <div class="mt-2 font-mono text-[10px] text-zinc-500">{{ implode(', ', $rebootPackages) }}</div>
            @endif
        </div>
    </section>

    <form class="panel space-y-3 p-5" onsubmit="return false;">
        <p class="text-sm text-zinc-400">
            Type the confirmation phrase, then run.
            On apt hosts, security uses <span class="font-mono">unattended-upgrade</span>; on EL, <span class="font-mono">dnf update --security</span>.
            Apply-all may restart services. Reboot takes <strong>every site</strong> down.
        </p>
        <input class="field max-w-md" wire:model="confirm" placeholder="APPLY-SECURITY / APPLY-ALL / REBOOT">
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn-primary" wire:click="applySecurity" wire:confirm="Apply security updates only?">Apply security</button>
            <button type="button" class="btn-ghost" wire:click="applyAll" wire:confirm="Apply ALL OS package updates? Services may restart.">Apply all</button>
            <button type="button" class="btn-danger" wire:click="reboot" wire:confirm="This reboots the whole droplet. Continue?">Reboot host</button>
        </div>
    </form>

    @if ($output)
        <pre class="panel max-h-80 overflow-auto p-4 font-mono text-xs text-zinc-300">{{ $output }}</pre>
    @endif

    <section class="panel overflow-hidden">
        <div class="border-b border-white/5 px-5 py-3 text-xs uppercase tracking-wide text-zinc-500">Packages (first 200)</div>
        <div class="divide-y divide-white/5">
            @foreach ($packages as $p)
                <div class="flex justify-between px-5 py-2 font-mono text-xs">
                    <span>{{ $p['name'] }}</span>
                    <span class="{{ !empty($p['security']) ? 'text-warn' : 'text-zinc-500' }}">{{ !empty($p['security']) ? 'security' : 'updates' }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="panel overflow-hidden">
        <div class="border-b border-white/5 px-5 py-3 text-xs uppercase tracking-wide text-zinc-500">TLS certificates (read-only)</div>
        <div class="divide-y divide-white/5">
            @forelse ($certs as $c)
                <div class="px-5 py-3 font-mono text-xs">
                    <div class="flex justify-between">
                        <span>{{ $c['domain'] }}</span>
                        <span class="{{ ($c['renewal'] ?? '') === 'ok' ? 'text-good' : 'text-warn' }}">{{ $c['days_remaining'] ?? '—' }}d · {{ $c['renewal'] ?? '' }}</span>
                    </div>
                    <div class="mt-1 text-zinc-500">{{ $c['issuer'] ?? ($c['error'] ?? '') }}</div>
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-zinc-500">No certificates probed.</p>
            @endforelse
        </div>
    </section>
</div>
