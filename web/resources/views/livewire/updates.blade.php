<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <pre class="max-h-64 overflow-auto rounded-md border border-bad/40 bg-bad/10 px-4 py-3 font-mono text-xs text-bad">{{ $error }}</pre>
    @endif

    <p class="text-sm text-zinc-400">
        Counts pending <strong class="font-medium text-zinc-200">OS package updates</strong> on this host
        ({{ $pkgMgr !== '' ? $pkgMgr : 'package manager' }}{{ $updateSource !== '' ? ' · '.$updateSource : '' }}{{ $distro !== '' ? ' · '.$distro : '' }}).
        This is not a panel self-update — the stack manager itself is not upgraded from this page.
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
