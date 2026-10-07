<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg">Git deploy · <span class="font-mono">{{ $domain }}</span></h1>
        <a href="/vhosts" class="btn-ghost">Back to vhosts</a>
    </div>
    @if ($error)<div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>@endif
    @if ($flash)<div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>@endif

    <section class="panel space-y-3 p-5">
        <p class="text-xs text-zinc-500">
            The panel fetches the repository with its own deploy key, then checks it out into the site's folder
            <strong>as the site</strong> and runs the command below as the site — never as root. Deploys happen in place:
            for a moment during a checkout the site serves a mix of old and new files. There are no webhooks; deploy
            by hand or on a schedule.
        </p>
        <div class="grid gap-3 md:grid-cols-2">
            <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">Repository
                <input class="field mt-1 font-mono text-sm" wire:model="repository" placeholder="git@github.com:owner/repo.git">
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">Branch
                <input class="field mt-1 font-mono text-sm" wire:model="branch" placeholder="main">
            </label>
            <label class="text-xs uppercase tracking-wide text-zinc-500">After checkout
                <select class="field mt-1" wire:model.live="preset">
                    <option value="none">Nothing</option>
                    <option value="composer">composer install --no-dev</option>
                    <option value="laravel">Laravel: composer install, migrate --force, optimize</option>
                    <option value="npm">npm ci &amp;&amp; npm run build</option>
                    <option value="custom">Custom command…</option>
                </select>
            </label>
            @if ($preset === 'custom')
                <label class="text-xs uppercase tracking-wide text-zinc-500 md:col-span-2">Command (runs as the site, in its folder)
                    <input class="field mt-1 font-mono text-sm" wire:model="command">
                </label>
                <label class="text-xs uppercase tracking-wide text-zinc-500">Type RUN-AS-SITE to allow a custom command
                    <input class="field mt-1 font-mono text-sm" wire:model="confirm">
                </label>
            @endif
            <label class="text-xs uppercase tracking-wide text-zinc-500">Schedule
                <select class="field mt-1" wire:model.live="scheduleMode">
                    <option value="off">Off (manual only)</option>
                    <option value="hourly">Every hour, if the branch moved</option>
                    <option value="daily">Daily, if the branch moved</option>
                </select>
            </label>
            @if ($scheduleMode === 'daily')
                <label class="text-xs uppercase tracking-wide text-zinc-500">Hour (0–23)
                    <input class="field mt-1" type="number" min="0" max="23" wire:model="scheduleHour">
                </label>
            @endif
        </div>
        <button class="btn-primary" type="button" wire:click="save">Save</button>
    </section>

    @if ($publicKey)
        <section class="panel space-y-2 p-5">
            <h2 class="text-sm font-medium">Deploy key</h2>
            <p class="text-xs text-zinc-500">Add this as a read-only deploy key at the repository. The private half stays on this server, readable by root only.</p>
            <pre class="overflow-x-auto rounded bg-black/30 p-3 font-mono text-xs">{{ $publicKey }}</pre>
            <button class="btn-ghost" type="button" wire:click="rotateKey" wire:confirm="Create a new key? Deploys fail until you replace the old key at the repository.">New key</button>
        </section>
    @endif

    @if ($configured && $this->webhookUrl())
        <section class="panel space-y-2 p-5">
            <h2 class="text-sm font-medium">Push-to-deploy webhook</h2>
            <p class="text-xs text-zinc-500">Add a webhook at the repository (GitHub: Payload URL + Secret, content type <span class="font-mono">application/json</span>; GitLab: URL + Secret token). A push to <span class="font-mono">{{ $branch }}</span> then deploys automatically.</p>
            <label class="block text-xs text-zinc-500">Payload URL</label>
            <pre class="overflow-x-auto rounded bg-black/30 p-3 font-mono text-xs">{{ $this->webhookUrl() }}</pre>
            @if ($webhookSecret)
                <label class="block text-xs text-zinc-500">Secret <span class="text-warn">— shown once; copy it now, then rotate for a new one.</span></label>
                <pre class="overflow-x-auto rounded bg-black/30 p-3 font-mono text-xs">{{ $webhookSecret }}</pre>
            @else
                <p class="text-xs text-zinc-500">A secret is set. It is shown only once when created — use <span class="font-mono">New secret</span> to generate a fresh one if you no longer have it.</p>
            @endif
            <button class="btn-ghost" type="button" wire:click="rotateWebhook" wire:confirm="Create a new webhook secret? The old secret stops working until you update it at the repository.">New secret</button>
        </section>
    @endif

    @if ($configured)
        <section class="panel space-y-3 p-5">
            <div class="flex flex-wrap gap-2">
                <button class="btn-primary" type="button" wire:click="deployNow">Deploy now</button>
                <button class="btn-ghost" type="button" wire:click="rollback" wire:confirm="Check out the previous deployed commit and run the command again? The database is not rolled back.">Roll back</button>
                <button class="btn-ghost" type="button" wire:click="refresh">Refresh</button>
                <button class="btn-danger" type="button" wire:click="remove" wire:confirm="Remove deploy settings, the key and the mirror? The site's files stay as they are.">Remove</button>
            </div>
            <p class="text-xs text-zinc-500">Deployed: <span class="font-mono">{{ isset($state['current']) ? substr($state['current'], 0, 12) : 'nothing yet' }}</span>
                @if (isset($state['previous'])) · previous <span class="font-mono">{{ substr($state['previous'], 0, 12) }}</span>@endif</p>
            <div class="overflow-hidden rounded border border-white/5">
                @forelse (($state['history'] ?? []) as $h)
                    <div class="flex flex-wrap justify-between gap-2 px-4 py-2 font-mono text-xs">
                        <span class="{{ $h['status'] === 'ok' ? 'text-good' : 'text-bad' }}">{{ substr($h['commit'], 0, 12) }} · {{ $h['trigger'] }}</span>
                        <span class="text-zinc-500">{{ $h['at'] }}{{ $h['error'] ? ' · '.\Illuminate\Support\Str::limit($h['error'], 90) : '' }}</span>
                    </div>
                @empty
                    <p class="px-4 py-3 text-xs text-zinc-500">No deploys yet.</p>
                @endforelse
            </div>
        </section>
    @endif
</div>
