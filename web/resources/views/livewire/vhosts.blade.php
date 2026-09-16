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
                <input class="field mt-1" wire:model.live.blur="domain" placeholder="app.example.com" required>
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
            <label class="text-xs uppercase tracking-wide text-zinc-500">Runtime intent
                <select class="field mt-1" wire:model.live="createRuntime">
                    <option value="traditional">Traditional (default)</option>
                    @if ($type === 'php')
                        <option value="octane">Laravel Octane</option>
                    @endif
                    @if (in_array($type, ['proxy', 'static'], true))
                        <option value="pm2">PM2 (Node)</option>
                        <option value="docker">Docker (rootless)</option>
                    @endif
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
            <label class="text-xs uppercase tracking-wide text-zinc-500">Engine
                <select class="field mt-1" wire:model="engine" @disabled($type === 'proxy' || $createRuntime === 'docker')>
                    <option value="caddy">Caddy (direct)</option>
                    <option value="apache">Apache (via Caddy)</option>
                    <option value="nginx">Nginx (via Caddy)</option>
                </select>
            </label>
            @if ($createRuntime === 'octane')
                <label class="text-xs uppercase tracking-wide text-zinc-500">Octane max requests
                    <input class="field mt-1" wire:model="octaneMaxRequests" inputmode="numeric" placeholder="500">
                </label>
            @endif
            @if ($createRuntime === 'pm2')
                <label class="text-xs uppercase tracking-wide text-zinc-500">PM2 instances
                    <input class="field mt-1" wire:model="pm2Instances" inputmode="numeric" placeholder="1">
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">PM2 entry (optional)
                    <input class="field mt-1 font-mono text-sm" wire:model="pm2Entry" placeholder="server.js">
                </label>
            @endif
            @if ($createRuntime === 'docker')
                <label class="text-xs uppercase tracking-wide text-zinc-500">Docker mode
                    <select class="field mt-1" wire:model.live="dockerMode">
                        <option value="image">Pull image</option>
                        <option value="compose">Compose file</option>
                        <option value="dockerfile">Dockerfile</option>
                    </select>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">Internal port
                    <input class="field mt-1" wire:model="dockerInternalPort" inputmode="numeric" placeholder="8080">
                </label>
                @if ($dockerMode === 'image')
                    <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">Image
                        <input class="field mt-1 font-mono text-sm" wire:model.live.debounce.400ms="dockerImage" placeholder="nginx:alpine">
                        @if ($dockerImageError)
                            <span class="mt-1 block text-xs text-bad">{{ $dockerImageError }}</span>
                        @endif
                        @if ($dockerImageSuggestions !== [])
                            <ul class="mt-1 max-w-md rounded border border-white/10 bg-ink-900 text-xs">
                                @foreach ($dockerImageSuggestions as $sug)
                                    <li>
                                        <button type="button" class="block w-full px-2 py-1 text-left font-mono hover:bg-white/5"
                                            wire:click="pickDockerImage('{{ $sug['repo_name'] }}')">{{ $sug['repo_name'] }}</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </label>
                @elseif ($dockerMode === 'compose')
                    <label class="text-xs uppercase tracking-wide text-zinc-500">Compose path (optional)
                        <input class="field mt-1 font-mono text-sm" wire:model="dockerCompose" placeholder="docker-compose.yml">
                    </label>
                @else
                    <label class="text-xs uppercase tracking-wide text-zinc-500">Dockerfile path (optional)
                        <input class="field mt-1 font-mono text-sm" wire:model="dockerDockerfile" placeholder="Dockerfile">
                    </label>
                @endif
            @endif
            <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">TLS mode
                <select class="field mt-1" wire:model.live="tlsMode">
                    <option value="off">Off (HTTP only)</option>
                    <option value="auto">Automatic (Let's Encrypt HTTP-01)</option>
                    <option value="dns01">DNS challenge (wildcard / pre-DNS)</option>
                    <option value="internal">Self-signed</option>
                </select>
            </label>
            @if ($tlsMode === 'dns01')
                <label class="text-xs uppercase tracking-wide text-zinc-500">DNS provider
                    <select class="field mt-1" wire:model="dnsProvider">
                        <option value="">Select…</option>
                        @foreach ($dnsProviders as $p)
                            <option value="{{ $p['id'] }}">{{ $p['display_name'] }}{{ !empty($p['credentials_present']) ? ' (token stored)' : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">API token (leave blank to reuse stored)
                    <input class="field mt-1" type="password" autocomplete="new-password" wire:model="dnsToken" placeholder="••••••••">
                </label>
                <label class="flex items-center gap-2 text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">
                    <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="wildcard">
                    Also request wildcard (*.apex)
                </label>
            @endif
            @if (in_array($tlsMode, ['auto', 'dns01'], true))
                <label class="flex items-center gap-2 text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">
                    <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="acmeStaging">
                    Use Let's Encrypt staging (tests — avoids rate limits)
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
            <label class="text-xs uppercase tracking-wide text-zinc-500">Engine
                <select class="field mt-1" wire:model="editEngine" @disabled($editType === 'proxy')>
                    <option value="caddy">Caddy (direct)</option>
                    <option value="apache">Apache (via Caddy)</option>
                    <option value="nginx">Nginx (via Caddy)</option>
                </select>
            </label>
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
                <label class="flex items-center gap-2 text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">
                    <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="editWildcard">
                    Also request wildcard (*.apex)
                </label>
            @endif
            @if (in_array($editTlsMode, ['auto', 'dns01'], true))
            <label class="flex items-center gap-2 text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">
                <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="editAcmeStaging">
                Use Let's Encrypt staging (for tests — avoids rate limits)
            </label>
            @endif
            <div class="flex gap-3 md:col-span-2">
                <button class="btn-primary" type="submit">Validate &amp; save</button>
                <button type="button" class="btn-ghost" wire:click="cancelEdit">Cancel</button>
            </div>
        </form>
    @endif

    @if ($pm2Target)
        <div class="panel border border-warn/40 p-5">
            <p class="text-sm">
                Enable <span class="font-mono text-zinc-200">PM2 (Node cluster)</span> for
                <span class="font-mono">{{ $pm2Target }}</span>?
            </p>
            <p class="mt-2 text-sm text-warn">
                PM2 cluster mode shares one listen port across workers. Reload performs a zero-downtime rolling
                restart; each worker is a fresh Node process afterward (unlike Octane/Laravel long-lived workers).
                Cluster workers do <strong>not</strong> share in-memory session or state — use sticky sessions or an
                external store. Your app must bind to <span class="font-mono">process.env.PORT</span> (and preferably
                127.0.0.1). See
                <a href="https://pm2.keymetrics.io/docs/usage/cluster-mode/" target="_blank" rel="noopener noreferrer" class="underline">PM2 cluster mode</a>
                before serving production traffic — and reload workers after every deploy.
            </p>
            <p class="mt-2 text-xs text-zinc-400">
                Caddy stays the front door and reverse-proxies to a Supervisor-managed <span class="font-mono">pm2-runtime</span>
                worker on loopback (127.0.0.1:36000–36999). Switching off PM2 restores the prior vhost mode.
            </p>
            <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Cluster instances
                <input class="field mt-1 max-w-[12rem]" wire:model="pm2Instances" inputmode="numeric" placeholder="1">
            </label>
            <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Entry script (optional)
                <input class="field mt-1 max-w-md font-mono text-sm" wire:model="pm2Entry" placeholder="server.js">
            </label>
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="enablePm2">Enable PM2</button>
                <button class="btn-ghost" wire:click="cancelPm2">Cancel</button>
            </div>
        </div>
    @endif

    @if ($pm2ScaleTarget)
        <div class="panel border border-accent/30 p-5">
            <p class="text-sm">
                Scale PM2 workers for <span class="font-mono">{{ $pm2ScaleTarget }}</span>
            </p>
            <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Cluster instances
                <input class="field mt-1 max-w-[12rem]" wire:model="pm2Instances" inputmode="numeric" placeholder="1">
            </label>
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="scalePm2">Apply scale</button>
                <button class="btn-ghost" wire:click="cancelPm2">Cancel</button>
            </div>
        </div>
    @endif

    @if ($dockerTarget)
        <div class="panel border border-warn/40 p-5">
            <p class="text-sm">
                Enable <span class="font-mono text-zinc-200">Docker (rootless)</span> for
                <span class="font-mono">{{ $dockerTarget }}</span>?
            </p>
            <p class="mt-2 text-sm text-warn">
                Rootless Docker only — the daemon runs as <span class="font-mono">azerioid-supervised</span>, never via the
                <span class="font-mono">docker</span> group or rootful <span class="font-mono">docker.service</span>.
                Ports publish as <span class="font-mono">127.0.0.1:37000–37999</span> only. Files and Terminal use the
                host docroot (build context), not the container filesystem. See
                <a href="https://docs.docker.com/engine/security/rootless/" target="_blank" rel="noopener noreferrer" class="underline">Docker rootless docs</a>.
            </p>
            <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Mode
                <select class="field mt-1 max-w-xs" wire:model.live="dockerMode">
                    <option value="image">Pull image</option>
                    <option value="compose">Compose file</option>
                    <option value="dockerfile">Dockerfile</option>
                </select>
            </label>
            <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Internal port (container listen)
                <input class="field mt-1 max-w-[12rem]" wire:model="dockerInternalPort" inputmode="numeric" placeholder="8080">
            </label>
            @if ($dockerMode === 'image')
                <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Image
                    <input class="field mt-1 max-w-md font-mono text-sm" wire:model.live.debounce.400ms="dockerImage" placeholder="nginx:alpine">
                    @if ($dockerImageError)
                        <span class="mt-1 block text-xs text-bad">{{ $dockerImageError }}</span>
                    @endif
                    @if ($dockerImageSuggestions !== [])
                        <ul class="mt-1 max-w-md rounded border border-white/10 bg-ink-900 text-xs">
                            @foreach ($dockerImageSuggestions as $sug)
                                <li>
                                    <button type="button" class="block w-full px-2 py-1 text-left font-mono hover:bg-white/5"
                                        wire:click="pickDockerImage('{{ $sug['repo_name'] }}')">{{ $sug['repo_name'] }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </label>
            @elseif ($dockerMode === 'compose')
                <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Compose path (optional)
                    <input class="field mt-1 max-w-md font-mono text-sm" wire:model="dockerCompose" placeholder="docker-compose.yml">
                </label>
            @else
                <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Dockerfile path (optional)
                    <input class="field mt-1 max-w-md font-mono text-sm" wire:model="dockerDockerfile" placeholder="Dockerfile">
                </label>
            @endif
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="enableDocker">Enable Docker</button>
                <button class="btn-ghost" wire:click="cancelDocker">Cancel</button>
            </div>
        </div>
    @endif

    @if ($octaneTarget)
        <div class="panel border border-warn/40 p-5">
            <p class="text-sm">
                Enable <span class="font-mono text-zinc-200">Laravel Octane (FrankenPHP)</span> for
                <span class="font-mono">{{ $octaneTarget }}</span>?
            </p>
            <p class="mt-2 text-sm text-warn">
                Octane keeps your application booted between requests. Constructors, static properties, and
                singletons are <strong>not</strong> reset per request, so code that relies on a fresh boot can leak
                state between visitors. Review
                <a href="https://laravel.com/docs/octane" target="_blank" rel="noopener noreferrer" class="underline">laravel.com/docs/octane</a>
                before serving production traffic — and reload the workers after every deploy.
            </p>
            <p class="mt-2 text-xs text-zinc-400">
                Caddy stays the front door and reverse-proxies to a Supervisor-managed worker on loopback
                (127.0.0.1:34000–34999). Switching back to PHP-FPM is one click.
            </p>
            <label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Max requests per worker
                <input class="field mt-1 max-w-[12rem]" wire:model="octaneMaxRequests" inputmode="numeric" placeholder="500">
            </label>
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="enableOctane">Enable Octane</button>
                <button class="btn-ghost" wire:click="cancelOctane">Cancel</button>
            </div>
        </div>
    @endif

    <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
        <label class="min-w-[12rem] flex-1 text-xs uppercase tracking-wide text-zinc-500">Search
            <input class="field mt-1" type="search" wire:model.live.debounce.250ms="listSearch" placeholder="Filter by domain…">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500 lg:w-36">Type
            <select class="field mt-1" wire:model.live="filterType">
                <option value="">All</option>
                <option value="php">PHP</option>
                <option value="static">Static</option>
                <option value="proxy">Proxy</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500 lg:w-36">Engine
            <select class="field mt-1" wire:model.live="filterEngine">
                <option value="">All</option>
                <option value="caddy">Caddy</option>
                <option value="apache">Apache</option>
                <option value="nginx">Nginx</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500 lg:w-40">Runtime
            <select class="field mt-1" wire:model.live="filterRuntime">
                <option value="">All</option>
                <option value="php-fpm">PHP-FPM</option>
                <option value="static">Static</option>
                <option value="proxy">Proxy</option>
                <option value="octane">Octane</option>
                <option value="pm2">PM2</option>
                <option value="docker">Docker</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500 lg:w-36">Status
            <select class="field mt-1" wire:model.live="filterStatus">
                <option value="">All</option>
                <option value="healthy">Healthy</option>
                <option value="failed">Failed</option>
                <option value="pending">Pending</option>
                <option value="disabled">Disabled</option>
            </select>
        </label>
        @if ($listSearch !== '' || $filterType !== '' || $filterEngine !== '' || $filterRuntime !== '' || $filterStatus !== '')
            <button type="button" class="btn-ghost text-xs" wire:click="clearListFilters">Clear filters</button>
        @endif
    </div>

    <div class="panel overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="font-mono text-[11px] uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-3 py-3">Domain</th>
                    <th class="px-3 py-3">Type</th>
                    <th class="px-3 py-3">Engine</th>
                    <th class="px-3 py-3">Root / upstream</th>
                    <th class="px-3 py-3">PHP</th>
                    <th class="px-3 py-3">Runtime</th>
                    <th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3">TLS</th>
                    <th class="w-12 px-3 py-3 text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                @forelse ($filteredVhosts as $v)
                    @php
                        $status = $this->vhostStatus($v);
                        $runtimeBadge = $this->vhostRuntimeBadge($v);
                        $tls = $this->vhostTlsDisplay($v);
                        $rootLabel = $this->vhostRootLabel($v);
                        $actionGroups = $this->vhostActionGroups($v);
                    @endphp
                    <tr wire:key="vhost-{{ $v['domain'] }}">
                        <td class="max-w-[11rem] px-3 py-3 font-mono text-xs">
                            <span class="block truncate" title="{{ $v['domain'] }}">{{ $v['domain'] }}</span>
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex flex-wrap items-center gap-1">
                                {{ $v['type'] }}
                                @if (!empty($v['readonly']))
                                    <x-badge>read-only</x-badge>
                                @endif
                            </span>
                        </td>
                        <td class="px-3 py-3 font-mono text-xs uppercase text-zinc-300">{{ $v['engine'] ?? 'caddy' }}</td>
                        <td class="max-w-[10rem] px-3 py-3 font-mono text-xs text-zinc-400">
                            <span class="block truncate" title="{{ $rootLabel }}">{{ $rootLabel }}</span>
                        </td>
                        <td class="px-3 py-3 font-mono text-xs">{{ $v['php_version'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-xs">
                            <div class="flex max-w-[9rem] flex-col items-start gap-0.5">
                                <x-badge :tone="$runtimeBadge['tone']">{{ $runtimeBadge['label'] }}</x-badge>
                                @if (!empty($runtimeBadge['detail']))
                                    <span class="max-w-full truncate font-mono text-[10px] text-zinc-500" title="{{ $runtimeBadge['detail'] }}">{{ $runtimeBadge['detail'] }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <x-status-badge :state="$status['state']" :label="$status['label']" :detail="$status['detail']" />
                        </td>
                        <td class="max-w-[9rem] px-3 py-3 text-xs">
                            <div class="truncate font-mono text-zinc-200" title="{{ $tls['primary'] }}">{{ $tls['primary'] }}</div>
                            @if (!empty($tls['secondary']))
                                <div class="mt-0.5 truncate font-mono text-[10px] text-zinc-500" title="{{ $tls['secondary'] }}">{{ $tls['secondary'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right">
                            <x-row-actions-menu :groups="$actionGroups" :label="'Actions for '.$v['domain']" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-zinc-500">
                        @if ($vhosts === [])
                            No virtual hosts found.
                        @else
                            No virtual hosts match the current filters.
                        @endif
                    </td></tr>
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
            @if (isset($mailDomains[$confirmDelete]))
                <p class="mt-2 text-sm text-warn">
                    Mail is enabled for this domain
                    ({{ $mailDomains[$confirmDelete] }} {{ \Illuminate\Support\Str::plural('mailbox', $mailDomains[$confirmDelete]) }}).
                    Deleting the vhost removes the mailboxes and every stored message.
                </p>
                <label class="mt-3 flex items-center gap-2 text-sm text-zinc-400">
                    <input type="checkbox" class="rounded border-white/10 bg-ink-800" wire:model="dropMailOnDelete">
                    Also delete mailboxes and stored mail for this domain
                </label>
            @endif
            <div class="mt-3 flex gap-2">
                <button class="btn-primary" wire:click="delete">Confirm delete</button>
                <button class="btn-ghost" wire:click="$set('confirmDelete', null)">Cancel</button>
            </div>
        </div>
    @endif
</div>
