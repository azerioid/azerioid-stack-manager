<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    <form wire:submit="saveProfile" class="panel grid gap-4 p-5 md:grid-cols-2">
        <h2 class="md:col-span-2 text-sm font-medium">Admin profile</h2>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Name
            <input class="field mt-1" wire:model="name">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Email
            <input class="field mt-1" type="email" wire:model="email">
        </label>
        <div><button class="btn-primary" type="submit">Save profile</button></div>
    </form>

    <form wire:submit="savePassword" class="panel grid gap-4 p-5 md:grid-cols-2">
        <h2 class="md:col-span-2 text-sm font-medium">Change password</h2>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Current
            <input class="field mt-1" type="password" wire:model="current_password">
        </label>
        <span></span>
        <label class="text-xs uppercase tracking-wide text-zinc-500">New
            <input class="field mt-1" type="password" wire:model="password">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Confirm
            <input class="field mt-1" type="password" wire:model="password_confirmation">
        </label>
        @error('current_password') <p class="text-sm text-bad">{{ $message }}</p> @enderror
        @error('password') <p class="text-sm text-bad md:col-span-2">{{ $message }}</p> @enderror
        <div><button class="btn-primary" type="submit">Update password</button></div>
    </form>

    <section class="panel p-5 space-y-2">
        <h2 class="text-sm font-medium">Two-factor (TOTP)</h2>
        @if ($totpRequired)
            <p class="text-sm text-zinc-400">Required for admin login (PANEL_REQUIRE_TOTP). Reinstall with <span class="font-mono">--require-totp=false</span> to allow password-only.</p>
            <p class="text-sm {{ $totpEnrolled ? 'text-good' : 'text-warn' }}">{{ $totpEnrolled ? 'This account is enrolled.' : 'This account is not enrolled yet.' }}</p>
        @else
            <p class="text-sm text-zinc-400">Disabled: admins log in with password only. Optional enrollment is available.</p>
            <p class="text-sm text-warn">If this panel is internet-facing, reinstall with <span class="font-mono">--require-totp=true</span>.</p>
            @unless ($totpEnrolled)
                <a class="btn-ghost inline-block text-xs" href="{{ route('two-factor.setup') }}">Enroll authenticator (optional)</a>
            @else
                <p class="text-sm text-good">This account is enrolled; login still asks for a code.</p>
            @endif
        @endif
    </section>

    <section class="panel p-5 space-y-3">
        <h2 class="text-sm font-medium">Panel runtime</h2>
        @if ($panelRuntime !== [])
            <dl class="grid gap-2 font-mono text-xs text-zinc-400 md:grid-cols-2">
                <div><dt class="text-zinc-600">PHP version</dt><dd>{{ $panelRuntime['php_version'] ?? '—' }}</dd></div>
                <div><dt class="text-zinc-600">FPM pool</dt><dd>{{ $panelRuntime['fpm_pool'] ?? '—' }}</dd></div>
                <div><dt class="text-zinc-600">FPM socket</dt><dd>{{ $panelRuntime['fpm_socket'] ?? '—' }}</dd></div>
                <div><dt class="text-zinc-600">Queue worker</dt><dd>{{ ($panelRuntime['queue_active'] ?? false) ? 'active' : 'inactive' }} ({{ $panelRuntime['queue_unit'] ?? 'azerioid-panel-queue.service' }})</dd></div>
            </dl>
            <p class="text-sm text-zinc-500">System component — cannot be removed while the panel is running.</p>
        @else
            <p class="text-sm text-zinc-400">Panel runtime details unavailable (broker panel.runtime).</p>
        @endif
    </section>

    <form wire:submit="savePanelDomain" class="panel grid gap-4 p-5">
        <h2 class="text-sm font-medium">Panel domain</h2>
        <p class="text-sm text-zinc-500">Optional white-label hostname on :443 with Let's Encrypt. <span class="font-mono">IP:{{ $panelRuntime['public_ip'] ?? 'host' }}:3169</span> and the SSH tunnel always keep working. Unrelated site domains never match the panel port.</p>
        <p class="font-mono text-xs text-zinc-500">
            Current:
            @if (!empty($panelRuntime['domain']))
                https://{{ $panelRuntime['domain'] }}
            @else
                not set (IP / tunnel)
            @endif
        </p>
        @if (!empty($panelRuntime['fallback_urls']))
            <p class="font-mono text-[11px] text-zinc-600">Fallback: {{ implode(' · ', $panelRuntime['fallback_urls']) }}</p>
        @endif
        @php $ts = $panelRuntime['tls_status'] ?? null; @endphp
        @if (is_array($ts))
            <p class="text-xs uppercase tracking-wide {{ !empty($ts['ok']) ? 'text-good' : 'text-warn' }}">
                TLS {{ $ts['issuer_type'] ?? $panelTlsMode }} @if (!empty($ts['error'])) · {{ $ts['error'] }} @endif
            </p>
        @endif
        <label class="text-xs uppercase tracking-wide text-zinc-500">Hostname
            <input class="field mt-1 max-w-md" wire:model="panelDomain" placeholder="panel.example.com">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">TLS
            <select class="field mt-1 max-w-xs" wire:model="panelTlsMode">
                <option value="auto">Let's Encrypt (HTTP-01)</option>
                <option value="dns01">DNS-01</option>
                <option value="internal">Self-signed</option>
            </select>
        </label>
        @error('panelDomain') <p class="text-sm text-bad">{{ $message }}</p> @enderror
        @if ($panelDomainError)
            <p class="text-sm text-bad">{{ $panelDomainError }}</p>
        @endif
        <div class="flex flex-wrap gap-2">
            <button class="btn-primary" type="submit">Save domain</button>
            <button class="btn-ghost" type="button" wire:click="clearPanelDomain">Clear</button>
        </div>
    </form>

    <form wire:submit="savePanel" class="panel grid gap-4 p-5">
        <h2 class="text-sm font-medium">Session &amp; access</h2>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Idle timeout (minutes)
            <input class="field mt-1 max-w-xs" type="number" wire:model="idle" min="5" max="120">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Audit retention (days)
            <input class="field mt-1 max-w-xs" type="number" wire:model="retention" min="7" max="365">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">IP allowlist (one per line, empty = any)
            <textarea class="field mt-1 h-28" wire:model="ipAllowlist" placeholder="127.0.0.1"></textarea>
        </label>
        @error('ipAllowlist') <p class="text-sm text-bad">{{ $message }}</p> @enderror
        <div><button class="btn-primary" type="submit">Save</button></div>
    </form>

    <section class="panel p-5 space-y-3">
        <h2 class="text-sm font-medium">DNS provider credentials (TLS DNS-01)</h2>
        <p class="text-sm text-zinc-400">API tokens for Cloudflare / DigitalOcean certbot plugins. Stored root-only under <span class="font-mono">/etc/azerioid-panel/dns-credentials/</span>. Existing secrets are never shown — enter a new token only to replace.</p>
        <ul class="space-y-1 font-mono text-xs text-zinc-400">
            @forelse ($dnsProviders as $p)
                <li>
                    {{ $p['display_name'] ?? $p['id'] }} —
                    @if (!empty($p['credentials_present']))
                        <span class="text-good">token stored</span>
                    @else
                        <span class="text-zinc-500">not configured</span>
                    @endif
                </li>
            @empty
                <li class="text-zinc-500">Providers unavailable (broker tls.dns-providers).</li>
            @endforelse
        </ul>
        <form wire:submit="rotateDnsCredential" class="grid gap-3 md:grid-cols-2">
            <label class="text-xs uppercase tracking-wide text-zinc-500">Provider
                <select class="field mt-1" wire:model="dnsRotateProvider">
                    <option value="">Select…</option>
                    @foreach ($dnsProviders as $p)
                        <option value="{{ $p['id'] }}">{{ $p['display_name'] ?? $p['id'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">New API token
                <input class="field mt-1" type="password" autocomplete="new-password" wire:model="dnsRotateToken" placeholder="••••••••">
            </label>
            @if ($dnsError)
                <p class="text-sm text-bad md:col-span-2">{{ $dnsError }}</p>
            @endif
            <div class="md:col-span-2">
                <button class="btn-primary" type="submit">Store / replace token</button>
            </div>
        </form>
    </section>

    <section class="panel p-5 space-y-2">
        <h2 class="text-sm font-medium">Integrations</h2>
        <p class="text-sm text-zinc-400">Telegram bot token, Spaces keys, and the backup passphrase are entered on <a class="text-brass-400" href="{{ route('alerts') }}">Alerts</a> and <a class="text-brass-400" href="{{ route('backups') }}">Backups</a>. They are encrypted with <span class="font-mono">APP_KEY</span>, masked after save, and never written to the audit log in plaintext.</p>
    </section>

    @if ($phpIni !== [])
        <div class="panel p-5">
            <h2 class="mb-3 text-sm font-medium">PHP {{ $phpVersion }} · allowlisted php.ini keys</h2>
            @error('phpIni') <p class="mb-2 text-sm text-bad">{{ $message }}</p> @enderror
            <dl class="grid gap-3 md:grid-cols-2">
                @foreach ($phpIni as $key => $value)
                    <form wire:submit="saveIni('{{ $key }}', $event.target.querySelector('input').value)" class="flex items-end gap-2">
                        <label class="flex-1 text-xs uppercase tracking-wide text-zinc-500">{{ $key }}
                            <input class="field mt-1" name="value" value="{{ $value }}">
                        </label>
                        <button class="btn-ghost text-xs" type="submit">Set</button>
                    </form>
                @endforeach
            </dl>
            <div class="mt-4 border-t border-white/5 pt-4">
                <h3 class="text-sm font-medium">OPcache</h3>
                <p class="mt-1 font-mono text-xs text-zinc-500">{{ $opcache['error'] ?? ($opcache['available'] ?? false ? 'available' : 'not available') }}</p>
                @if (!empty($opcache['raw']))
                    <pre class="mt-2 max-h-40 overflow-auto font-mono text-[10px] text-zinc-400">{{ $opcache['raw'] }}</pre>
                @endif
                <button type="button" class="btn-ghost mt-3 text-xs" wire:click="resetOpcache" wire:confirm="Reset OPcache for PHP {{ $phpVersion }}?">Reset OPcache</button>
            </div>
        </div>
    @endif
</div>
