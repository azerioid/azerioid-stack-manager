<div class="space-y-6">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    <div class="flex justify-end">
        <button type="button" class="btn-primary" wire:click="$toggle('showForm')">{{ $showForm ? 'Close' : 'Add vhost' }}</button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="panel grid gap-4 p-5 md:grid-cols-2">
            <label class="text-xs uppercase tracking-wide text-zinc-500">Domain
                <input class="field mt-1" wire:model.blur="domain" placeholder="app.example.com" required>
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">Web root
                <input class="field mt-1" wire:model="root" placeholder="/data/www/app.example.com" required>
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">Type
                <select class="field mt-1" wire:model.live="type">
                    <option value="php">PHP-FPM</option>
                    <option value="static">Static</option>
                    <option value="proxy">Reverse proxy (127.0.0.1)</option>
                </select>
            </label>
            @if ($type === 'php')
                <label class="text-xs uppercase tracking-wide text-zinc-500">PHP version
                    <select class="field mt-1" wire:model="php_version">
                        @foreach ($phpVersions as $ver)
                            <option value="{{ $ver }}">{{ $ver }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            @if ($type === 'proxy')
                <label class="text-xs uppercase tracking-wide text-zinc-500">Upstream
                    <input class="field mt-1" wire:model="upstream" placeholder="127.0.0.1:9000">
                </label>
            @endif
            <div class="md:col-span-2">
                <button class="btn-primary" type="submit">Validate &amp; create</button>
            </div>
        </form>
    @endif

    @if ($editingDomain)
        <form wire:submit="saveEdit" class="panel grid gap-4 p-5 md:grid-cols-2">
            <p class="md:col-span-2 text-sm text-zinc-400">
                Editing <span class="font-mono text-zinc-200">{{ $editingDomain }}</span>
                ({{ $editType }}) — domain and type are fixed.
            </p>
            @if ($editType !== 'proxy')
                <label class="text-xs uppercase tracking-wide text-zinc-500">Web root
                    <input class="field mt-1" wire:model="editRoot" required>
                </label>
            @endif
            @if ($editType === 'php')
                <label class="text-xs uppercase tracking-wide text-zinc-500">PHP version
                    <select class="field mt-1" wire:model="editPhpVersion">
                        @foreach ($phpVersions as $ver)
                            <option value="{{ $ver }}">{{ $ver }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">TLS mode
                <select class="field mt-1" wire:model.live="editTlsMode">
                    <option value="off">Off (HTTP only)</option>
                    <option value="auto">Automatic (HTTP-01 Let's Encrypt)</option>
                    <option value="dns01">DNS challenge (wildcard / pre-DNS)</option>
                    <option value="internal">Self-signed</option>
                </select>
            </label>
            @if ($editTlsMode === 'dns01')
                <label class="text-xs uppercase tracking-wide text-zinc-500">DNS provider
                    <select class="field mt-1" wire:model="editDnsProvider">
                        <option value="">Select…</option>
                        @foreach ($dnsProviders as $p)
                            <option value="{{ $p['id'] }}">{{ $p['display_name'] }}{{ !empty($p['credentials_present']) ? ' (token stored)' : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">API token (stdin only — leave blank to reuse stored)
                    <input class="field mt-1" type="password" autocomplete="new-password" wire:model="editDnsToken" placeholder="••••••••">
                </label>
            @endif
            <label class="flex items-center gap-2 text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">
                <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="editAcmeStaging">
                Use Let's Encrypt staging (for tests — avoids rate limits)
            </label>
            <div class="flex gap-3 md:col-span-2">
                <button class="btn-primary" type="submit">Validate &amp; save</button>
                <button type="button" class="btn-ghost" wire:click="cancelEdit">Cancel</button>
            </div>
        </form>
    @endif

    <div class="panel overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="font-mono text-[11px] uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-4 py-3">Domain</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Root / upstream</th>
                    <th class="px-4 py-3">PHP</th>
                    <th class="px-4 py-3">TLS</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                @forelse ($vhosts as $v)
                    <tr>
                        <td class="px-4 py-3 font-mono">{{ $v['domain'] }}</td>
                        <td class="px-4 py-3">
                            {{ $v['type'] }}
                            @if (!empty($v['readonly']))
                                <span class="ml-1 rounded bg-ink-700 px-1.5 py-0.5 font-mono text-[10px] uppercase text-zinc-400">read-only</span>
                            @endif
                            @if (isset($v['enabled']) && $v['enabled'] === false)
                                <span class="ml-1 rounded bg-ink-700 px-1.5 py-0.5 font-mono text-[10px] uppercase text-zinc-500">disabled</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-400">{{ $v['reverse_proxy'] ?? $v['root'] }}</td>
                        <td class="px-4 py-3 font-mono">{{ $v['php_version'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs">
                            @php
                                $ts = $v['tls_status'] ?? null;
                                $label = is_array($ts) ? ($ts['label'] ?? null) : null;
                            @endphp
                            @if ($label)
                                <span class="font-mono text-zinc-200">{{ $label }}</span>
                                @if (!empty($ts['issuer_type']))
                                    <div class="mt-0.5 font-mono text-[10px] uppercase text-zinc-500">{{ $ts['issuer_type'] }} · {{ $v['tls_mode'] ?? '' }}</div>
                                @endif
                            @else
                                {{ !empty($v['tls']) ? (($v['tls_mode'] ?? 'auto')) : 'http' }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right space-x-3">
                            @if (!empty($supervisorByVhost[$v['domain'] ?? '']))
                                <a href="/processes" class="text-xs text-accent" title="Supervisor processes">{{ count($supervisorByVhost[$v['domain']]) }} proc</a>
                            @endif
                            @if (empty($v['readonly']))
                                <a href="/vhosts/{{ $v['domain'] }}/terminal" class="text-xs text-accent">Terminal</a>
                                <button type="button" class="text-xs text-accent" wire:click="startEdit('{{ $v['domain'] }}')">Edit</button>
                                <button type="button" class="text-xs text-bad" wire:click="askDelete('{{ $v['domain'] }}')">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No virtual hosts found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($confirmDelete)
        <div class="panel border border-warn/40 p-5">
            <p class="text-sm">Delete vhost <span class="font-mono">{{ $confirmDelete }}</span>? Website files will be kept.</p>
            @if (!empty($supervisorByVhost[$confirmDelete]))
                <p class="mt-2 text-sm text-warn">
                    This vhost has supervisor process(es):
                    <span class="font-mono">{{ implode(', ', $supervisorByVhost[$confirmDelete]) }}</span>
                </p>
                <label class="mt-3 flex items-center gap-2 text-sm text-zinc-400">
                    <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="removeSupervisorOnDelete">
                    Also remove linked supervisor processes
                </label>
            @endif
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="delete">Confirm delete</button>
                <button class="btn-ghost" wire:click="$set('confirmDelete', null)">Cancel</button>
            </div>
        </div>
    @endif
</div>
