{{-- B2 / request #2 — firewall rules. Every refusal here comes from the broker (A46);
     nothing on this page is hidden in order to make it safe. --}}
<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif

    @php($revert = $this->revert())
    @if ($revert['armed'] ?? false)
        <div class="panel border-brass-400/40 bg-brass-400/5 p-5">
            <h2 class="text-sm font-medium text-brass-400">A change is waiting on you</h2>
            <p class="mt-1 text-sm text-zinc-300">
                <span class="font-mono">{{ $revert['description'] ?? 'firewall change' }}</span> is applied.
                If you do not confirm it by <span class="font-mono">{{ $revert['deadline'] ?? '' }}</span>
                the previous rules are restored automatically.
            </p>
            <p class="mt-1 text-xs text-zinc-500">
                You are reading this, so you can still reach the panel — that is the whole test. Confirm it.
            </p>
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" type="button" wire:click="confirmChange">Confirm, keep the change</button>
                <button class="btn-ghost" type="button" wire:click="revertChange">Revert it now</button>
            </div>
        </div>
    @endif

    <div class="panel p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-medium">Rules</h2>
            <p class="text-xs text-zinc-500">
                backend: <span class="font-mono text-zinc-300">{{ $state['backend'] ?? 'none' }}</span>
                @if (($state['managed_count'] ?? 0) > 0)
                    · {{ $state['managed_count'] }} written by the panel
                @endif
            </p>
        </div>

        @if ($this->rules() === [])
            <p class="mt-4 text-sm text-zinc-500">
                No rules to show. If no firewall is active on this host, install and enable ufw
                (Debian/Ubuntu) or firewalld (EL) first — adding rules to an inactive firewall
                would report success while changing nothing.
            </p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-zinc-500">
                        <tr>
                            <th class="px-2 py-2 font-medium">Action</th>
                            <th class="px-2 py-2 font-medium">Port</th>
                            <th class="px-2 py-2 font-medium">From</th>
                            <th class="px-2 py-2 font-medium">Owner</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($this->rules() as $rule)
                            @php($protected = in_array($rule['port'], array_merge(
                                $state['protected_ports']['ssh'] ?? [],
                                [$state['protected_ports']['panel'] ?? 0],
                                $state['protected_ports']['web'] ?? []
                            ), true))
                            <tr wire:key="fw-{{ $rule['action'] }}-{{ $rule['port'] }}-{{ $rule['protocol'] }}-{{ $rule['source'] ?? 'any' }}">
                                <td class="px-2 py-2 {{ $rule['action'] === 'deny' ? 'text-bad' : 'text-good' }}">{{ $rule['action'] }}</td>
                                <td class="px-2 py-2 font-mono text-zinc-200">{{ $rule['port'] }}/{{ $rule['protocol'] }}</td>
                                <td class="px-2 py-2 font-mono text-zinc-400">{{ $rule['source'] ?? 'anywhere' }}</td>
                                <td class="px-2 py-2 text-zinc-500">
                                    @if ($rule['managed'])
                                        <span class="font-mono text-[11px]">{{ $rule['comment'] }}</span>
                                    @else
                                        <span class="text-[11px]">yours (not panel-managed)</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2 text-right">
                                    @if ($protected)
                                        <span class="text-[11px] text-zinc-500" title="The broker refuses to close this port.">protected</span>
                                    @else
                                        <button type="button" class="text-bad hover:underline"
                                                wire:click="remove('{{ $rule['action'] }}', {{ $rule['port'] }}, '{{ $rule['protocol'] }}', @js($rule['source']))"
                                                wire:confirm="Remove this rule?">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="panel grid gap-4 p-5 md:grid-cols-2">
        <h2 class="md:col-span-2 text-sm font-medium">Add a rule</h2>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Action
            <select class="field mt-1" wire:model="action">
                <option value="allow">allow</option>
                <option value="deny">deny</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Port
            <input class="field mt-1" wire:model="port" inputmode="numeric" placeholder="8443">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Protocol
            <select class="field mt-1" wire:model="protocol">
                <option value="tcp">tcp</option>
                <option value="udp">udp</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">From (IP or CIDR, blank = anywhere)
            <input class="field mt-1 font-mono text-sm" wire:model="source" placeholder="10.1.0.0/16">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Label (optional)
            <input class="field mt-1" wire:model="note" placeholder="office">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Revert after (seconds)
            <input class="field mt-1 max-w-[10rem]" wire:model="revertAfter" inputmode="numeric" placeholder="120">
        </label>
        <label class="md:col-span-2 flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="withoutRevert">
            Apply without an automatic revert (needs the typed confirmation)
        </label>
        @if ($withoutRevert)
            <label class="md:col-span-2 text-xs uppercase tracking-wide text-zinc-500">
                Type <span class="font-mono normal-case">I-HAVE-CONSOLE-ACCESS</span>
                <input class="field mt-1 max-w-md font-mono text-sm" wire:model="confirm" placeholder="I-HAVE-CONSOLE-ACCESS">
            </label>
        @endif
        <div class="md:col-span-2">
            <button class="btn-primary" type="button" wire:click="add">Add rule</button>
        </div>
    </div>

    <p class="text-xs text-zinc-500">
        SSH (read from this host's own <span class="font-mono">sshd_config</span>), the panel port and
        80/443 cannot be denied or have their allow rule removed from here — the broker refuses it, not
        just this page. Rules the panel wrote for a database, mail or site serving are changed where
        that feature lives, since deleting them here would leave the feature believing it is still
        reachable. Anything else on the host is listed but never rewritten.
    </p>
</div>
