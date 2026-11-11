<div class="space-y-6" wire:poll.10s="reload">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">
            <pre class="max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs leading-5">{{ $error }}</pre>
        </div>
    @endif
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    @if (!$supervisorInstalled)
        <div class="panel p-6 text-sm text-zinc-400">
            <p>Supervisor is not installed. Install it from <a href="/components" class="text-accent hover:underline">Components</a> to manage long-running application processes.</p>
        </div>
    @else
        <div class="flex flex-wrap justify-end gap-2">
            @if ($formMode === '' && $editingName === null)
                <button type="button" class="btn-primary" wire:click="openFreeform">New freeform process</button>
                <button type="button" class="btn-ghost" wire:click="openVhostTied">New vhost-tied app</button>
            @endif
        </div>

        @if ($formMode !== '' || $editingName !== null)
            <form wire:submit="{{ $editingName ? 'saveEdit' : 'create' }}" class="panel grid gap-4 p-5 md:grid-cols-2">
                <p class="md:col-span-2 text-sm text-zinc-400">
                    @if ($editingName)
                        Editing <span class="font-mono text-zinc-200">{{ $editingName }}</span>
                    @elseif ($formMode === 'vhost')
                        <strong class="text-zinc-200">Vhost-tied process</strong> — linked to a virtual host; optionally creates a reverse-proxy vhost.
                    @else
                        <strong class="text-zinc-200">Freeform process</strong> — standalone command with no vhost association.
                    @endif
                    · Runs as <span class="font-mono text-brass-400">azerioid-supervised</span> (fixed).
                </p>
                @if (!$editingName)
                    <label class="text-xs uppercase tracking-wide text-zinc-500">Program name
                        <input class="field mt-1 font-mono" wire:model="name" placeholder="my-app" required>
                    </label>
                @endif
                <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">Command
                    <input class="field mt-1 font-mono" wire:model="command" placeholder="node server.js" required>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">Working directory
                    <input class="field mt-1 font-mono" wire:model="directory" placeholder="/data/www/app.example.com" required>
                </label>
                @if ($formMode === 'vhost')
                    <label class="text-xs uppercase tracking-wide text-zinc-500">Linked vhost
                        <select class="field mt-1" wire:model.live="vhostDomain">
                            <option value="">— select —</option>
                            @foreach ($vhosts as $v)
                                <option value="{{ $v['domain'] }}">{{ $v['domain'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if (!$editingName)
                        <label class="text-xs uppercase tracking-wide text-zinc-500">Listen port (for new proxy vhost)
                            <input class="field mt-1 font-mono" wire:model="upstreamPort" placeholder="3000">
                        </label>
                    @endif
                @endif
                <label class="flex items-center gap-2 text-xs uppercase tracking-wide text-zinc-500">
                    <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="autostart">
                    Autostart
                </label>
                <label class="flex items-center gap-2 text-xs uppercase tracking-wide text-zinc-500">
                    <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="autorestart">
                    Autorestart
                </label>
                <div class="flex gap-3 md:col-span-2">
                    <button class="btn-primary" type="submit">{{ $editingName ? 'Save changes' : 'Create process' }}</button>
                    <button type="button" class="btn-ghost" wire:click="cancelForm">Cancel</button>
                </div>
            </form>
        @endif

        <div class="panel overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="font-mono text-[11px] uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Vhost</th>
                        <th class="px-4 py-3">Command</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @forelse ($programs as $program)
                        @php
                            $state = $program['status']['state'] ?? 'unknown';
                            $led = match ($state) {
                                'running' => 'led-on',
                                'stopped', 'exited' => 'led-off',
                                'fatal', 'backoff' => 'led-bad',
                                default => 'led-warn',
                            };
                        @endphp
                        <tr>
                            <td class="px-4 py-3 font-mono">{{ $program['name'] }}</td>
                            <td class="px-4 py-3">
                                <span class="led {{ $led }}"></span>
                                <span class="ml-2 font-mono text-xs text-zinc-400">{{ $state }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                @if (!empty($program['vhost_domain']))
                                    <a href="/vhosts" class="text-accent">{{ $program['vhost_domain'] }}</a>
                                @else
                                    <span class="text-zinc-600">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-400 max-w-xs truncate" title="{{ $program['command'] ?? '' }}">{{ $program['command'] ?? '' }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap space-x-2">
                                <button type="button" class="text-xs text-accent" wire:click="askControl('start', @js($program['name']))">Start</button>
                                <button type="button" class="text-xs text-zinc-400" wire:click="askControl('stop', @js($program['name']))">Stop</button>
                                <button type="button" class="text-xs text-zinc-400" wire:click="askControl('restart', @js($program['name']))">Restart</button>
                                <button type="button" class="text-xs text-zinc-400" wire:click="startEdit(@js($program['name']))">Edit</button>
                                <button type="button" class="text-xs text-zinc-400" wire:click="showLogs(@js($program['name']))">Logs</button>
                                <button type="button" class="text-xs text-bad" wire:click="askControl('delete', @js($program['name']))">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No supervised processes yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($pendingName)
        <div class="panel border border-warn/40 p-5">
            <p class="text-sm">Confirm <span class="font-mono text-warn">{{ $pendingAction }}</span> on <span class="font-mono">{{ $pendingName }}</span>.</p>
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="runControl">Confirm</button>
                <button class="btn-ghost" wire:click="$set('pendingName', null)">Cancel</button>
            </div>
        </div>
    @endif

    @if ($viewingLogs)
        <div class="panel p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-mono text-sm">Logs · {{ $viewingLogs }}</h3>
                <button type="button" class="btn-ghost text-xs" wire:click="$set('viewingLogs', null)">Close</button>
            </div>
            <div>
                <div class="mb-1 text-xs uppercase tracking-wide text-zinc-500">stdout</div>
                <pre class="max-h-48 overflow-auto rounded border border-white/5 bg-ink-950/60 p-3 font-mono text-[10px] leading-4 text-zinc-300">{{ $logStdout !== '' ? $logStdout : '(empty)' }}</pre>
            </div>
            <div>
                <div class="mb-1 text-xs uppercase tracking-wide text-zinc-500">stderr</div>
                <pre class="max-h-48 overflow-auto rounded border border-white/5 bg-ink-950/60 p-3 font-mono text-[10px] leading-4 text-zinc-300">{{ $logStderr !== '' ? $logStderr : '(empty)' }}</pre>
            </div>
        </div>
    @endif
</div>
