{{-- A111 — per-site Redis users. Every rule is enforced by the broker and Redis itself: each site's
     user reaches only keys and channels under its own prefix and none of the instance-wide commands. --}}
<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif

    @if (! ($state['installed'] ?? false))
        <div class="panel p-5 text-sm text-zinc-400">
            Redis is not installed. Install it from <a class="text-brass-400 hover:underline" href="/components">Components</a>;
            a new install allows per-site users only.
        </div>
    @else
        <div class="panel p-5">
            <h2 class="text-sm font-medium">Access</h2>
            @if ($state['enforced'] ?? false)
                <p class="mt-1 text-xs text-good">Per-site users only. A client without credentials is refused.</p>
            @else
                <p class="mt-2 rounded-md border border-warn/40 bg-warn/10 px-3 py-2 text-xs text-warn">
                    Redis still answers clients without a password, so every site can read and change every other
                    site's keys. Enable Redis below for each site that uses it, put its lines in that site's
                    <span class="font-mono">.env</span>, then enforce per-site users. Sites still connecting without a
                    password stop working at that moment.
                </p>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <label class="block text-xs uppercase tracking-wide text-zinc-500">
                        Type <span class="font-mono normal-case text-zinc-300">REDIS-PER-SITE</span>
                        <input type="text" class="field mt-1 w-56 font-mono" wire:model="confirm" autocomplete="off">
                    </label>
                    <button type="button" class="btn-primary" wire:click="enforce">Enforce per-site users</button>
                </div>
            @endif
        </div>

        <div class="panel p-5">
            <h2 class="text-sm font-medium">Sites</h2>
            @if ($vhosts === [])
                <p class="mt-4 text-sm text-zinc-500">No sites yet.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-zinc-500">
                            <tr>
                                <th class="px-2 py-2 font-medium">Site</th>
                                <th class="px-2 py-2 font-medium">Redis</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($vhosts as $v)
                                @php($domain = $v['domain'] ?? '')
                                @php($on = $this->enabledFor($domain))
                                <tr wire:key="redis-{{ $domain }}" class="align-top">
                                    <td class="px-2 py-2 text-zinc-200">{{ $domain }}</td>
                                    <td class="px-2 py-2 {{ $on ? 'text-good' : 'text-zinc-500' }}">{{ $on ? 'own user' : 'off' }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">
                                        @if ($on)
                                            <button type="button" class="text-brass-400 hover:underline" wire:click="show(@js($domain))">
                                                {{ ($shown['domain'] ?? null) === $domain ? 'Hide' : 'Connection' }}
                                            </button>
                                            <button type="button" class="ml-2 text-zinc-400 hover:underline" wire:click="disable(@js($domain))"
                                                    wire:confirm="Remove this site's Redis user? The site loses access to its keys immediately.">Disable</button>
                                        @else
                                            <button type="button" class="text-brass-400 hover:underline" wire:click="enable(@js($domain))">Enable</button>
                                        @endif
                                    </td>
                                </tr>
                                @if (($shown['domain'] ?? null) === $domain)
                                    <tr wire:key="redis-env-{{ $domain }}">
                                        <td colspan="3" class="bg-ink-900/60 px-4 py-3">
                                            <pre class="select-all whitespace-pre font-mono text-[11px] text-zinc-200">{{ $this->envLines() }}</pre>
                                            <p class="mt-2 text-[11px] text-zinc-500">
                                                Laravel reads these as they are. Every key the site writes must start with
                                                <span class="font-mono">{{ $shown['prefix'] ?? '' }}</span> — <span class="font-mono">REDIS_PREFIX</span>
                                                does that for cache, sessions and queues. Commands that act on the whole instance
                                                (<span class="font-mono">FLUSHDB</span>, <span class="font-mono">KEYS</span>, …) are refused, so
                                                <span class="font-mono">cache:clear</span> on a Redis store fails; clear with cache tags or by prefix.
                                            </p>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
