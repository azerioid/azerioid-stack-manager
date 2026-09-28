{{-- Docker workload settings (A50): shared by the enable and the container-settings panels. --}}
@if ($compose)
    <div class="mt-3">
        <span class="block text-xs uppercase tracking-wide text-zinc-500">Service that serves the site</span>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            @if ($dockerServices !== [])
                <select class="field max-w-xs font-mono text-sm" wire:model="dockerService">
                    <option value="">— choose —</option>
                    @foreach ($dockerServices as $svc)
                        <option value="{{ $svc }}">{{ $svc }}</option>
                    @endforeach
                </select>
            @else
                <input class="field max-w-xs font-mono text-sm" wire:model="dockerService" placeholder="web">
            @endif
            <button type="button" class="btn-ghost" wire:click="loadDockerServices">Load services</button>
        </div>
        <span class="mt-1 block text-xs text-zinc-500">The site's port is published on this service only. With one service it is chosen for you.</span>
    </div>
@endif
<label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Restart policy
    <select class="field mt-1 max-w-xs" wire:model="dockerRestart">
        <option value="always">Always restart</option>
        <option value="on-failure">Restart only after a failure</option>
        <option value="never">Never restart</option>
    </select>
</label>
<label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Private registry
    <select class="field mt-1 max-w-xs" wire:model="dockerRegistry">
        <option value="">Public images (no login)</option>
        @foreach ($dockerRegistries as $reg)
            <option value="{{ $reg['name'] }}">{{ $reg['name'] }} — {{ $reg['username'] }}@{{ $reg['host'] }}</option>
        @endforeach
    </select>
</label>
<details class="mt-2 text-sm">
    <summary class="cursor-pointer text-xs text-zinc-400">Manage saved registries</summary>
    <p class="mt-2 text-xs text-zinc-500">
        Stored root-only on the host and used only for the moment of a pull; never written to the shared Docker account.
        Docker Hub, GHCR, GitLab, Harbor and other standard registries. Amazon ECR is not supported.
    </p>
    @if ($dockerRegistries !== [])
        <ul class="mt-2 space-y-1">
            @foreach ($dockerRegistries as $reg)
                <li class="flex items-center gap-2 font-mono text-xs">
                    <span>{{ $reg['name'] }} — {{ $reg['username'] }}@{{ $reg['host'] }}</span>
                    <button type="button" class="text-bad underline" wire:click="deleteRegistry('{{ $reg['name'] }}')">delete</button>
                </li>
            @endforeach
        </ul>
    @endif
    <div class="mt-2 grid max-w-2xl gap-2 md:grid-cols-4">
        <input class="field font-mono text-sm" wire:model="registryName" placeholder="name (ghcr)">
        <input class="field font-mono text-sm" wire:model="registryHost" placeholder="host (ghcr.io; empty = Docker Hub)">
        <input class="field font-mono text-sm" wire:model="registryUsername" placeholder="username" autocomplete="off">
        <input class="field font-mono text-sm" type="password" wire:model="registryPassword" placeholder="password or token" autocomplete="new-password">
    </div>
    <button type="button" class="btn-ghost mt-2" wire:click="addRegistry">Save registry</button>
</details>
<label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Data volumes
    <textarea class="field mt-1 max-w-md font-mono text-sm" rows="2" wire:model="dockerVolumesText" placeholder="data/pg:/var/lib/postgresql/data"></textarea>
    <span class="mt-1 block normal-case tracking-normal text-zinc-500">
        One <span class="font-mono">host:container</span> per line (add <span class="font-mono">:ro</span> for read-only). Host paths are inside the app directory,
        so the data survives rebuilds and is included in the site's files and backups.
    </span>
</label>
<label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Environment
    <textarea class="field mt-1 max-w-md font-mono text-sm" rows="3" wire:model="dockerEnvText" placeholder="DATABASE_URL=postgres://..." autocomplete="off" spellcheck="false"></textarea>
    <span class="mt-1 block normal-case tracking-normal text-zinc-500">
        One <span class="font-mono">KEY=VALUE</span> per line. Kept in a file on the host, never on a command line; hidden from the audit log.
    </span>
</label>
