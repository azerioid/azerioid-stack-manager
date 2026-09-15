<div class="space-y-6" @if ($activeOperation) wire:poll.2s="pollOperation" @endif>
    @if ($error)
        <div class="rounded-md border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
        @if ($preflightRemediations !== [])
            <section class="panel border border-brass-500/30 p-5 space-y-3">
                <p class="text-sm text-zinc-300">Resolve the port conflict, then retry:</p>
                @foreach ($preflightRemediations as $remediation)
                    <p class="text-sm text-zinc-400">{{ $remediation['detail'] ?? '' }}</p>
                    @if (($remediation['action'] ?? '') === 'web.release-site-ports')
                        <div class="flex gap-2">
                            <button type="button" class="btn-primary" wire:click="releaseSitePorts">
                                {{ $remediation['label'] ?? 'Release site ports' }}
                            </button>
                            <button type="button" class="btn-ghost" wire:click="dismissPreflightRemediation">Dismiss</button>
                        </div>
                    @endif
                @endforeach
            </section>
        @endif
    @endif
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    @if ($activeOperation)
        <section class="panel p-5 space-y-2">
            <h2 class="text-sm font-medium">Operation in progress</h2>
            <p class="text-sm text-zinc-400">
                {{ ucfirst($activeOperation['action']) }}
                <span class="font-mono text-zinc-300">{{ $activeOperation['component_id'] }}</span>
                — <span class="uppercase text-xs tracking-wide">{{ $activeOperation['status'] }}</span>
            </p>
            @if (!empty($activeOperation['log']))
                <pre class="mt-2 max-h-48 overflow-auto rounded bg-black/40 p-3 font-mono text-xs text-zinc-400">{{ $activeOperation['log'] }}</pre>
            @endif
        </section>
    @endif

    @if ($pendingInstall)
        <section class="panel border border-brass-500/30 p-5 space-y-3">
            <p class="text-sm text-zinc-300">
                Install <span class="font-mono">{{ $pendingInstall }}</span>
                @if ($pendingInstall === 'nodejs')
                    — choose a Node.js major version (runtime only, no PM2).
                @endif
            </p>
            @if ($pendingInstall === 'nodejs')
                <label class="text-xs uppercase tracking-wide text-zinc-500">Major version
                    <select class="field mt-1" wire:model="nodeMajor">
                        <option value="20">20 LTS</option>
                        <option value="22">22 LTS</option>
                        <option value="24">24</option>
                    </select>
                </label>
            @endif
            @if ($pendingInstall === 'mail')
                <p class="text-sm text-zinc-400">
                    Mail is opt-in and reputation-sensitive. It opens ports 25, 465, 587, and 993 to the internet.
                    After install you must set a mail hostname, publish DNS, and set reverse DNS (PTR) at your VPS
                    provider. Outbound port 25 is blocked by default at many providers — the Mail page will tell you
                    plainly and offer relay setup.
                </p>
                @if ($foreignMtaReasons !== [])
                    <div class="rounded-md border border-bad/40 bg-bad/10 p-4 space-y-3">
                        <p class="text-sm text-bad">This host already has a mail transport:</p>
                        <ul class="list-disc space-y-1 pl-5 text-sm text-zinc-300">
                            @foreach ($foreignMtaReasons as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                        <p class="text-sm text-zinc-400">
                            Installing Postfix removes those packages. Anything relying on the current transport to send
                            mail will stop working. Type <span class="font-mono text-zinc-200">REPLACE-MTA</span> to proceed.
                        </p>
                        <input class="field w-64 font-mono" wire:model="replaceMtaConfirm" placeholder="REPLACE-MTA" autocomplete="off">
                    </div>
                @endif
            @endif
            <div class="flex gap-2">
                <button type="button" class="btn-primary" wire:click="confirmInstall">Queue install</button>
                <button type="button" class="btn-ghost" wire:click="cancelInstall">Cancel</button>
            </div>
        </section>
    @endif

    @if ($pendingUninstall)
        <section class="panel border-warn/30 p-5 space-y-3">
            <p class="text-sm text-zinc-300">
                Remove <span class="font-mono">{{ $pendingUninstall }}</span> and its packages from this host?
            </p>
            @if (in_array($pendingUninstall, ['mariadb', 'postgresql'], true))
                <p class="text-sm text-warn">
                    This will remove the database server and all site databases on this host.
                </p>
                <label class="flex items-center gap-2 text-sm text-zinc-400">
                    <input type="checkbox" wire:model="dumpBeforeUninstall">
                    Dump all databases to staging before uninstall
                </label>
            @endif
            <div class="flex gap-2">
                <button type="button" class="btn-primary" wire:click="uninstall">Confirm uninstall</button>
                <button type="button" class="btn-ghost" wire:click="cancelUninstall">Cancel</button>
            </div>
        </section>
    @endif

    <section class="panel p-5">
        <h2 class="text-sm font-medium">Component registry</h2>
        <p class="mt-1 text-sm text-zinc-400">
            Detected on <span class="font-mono text-zinc-300">{{ $distroLabel ?: 'this host' }}</span>.
            Phase 6: PHP runtimes, Node.js, Memcached, and MongoDB install from the dashboard.
        </p>
    </section>

    @include('livewire.partials.component-grid', [
        'title' => 'System (panel)',
        'hint' => 'Pinned runtime components required by the panel.',
        'components' => $systemComponents,
        'empty' => 'No system components reported.',
        'operationBusy' => $operationBusy,
    ])

    @include('livewire.partials.component-grid', [
        'title' => 'Managed catalog',
        'hint' => 'Installable stack components from the registry.',
        'components' => $managedComponents,
        'empty' => 'No managed components in the registry.',
        'operationBusy' => $operationBusy,
    ])

    @if ($observedComponents !== [] || $observedExtras !== [])
        @include('livewire.partials.component-grid', [
            'title' => 'Observed on host',
            'hint' => 'Found on this machine but not installed by the panel. Adopt to manage without reinstalling packages.',
            'components' => array_merge($observedComponents, $observedExtras),
            'empty' => null,
            'operationBusy' => $operationBusy,
        ])
    @endif

    @if ($brokenComponents !== [])
        @include('livewire.partials.component-grid', [
            'title' => 'Broken',
            'hint' => 'Package present but service unit missing or failed.',
            'components' => $brokenComponents,
            'empty' => null,
            'operationBusy' => $operationBusy,
        ])
    @endif
</div>
