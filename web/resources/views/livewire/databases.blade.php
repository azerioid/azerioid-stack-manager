<div class="space-y-6">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    @if ($configuredEngines === [])
        <div class="panel border border-warn/30 p-5 text-sm text-zinc-300">
            No database engine is configured yet. Install <span class="font-mono">mariadb</span>, <span class="font-mono">postgresql</span>, or <span class="font-mono">mongodb</span> from the Components page.
        </div>
    @elseif (count($configuredEngines) > 1)
        <div class="panel p-5">
            <label class="text-xs uppercase tracking-wide text-zinc-500">Engine
                <select class="field mt-1" wire:model.live="selectedEngine">
                    @foreach ($configuredEngines as $engine)
                        <option value="{{ $engine['id'] }}">{{ $engine['label'] }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    @endif

    @if ($selectedEngine === 'mongodb')
        <div class="rounded-md border border-warn/40 bg-warn/10 px-4 py-3 text-sm text-warn">
            MongoDB has no per-database host authentication. Remote access here only changes this instance’s firewall and bind on port 27017 — every database on this mongod shares that network scope. MariaDB and PostgreSQL enforce hosts per database inside the engine.
        </div>
    @endif

    @if ($revealedPassword)
        <div class="panel border border-brass-500/40 p-5">
            <div class="text-xs uppercase tracking-wide text-warn">One-time password reveal</div>
            <div class="mt-2 flex items-center gap-3">
                <code class="font-mono text-sm text-zinc-100" x-ref="pw">{{ $revealedPassword }}</code>
                <button type="button" class="btn-ghost text-xs" @click="navigator.clipboard.writeText($refs.pw.innerText)">Copy</button>
            </div>
            <p class="mt-2 text-xs text-zinc-500">This value is not stored by the panel and is not written to logs.</p>
        </div>
    @endif

    @if ($selectedEngine !== '')
        <div class="flex justify-end">
            <button type="button" class="btn-primary" wire:click="$toggle('showForm')">{{ $showForm ? 'Close' : 'Add database' }}</button>
        </div>

        @if ($showForm)
            <form wire:submit="create" class="panel grid gap-4 p-5 md:grid-cols-2">
                <label class="text-xs uppercase tracking-wide text-zinc-500">Database name
                    <input class="field mt-1" wire:model="name" maxlength="32" required>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">User (default: same as db)
                    <input class="field mt-1" wire:model="user" maxlength="32">
                </label>
                @if ($selectedEngine === 'mongodb')
                    <p class="md:col-span-2 text-xs text-zinc-500">MongoDB materializes the database on first write and creates a <span class="font-mono">dbOwner</span> user on that database. Connect with <span class="font-mono">--authenticationDatabase &lt;name&gt;</span>.</p>
                @endif
                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="btn-primary">Create</button>
                    <p class="self-center text-xs text-zinc-500">A one-time password is generated only after {{ $engineLabel }} confirms the create.</p>
                </div>
            </form>
        @endif
    @endif

    @if ($selectedEngine !== '')
        <div class="panel overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="font-mono text-[11px] uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Size</th>
                        <th class="px-4 py-3">Tables</th>
                        <th class="px-4 py-3">Users</th>
                        <th class="px-4 py-3">Remote access</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @forelse ($databases as $db)
                        @php
                            $rowAccess = is_array($db['access'] ?? null) ? $db['access'] : [];
                            $rowAccessMode = $rowAccess['mode'] ?? 'localhost';
                            $rowAccessLabel = $rowAccess['label'] ?? 'Localhost only';
                        @endphp
                        <tr>
                            <td class="px-4 py-3 font-mono">
                                {{ $db['name'] }}
                                @if (!empty($db['protected']))
                                    <span class="ml-1 rounded bg-ink-700 px-1.5 py-0.5 font-mono text-[10px] uppercase text-zinc-400">protected</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $format::bytes((int) $db['size_bytes']) }}</td>
                            <td class="px-4 py-3 font-mono">{{ $db['table_count'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-400">
                                @foreach ($db['users'] as $u)
                                    {{ $u['user'].'@'.$u['host'] }}@if (!$loop->last), @endif
                                @endforeach
                            </td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded px-1.5 py-0.5 font-mono text-[10px] uppercase',
                                    'bg-ink-700 text-zinc-400' => $rowAccessMode === 'localhost',
                                    'bg-brass-500/20 text-brass-400' => $rowAccessMode === 'specific',
                                    'bg-bad/20 text-bad' => $rowAccessMode === 'global',
                                ])>{{ $rowAccessLabel }}</span>
                                @if (!empty($rowAccess['conflict']))
                                    <div class="mt-1 text-[10px] text-warn">Instance firewall is wider than this database’s requested scope.</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                @if (empty($db['protected']))
                                    @php $firstUser = $db['users'][0]['user'] ?? $db['name']; @endphp
                                    <button type="button" class="text-xs text-brass-400" wire:click="startReset('{{ $firstUser }}')" wire:confirm="Reset password for {{ $firstUser }}?">Reset pw</button>
                                    <button type="button" class="text-xs text-brass-400" wire:click="startAccess('{{ $db['name'] }}')">Access</button>
                                    <button type="button" class="text-xs text-bad" wire:click="$set('confirmDelete', '{{ $db['name'] }}')">Delete</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No databases visible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($accessName && !$accessShowGlobalModal)
            <form wire:submit="requestAccessSave" class="panel border border-brass-500/30 p-5 space-y-4">
                <p class="text-sm">Remote access for <span class="font-mono text-brass-400">{{ $accessName }}</span> ({{ $engineLabel }})</p>
                @if ($selectedEngine === 'mongodb')
                    <p class="text-xs text-warn">Specific IP and Global only restrict who can reach port 27017 for this whole MongoDB instance — not this database alone.</p>
                @elseif ($selectedEngine === 'mariadb')
                    <p class="text-xs text-zinc-500">MariaDB will grant this database’s user only from the hosts you set (<span class="font-mono">user@ip</span>). Localhost access is always kept.</p>
                @else
                    <p class="text-xs text-zinc-500">PostgreSQL will add <span class="font-mono">pg_hba.conf</span> lines for this database only, then reload. Local connections stay unchanged.</p>
                @endif
                <fieldset class="space-y-2 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="accessMode" value="localhost" @checked($accessMode === 'localhost')> Localhost only
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model.live="accessMode" value="specific" @checked($accessMode === 'specific')> Specific IP(s)
                    </label>
                    <label class="flex items-center gap-2 text-bad">
                        <input type="radio" wire:model.live="accessMode" value="global" @checked($accessMode === 'global')> Global (any IP)
                    </label>
                </fieldset>
                @if ($accessMode === 'specific')
                    <label class="text-xs uppercase tracking-wide text-zinc-500">Allowed IPs or CIDRs
                        <input
                            class="field mt-1"
                            wire:model="accessIpsText"
                            wire:key="access-ips-{{ $accessName }}-{{ $accessMode }}"
                            value="{{ $accessIpsText }}"
                            placeholder="IP or CIDR, comma-separated"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            pattern="^\s*(?:\d{1,3}(?:\.\d{1,3}){3}(?:/\d{1,2})?)(?:\s*,\s*\d{1,3}(?:\.\d{1,3}){3}(?:/\d{1,2})?)*\s*$"
                            title="Comma-separated IPv4 addresses or CIDRs"
                            aria-label="Allowed IPs or CIDRs"
                        >
                    </label>
                    <p class="text-xs text-zinc-500">Saved IPs appear in the field. Placeholder text is only an example for a first-time Specific-IP setup (<span class="font-mono">203.0.113.5, 198.51.100.0/24</span>).</p>
                    @if ($selectedEngine === 'mariadb')
                        <p class="text-xs text-zinc-500">MariaDB host grants accept /8, /16, /24, and /32 (or a single IP).</p>
                    @endif
                @endif
                <div class="flex gap-2">
                    <button class="btn-primary" type="submit">Apply access</button>
                    <button class="btn-ghost" type="button" wire:click="cancelAccess">Cancel</button>
                </div>
            </form>
        @endif

        @if ($accessShowGlobalModal)
            <form wire:submit="confirmGlobalAccess" class="panel border border-bad/50 p-5 space-y-4">
                <p class="text-sm font-medium text-bad">Expose this database to the entire internet?</p>
                <p class="text-sm text-zinc-300">
                    Global mode opens this engine’s port
                    ({{ $selectedEngine === 'mariadb' ? '3306' : ($selectedEngine === 'postgresql' ? '5432' : '27017') }})
                    to any IPv4 address. The only remaining protection is the database password.
                    Scanners will find it and brute-force it.
                    @if ($selectedEngine === 'mongodb')
                        For MongoDB this is instance-wide: every database on this mongod becomes reachable.
                    @endif
                </p>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="accessUnderstand" class="mt-1">
                    <span>I understand this exposes the port to the entire internet, protected only by the database password.</span>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">Type <span class="font-mono text-bad">{{ $accessName }}</span> to confirm
                    <input class="field mt-1" wire:model="accessConfirmName" autocomplete="off">
                </label>
                <div class="flex gap-2">
                    <button class="btn-danger" type="submit">Expose globally</button>
                    <button class="btn-ghost" type="button" wire:click="cancelAccess">Cancel</button>
                </div>
            </form>
        @endif

        @if ($confirmDelete)
            <form wire:submit="delete" class="panel border border-bad/40 p-5">
                <p class="text-sm">Type <span class="font-mono text-bad">{{ $confirmDelete }}</span> to drop the database and its user.</p>
                <input class="field mt-3" wire:model="confirmTyped" autocomplete="off">
                <div class="mt-3 flex gap-2">
                    <button class="btn-danger" type="submit">Drop database</button>
                    <button class="btn-ghost" type="button" wire:click="$set('confirmDelete', null)">Cancel</button>
                </div>
            </form>
        @endif

        @if ($resetUser)
            <div class="panel p-5">
                <p class="text-sm">Reset password for <span class="font-mono">{{ $resetUser }}</span>? A new password is shown only if {{ $engineLabel }} accepts the change.</p>
                <button type="button" class="btn-primary mt-3" wire:click="confirmReset" wire:confirm="Apply a new password now?">Apply reset</button>
                <button type="button" class="btn-ghost mt-3" wire:click="$set('resetUser', null)">Cancel</button>
            </div>
        @endif
    @endif
</div>
