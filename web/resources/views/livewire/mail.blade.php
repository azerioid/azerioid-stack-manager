<div class="space-y-6">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    @if (! $installed)
        <section class="panel border border-warn/30 p-5 space-y-3">
            <h2 class="text-sm font-medium">Mail is not installed</h2>
            <p class="text-sm text-zinc-400">
                Mail is opt-in and reputation-sensitive. Installing it puts this server on the public internet as a
                mail host: you must set an explicit mail hostname, publish DNS, and set reverse DNS (PTR) at your VPS
                provider. If another mail transport is already on this host, install will ask you to type
                <span class="font-mono">REPLACE-MTA</span> before removing it.
            </p>
            <a href="/components" class="btn-primary inline-block">Install from Components</a>
        </section>
    @else

    {{-- Health strip (A36 §7.4): outbound delivery status is mandatory, and the relay CTA sits beside it. --}}
    <section class="panel p-5 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-medium">Health</h2>
            <button type="button" class="btn-ghost text-xs" wire:click="refreshProbe">Re-check outbound</button>
        </div>

        @php $open = ($outbound['open'] ?? null) === true; @endphp
        <div class="rounded-md border px-4 py-3 {{ $open ? 'border-good/40 bg-good/10' : 'border-warn/50 bg-warn/10' }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-sm {{ $open ? 'text-good' : 'text-warn' }}">
                        {{ $status['delivery_message'] ?? '' }}
                    </div>
                    <div class="mt-1 font-mono text-xs text-zinc-500">
                        Probed {{ $outbound['host'] ?? '—' }}:{{ $outbound['port'] ?? 25 }}
                        @if (isset($outbound['latency_ms'])) · {{ $outbound['latency_ms'] }} ms @endif
                        · current path: {{ $status['delivery_mode'] ?? 'direct' }}
                    </div>
                </div>
                @if (! empty($status['relay_cta']))
                    <button type="button" class="btn-primary" wire:click="openRelayForm">Configure relay…</button>
                @endif
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-3">
            @foreach (($status['services'] ?? []) as $name => $unit)
                @php $active = ($unit['active_state'] ?? '') === 'active'; @endphp
                <div class="rounded-md border border-white/5 px-3 py-2">
                    <div class="font-mono text-xs text-zinc-500">{{ $name }}</div>
                    <div class="text-sm {{ $active ? 'text-good' : 'text-bad' }}">{{ $unit['active_state'] ?? 'unknown' }}</div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3 text-xs text-zinc-400">
            <button type="button" class="btn-ghost text-xs" wire:click="runRelaySelftest">Run open-relay self-test</button>
            @php $fw = $status['firewall'] ?? []; @endphp
            @if (($fw['missing_ports'] ?? []) !== [])
                <span class="text-warn">
                    {{ $fw['backend'] }} is not allowing {{ implode(', ', $fw['missing_ports']) }} — mail will not reach this host.
                </span>
            @else
                <span>Firewall ({{ $fw['backend'] ?? 'none' }}): 25, 465, 587, 993 open.</span>
            @endif
        </div>

        @php $ptr = $status['ptr'] ?? []; @endphp
        @if (($ptr['checked'] ?? false) && ! ($ptr['matches'] ?? false))
            <p class="text-xs text-warn">{{ $ptr['note'] }}</p>
        @endif
    </section>

    {{-- Mail hostname: explicit operator setting, never inferred (A36 §9.6). --}}
    <section class="panel p-5 space-y-3">
        <h2 class="text-sm font-medium">Mail hostname</h2>
        <p class="text-sm text-zinc-400">
            The name this server announces as, and the target of every domain’s MX record. It is not derived from your
            first vhost — set it explicitly, publish an A record for it, and make reverse DNS match.
        </p>
        <form wire:submit="saveHostname" class="flex flex-wrap items-end gap-3">
            <label class="text-xs uppercase tracking-wide text-zinc-500">Hostname
                <input class="field mt-1 w-72" wire:model="hostname" placeholder="mail.example.com">
            </label>
            <button type="submit" class="btn-primary">Save</button>
        </form>
    </section>

    {{-- Outbound / smarthost --}}
    <section class="panel p-5 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-medium">Outbound delivery</h2>
            @if ($relay)
                <div class="flex gap-2">
                    <button type="button" class="btn-ghost text-xs" wire:click="testRelay">Test connection</button>
                    <button type="button" class="btn-ghost text-xs" wire:click="openRelayForm">Edit</button>
                    <button type="button" class="btn-ghost text-xs text-bad" wire:click="clearRelay">Clear relay</button>
                </div>
            @else
                <button type="button" class="btn-ghost text-xs" wire:click="openRelayForm">Configure relay</button>
            @endif
        </div>

        @if ($relay)
            <p class="text-sm text-zinc-300">
                Relaying through <span class="font-mono">{{ $relay['host'] }}:{{ $relay['port'] }}</span>
                as <span class="font-mono">{{ $relay['username'] }}</span> ({{ $relay['tls'] }}).
            </p>
            <p class="text-xs text-zinc-500">
                The password is stored only in Postfix’s root-only credentials file. It is never displayed, logged, or
                included in the audit trail.
            </p>
        @else
            <p class="text-sm text-zinc-400">
                Sending directly to each recipient’s MX on port 25. Most cloud providers block that by default — if the
                health strip above says blocked, configure a relay (SES, SendGrid, Mailgun, or any SMTP provider).
            </p>
        @endif

        @if ($showRelayForm)
            <form wire:submit="saveRelay" class="grid gap-4 border-t border-white/5 pt-4 md:grid-cols-2">
                <label class="text-xs uppercase tracking-wide text-zinc-500">Relay host
                    <input class="field mt-1" wire:model="relayHost" placeholder="email-smtp.eu-west-1.amazonaws.com" required>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">Port
                    <input class="field mt-1" wire:model="relayPort" placeholder="587">
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">Username
                    <input class="field mt-1" wire:model="relayUsername" autocomplete="off" required>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">Password
                    <input type="password" class="field mt-1" wire:model="relayPassword" autocomplete="new-password" required>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">TLS
                    <select class="field mt-1" wire:model="relayTls">
                        <option value="starttls">STARTTLS (587)</option>
                        <option value="wrapper">Implicit TLS (465)</option>
                    </select>
                </label>
                <div class="flex items-end gap-2 md:col-span-2">
                    <button type="submit" class="btn-primary">Save relay</button>
                    <button type="button" class="btn-ghost" wire:click="closeRelayForm">Cancel</button>
                </div>
            </form>
        @endif
    </section>

    {{-- Domains --}}
    <section class="panel p-5 space-y-4">
        <h2 class="text-sm font-medium">Domains</h2>
        <p class="text-sm text-zinc-400">
            Mail domains are scoped to panel vhosts. Deleting a vhost is refused while its mail data exists.
        </p>

        @if ($vhostDomains !== [])
            <form wire:submit="enableDomainMail" class="flex flex-wrap items-end gap-3">
                <label class="text-xs uppercase tracking-wide text-zinc-500">Enable mail for vhost
                    <select class="field mt-1 w-64" wire:model="enableDomain">
                        <option value="">Choose a domain…</option>
                        @foreach ($vhostDomains as $domain)
                            <option value="{{ $domain }}">{{ $domain }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn-primary">Enable mail</button>
            </form>
        @endif

        @if ($domains === [])
            <p class="text-sm text-zinc-500">No domain has mail enabled yet.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left font-mono text-xs uppercase text-zinc-500">
                    <tr><th class="py-2">Domain</th><th>Mail</th><th>Mailboxes</th><th>Aliases</th><th>DKIM selector</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($domains as $domain)
                        <tr class="border-t border-white/5">
                            <td class="py-2 font-mono text-zinc-200">{{ $domain['domain'] }}</td>
                            <td class="{{ $domain['enabled'] ? 'text-good' : 'text-zinc-500' }}">{{ $domain['enabled'] ? 'enabled' : 'off' }}</td>
                            <td class="text-zinc-400">{{ $domain['mailbox_count'] }}</td>
                            <td class="text-zinc-400">{{ $domain['alias_count'] }}</td>
                            <td class="font-mono text-xs text-zinc-500">{{ $domain['dkim_selector'] }}</td>
                            <td class="text-right">
                                <button type="button" class="btn-ghost text-xs" wire:click="selectDnsDomain('{{ $domain['domain'] }}')">DNS</button>
                                <button type="button" class="btn-ghost text-xs" wire:click="rotateDkim('{{ $domain['domain'] }}')">Rotate DKIM</button>
                                @if ($domain['enabled'])
                                    <button type="button" class="btn-ghost text-xs text-bad" wire:click="askDisableDomain('{{ $domain['domain'] }}')">Disable</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($pendingDomainDisable)
            <div class="rounded-md border border-warn/40 bg-warn/10 p-4 space-y-3">
                <p class="text-sm text-warn">Disable mail for <span class="font-mono">{{ $pendingDomainDisable }}</span>?</p>
                <label class="flex items-center gap-2 text-sm text-zinc-300">
                    <input type="checkbox" wire:model="dropMailOnDisable">
                    Also delete every mailbox and all stored mail for this domain (cannot be undone)
                </label>
                <div class="flex gap-2">
                    <button type="button" class="btn-primary" wire:click="disableDomainMail">Confirm</button>
                    <button type="button" class="btn-ghost" wire:click="cancelDisableDomain">Cancel</button>
                </div>
            </div>
        @endif
    </section>

    {{-- DNS checklist --}}
    @if ($dnsRecords !== [])
        <section class="panel p-5 space-y-3">
            <h2 class="text-sm font-medium">DNS checklist — <span class="font-mono">{{ $dnsDomain }}</span></h2>
            <p class="text-sm text-zinc-400">
                Publish these at your DNS provider. SPF reflects your current outbound path, so it changes if you add or
                remove a relay. PTR is set at your VPS provider, not in your DNS zone.
            </p>
            <table class="w-full text-sm">
                <thead class="text-left font-mono text-xs uppercase text-zinc-500">
                    <tr><th class="py-2">Type</th><th>Name</th><th>Value</th><th>Live check</th></tr>
                </thead>
                <tbody>
                    @foreach ($dnsRecords as $record)
                        @php
                            $state = $record['state'] ?? 'unknown';
                            $tone = match ($state) { 'ok' => 'text-good', 'missing' => 'text-bad', 'mismatch' => 'text-warn', default => 'text-zinc-500' };
                        @endphp
                        <tr class="border-t border-white/5 align-top">
                            <td class="py-2 font-mono text-xs text-zinc-400">{{ $record['type'] }}</td>
                            <td class="font-mono text-xs text-zinc-300">{{ $record['name'] }}</td>
                            <td class="break-all font-mono text-xs text-zinc-200">{{ $record['value'] }}</td>
                            <td class="{{ $tone }} text-xs">
                                {{ $state }}
                                @if (! empty($record['note']))
                                    <div class="text-zinc-500">{{ $record['note'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- Mailboxes --}}
    <section class="panel p-5 space-y-4">
        <h2 class="text-sm font-medium">Mailboxes</h2>

        @if ($revealedPassword)
            <div class="rounded-md border border-brass-500/40 p-4">
                <div class="text-xs uppercase tracking-wide text-warn">One-time password for {{ $revealedFor }}</div>
                <div class="mt-2 flex items-center gap-3">
                    <code class="font-mono text-sm text-zinc-100" x-ref="mailpw">{{ $revealedPassword }}</code>
                    <button type="button" class="btn-ghost text-xs" @click="navigator.clipboard.writeText($refs.mailpw.innerText)">Copy</button>
                    <button type="button" class="btn-ghost text-xs" wire:click="dismissPassword">Dismiss</button>
                </div>
                <p class="mt-2 text-xs text-zinc-500">
                    Only a hash is stored. Dismiss after copying so the value leaves the Livewire session.
                </p>
            </div>
        @endif

        <form wire:submit="addMailbox" class="flex flex-wrap items-end gap-3">
            <label class="text-xs uppercase tracking-wide text-zinc-500">New mailbox
                <input class="field mt-1 w-72" wire:model="newMailboxAddress" placeholder="you@example.com">
            </label>
            <button type="submit" class="btn-primary">Create</button>
        </form>

        @if ($mailboxes === [])
            <p class="text-sm text-zinc-500">No mailboxes yet.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left font-mono text-xs uppercase text-zinc-500">
                    <tr><th class="py-2">Address</th><th>State</th><th>Created</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($mailboxes as $mailbox)
                        <tr class="border-t border-white/5">
                            <td class="py-2 font-mono text-zinc-200">{{ $mailbox['address'] }}</td>
                            <td class="{{ $mailbox['disabled'] ? 'text-warn' : 'text-good' }}">{{ $mailbox['disabled'] ? 'disabled' : 'active' }}</td>
                            <td class="font-mono text-xs text-zinc-500">{{ $mailbox['created_at'] }}</td>
                            <td class="text-right">
                                <button type="button" class="btn-ghost text-xs" wire:click="resetMailboxPassword('{{ $mailbox['address'] }}')">Reset password</button>
                                <button type="button" class="btn-ghost text-xs"
                                        wire:click="toggleMailbox('{{ $mailbox['address'] }}', {{ $mailbox['disabled'] ? 'false' : 'true' }})">
                                    {{ $mailbox['disabled'] ? 'Enable' : 'Disable' }}
                                </button>
                                <button type="button" class="btn-ghost text-xs text-bad" wire:click="askDeleteMailbox('{{ $mailbox['address'] }}')">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($pendingMailboxDelete)
            <div class="rounded-md border border-warn/40 bg-warn/10 p-4 space-y-3">
                <p class="text-sm text-warn">Delete mailbox <span class="font-mono">{{ $pendingMailboxDelete }}</span>?</p>
                <label class="flex items-center gap-2 text-sm text-zinc-300">
                    <input type="checkbox" wire:model="dropMailOnDelete">
                    Also delete stored mail for this mailbox (cannot be undone)
                </label>
                <div class="flex gap-2">
                    <button type="button" class="btn-primary" wire:click="deleteMailbox">Confirm delete</button>
                    <button type="button" class="btn-ghost" wire:click="cancelDeleteMailbox">Cancel</button>
                </div>
            </div>
        @endif

        <p class="text-xs text-zinc-500">
            No webmail in v1 — connect any IMAP client to
            <span class="font-mono">{{ $hostname ?: 'your mail hostname' }}:993</span> (SSL/TLS), and submit through
            port 587 (STARTTLS) or 465 (implicit TLS). Maximum message size is
            {{ intdiv((int) ($status['max_message_bytes'] ?? 0), 1048576) }} MiB.
        </p>
    </section>

    {{-- Aliases --}}
    <section class="panel p-5 space-y-4">
        <h2 class="text-sm font-medium">Aliases</h2>
        <form wire:submit="addAlias" class="flex flex-wrap items-end gap-3">
            <label class="text-xs uppercase tracking-wide text-zinc-500">Alias
                <input class="field mt-1 w-60" wire:model="newAliasAddress" placeholder="info@example.com">
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">Delivers to
                <input class="field mt-1 w-60" wire:model="newAliasDestination" placeholder="you@example.com">
            </label>
            <label class="flex items-center gap-2 pb-2 text-xs text-zinc-400">
                <input type="checkbox" wire:model="aliasConfirmExternal">
                Allow forwarding off this server
            </label>
            <button type="submit" class="btn-primary">Add alias</button>
        </form>

        @if ($aliases === [])
            <p class="text-sm text-zinc-500">No aliases yet.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left font-mono text-xs uppercase text-zinc-500">
                    <tr><th class="py-2">Alias</th><th>Destination</th><th>Scope</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($aliases as $alias)
                        <tr class="border-t border-white/5">
                            <td class="py-2 font-mono text-zinc-200">{{ $alias['address'] }}</td>
                            <td class="font-mono text-zinc-300">{{ $alias['destination'] }}</td>
                            <td class="{{ $alias['external'] ? 'text-warn' : 'text-zinc-400' }}">{{ $alias['external'] ? 'external' : 'local' }}</td>
                            <td class="text-right">
                                <button type="button" class="btn-ghost text-xs text-bad" wire:click="deleteAlias('{{ $alias['address'] }}')">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- Queue, logs, test send --}}
    <section class="panel p-5 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-medium">Queue and logs</h2>
            <div class="flex gap-2">
                <button type="button" class="btn-ghost text-xs" wire:click="loadQueue">Refresh queue</button>
                <button type="button" class="btn-ghost text-xs" wire:click="flushQueue">Flush deferred</button>
                <button type="button" class="btn-ghost text-xs" wire:click="loadLogs">Load recent log</button>
            </div>
        </div>

        @if ($queue !== [])
            <p class="text-sm text-zinc-300">
                Queued {{ $queue['total'] ?? 0 }} · active {{ $queue['active'] ?? 0 }}
                · deferred {{ $queue['deferred'] ?? 0 }} · held {{ $queue['held'] ?? 0 }}
            </p>
            @if (($queue['sample'] ?? []) !== [])
                <pre class="max-h-48 overflow-auto rounded bg-black/40 p-3 font-mono text-xs text-zinc-400">{{ implode("\n", $queue['sample']) }}</pre>
            @endif
        @endif

        @if ($logLines !== [])
            <pre class="max-h-64 overflow-auto rounded bg-black/40 p-3 font-mono text-xs text-zinc-400">{{ implode("\n", $logLines) }}</pre>
        @endif

        <form wire:submit="sendTest" class="grid gap-3 border-t border-white/5 pt-4 md:grid-cols-4">
            <label class="text-xs uppercase tracking-wide text-zinc-500">From mailbox
                <input class="field mt-1" wire:model="testFrom" placeholder="you@example.com">
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">To
                <input class="field mt-1" wire:model="testTo" placeholder="check@gmail.com">
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">Subject
                <input class="field mt-1" wire:model="testSubject" placeholder="Test">
            </label>
            <div class="flex items-end">
                <button type="submit" class="btn-primary">Send test</button>
            </div>
        </form>
        <p class="text-xs text-zinc-500">
            The test exercises whichever outbound path is active right now
            ({{ $status['delivery_mode'] ?? 'direct' }}) and is DKIM-signed when local signing applies.
        </p>
    </section>
    @endif
</div>
