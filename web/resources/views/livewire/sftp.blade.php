{{-- A48 / request #11 — per-vhost SFTP. Every refusal comes from the broker: the sshd drop-in is
     validated with `sshd -t` before any reload, sshd is reloaded and never restarted, and the Match
     block cannot match root, so the administrator's own access is not at stake here. --}}
<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif

    <div class="panel p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-medium">sshd configuration</h2>
                <p class="mt-1 text-xs text-zinc-500">
                    @if ($state['configured'] ?? false)
                        Managed through <span class="font-mono">{{ $state['drop_in'] ?? '' }}</span>,
                        group <span class="font-mono">{{ $state['group'] ?? '' }}</span>,
                        unit <span class="font-mono">{{ $state['service'] ?? '' }}</span>.
                    @else
                        Not configured yet. Enabling a site below sets this up for you.
                    @endif
                </p>
            </div>
            <div class="flex gap-2">
                @if ($state['configured'] ?? false)
                    <button type="button" class="btn-ghost" wire:click="unconfigure"
                            wire:confirm="Remove the panel's sshd drop-in? Sites lose SFTP access; your own SSH access is unaffected.">Remove</button>
                @else
                    <button type="button" class="btn-primary" wire:click="configure">Configure sshd</button>
                @endif
            </div>
        </div>

        @unless ($state['include_present'] ?? true)
            <p class="mt-3 rounded-md border border-bad/40 bg-bad/10 px-3 py-2 text-xs text-bad">
                This host's <span class="font-mono">sshd_config</span> does not include
                <span class="font-mono">sshd_config.d/*.conf</span>, so a drop-in would be ignored. The panel
                will not edit <span class="font-mono">sshd_config</span> itself — add that line by hand first.
            </p>
        @endunless
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-medium">Sites</h2>
        @if ($vhosts === [])
            <p class="mt-4 text-sm text-zinc-500">No sites to enable SFTP for yet.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-zinc-500">
                        <tr>
                            <th class="px-2 py-2 font-medium">Site</th>
                            <th class="px-2 py-2 font-medium">Username</th>
                            <th class="px-2 py-2 font-medium">SFTP</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($vhosts as $v)
                            @php($domain = $v['domain'] ?? '')
                            @php($on = $this->enabledFor($domain))
                            <tr wire:key="sftp-{{ $domain }}" class="align-top">
                                <td class="px-2 py-2 text-zinc-200">{{ $domain }}</td>
                                <td class="px-2 py-2 font-mono text-[11px] text-zinc-400">az-vh-{{ str_replace('.', '-', $domain) }}</td>
                                <td class="px-2 py-2 {{ $on ? 'text-good' : 'text-zinc-500' }}">{{ $on ? 'enabled' : 'off' }}</td>
                                <td class="px-2 py-2 text-right whitespace-nowrap">
                                    @if ($on)
                                        <button type="button" class="text-brass-400 hover:underline" wire:click="showKeys('{{ $domain }}')">
                                            {{ $keysFor === $domain ? 'Hide keys' : 'Keys' }}
                                        </button>
                                        <button type="button" class="ml-2 text-zinc-400 hover:underline"
                                                wire:click="toggle('{{ $domain }}', false)">Disable</button>
                                    @else
                                        <button type="button" class="text-brass-400 hover:underline"
                                                wire:click="toggle('{{ $domain }}', true)">Enable</button>
                                    @endif
                                </td>
                            </tr>
                            @if ($keysFor === $domain)
                                <tr wire:key="sftp-keys-{{ $domain }}">
                                    <td colspan="4" class="bg-ink-900/60 px-4 py-3">
                                        @if ($keys === [])
                                            <p class="text-[11px] text-warn">
                                                No keys installed — nobody can connect yet. There is no password to set:
                                                these accounts have a shell for the panel's Terminal, so a password here
                                                would be an SSH login as well.
                                            </p>
                                        @else
                                            <table class="w-full text-left text-[11px]">
                                                <tbody class="divide-y divide-white/5">
                                                    @foreach ($keys as $key)
                                                        <tr wire:key="key-{{ $key['fingerprint'] }}">
                                                            <td class="py-1 font-mono text-zinc-400">{{ $key['type'] }}</td>
                                                            <td class="py-1 font-mono text-zinc-300">{{ $key['fingerprint'] }}</td>
                                                            <td class="py-1 text-zinc-500">{{ $key['comment'] }}</td>
                                                            <td class="py-1 text-right">
                                                                <button type="button" class="text-bad hover:underline"
                                                                        wire:click="removeKey('{{ $key['fingerprint'] }}')"
                                                                        wire:confirm="Remove this key? Anyone using it loses access immediately.">Remove</button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @endif
                                        <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">
                                            Public key (the <span class="font-mono normal-case">.pub</span> file)
                                            <textarea class="field mt-1 h-20 font-mono text-[11px]" wire:model="newKey"
                                                      placeholder="ssh-ed25519 AAAAC3... operator@workstation"></textarea>
                                        </label>
                                        <div class="mt-2 flex items-center gap-3">
                                            <button type="button" class="btn-primary" wire:click="addKey">Install key</button>
                                            <span class="text-[11px] text-zinc-500">
                                                Stored root-owned, outside the site's home, so the site cannot add its own.
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="text-xs text-zinc-500">
        SFTP here is file transfer only: no shell, no port forwarding, keys only. The panel configures sshd
        through a drop-in, checks it with <span class="font-mono">sshd -t</span> before applying, and reloads
        rather than restarts — a rejected configuration is removed again and never loaded. Your own SSH access
        cannot be affected: the rule only matches sites you have enabled here.
    </p>
</div>
