{{-- B2 / request #4 — scheduled jobs. A job belongs to a site and runs as that site;
     root jobs need the typed confirmation every time. --}}
<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
    @endif

    <div class="panel p-5">
        <h2 class="text-sm font-medium">Jobs</h2>
        @if ($this->jobs() === [])
            <p class="mt-4 text-sm text-zinc-500">
                No scheduled jobs yet. A job you add for a site runs as that site's own identity,
                not as root.
            </p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-zinc-500">
                        <tr>
                            <th class="px-2 py-2 font-medium">Owner</th>
                            <th class="px-2 py-2 font-medium">Runs as</th>
                            <th class="px-2 py-2 font-medium">Schedule</th>
                            <th class="px-2 py-2 font-medium">Command</th>
                            <th class="px-2 py-2 font-medium">State</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($this->jobs() as $job)
                            <tr wire:key="cron-{{ $job['id'] }}" class="align-top">
                                <td class="px-2 py-2 text-zinc-300">{{ $job['owner'] }}</td>
                                <td class="px-2 py-2 font-mono text-[11px] {{ $job['owner'] === 'root' ? 'text-bad' : 'text-zinc-400' }}">
                                    {{ $job['runs_as'] }}
                                </td>
                                <td class="px-2 py-2 font-mono text-zinc-200">{{ $job['schedule'] }}</td>
                                <td class="px-2 py-2 font-mono text-[11px] text-zinc-400">
                                    {{ $job['command'] }}
                                    @if (($job['note'] ?? '') !== '')
                                        <span class="block text-zinc-500">{{ $job['note'] }}</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2 {{ $job['enabled'] ? 'text-good' : 'text-zinc-500' }}">
                                    {{ $job['enabled'] ? 'enabled' : 'disabled' }}
                                </td>
                                <td class="px-2 py-2 text-right whitespace-nowrap">
                                    <button type="button" class="text-brass-400 hover:underline" wire:click="runNow('{{ $job['id'] }}')">Run now</button>
                                    <button type="button" class="ml-2 text-brass-400 hover:underline" wire:click="showLog('{{ $job['id'] }}')">
                                        {{ $openLog === $job['id'] ? 'Hide log' : 'Log' }}
                                    </button>
                                    @if ($job['enabled'])
                                        <button type="button" class="ml-2 text-zinc-400 hover:underline" wire:click="toggle('{{ $job['id'] }}', false)">Disable</button>
                                    @else
                                        <button type="button" class="ml-2 text-zinc-400 hover:underline" wire:click="toggle('{{ $job['id'] }}', true)">Enable</button>
                                    @endif
                                    <button type="button" class="ml-2 text-bad hover:underline"
                                            wire:click="delete('{{ $job['id'] }}')" wire:confirm="Remove this job?">Remove</button>
                                </td>
                            </tr>
                            @if ($openLog === $job['id'])
                                <tr wire:key="cron-log-{{ $job['id'] }}">
                                    <td colspan="6" class="bg-ink-900/60 px-4 py-3">
                                        @if ($logLines === [])
                                            <p class="text-[11px] text-zinc-500">Nothing recorded yet — the job has not run since it was added.</p>
                                        @else
                                            <pre class="max-h-64 overflow-auto font-mono text-[11px] text-zinc-300">{{ implode("\n", $logLines) }}</pre>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="panel grid gap-4 p-5 md:grid-cols-2">
        <h2 class="md:col-span-2 text-sm font-medium">Add a job</h2>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Owner
            <select class="field mt-1" wire:model.live="owner">
                <option value="">Choose…</option>
                @foreach ($domains as $domain)
                    <option value="{{ $domain }}">{{ $domain }}</option>
                @endforeach
                <option value="root">root (host maintenance)</option>
            </select>
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Schedule
            <input class="field mt-1 font-mono text-sm" wire:model="schedule" placeholder="*/5 * * * *">
        </label>
        <label class="md:col-span-2 text-xs uppercase tracking-wide text-zinc-500">Command
            <input class="field mt-1 font-mono text-sm" wire:model="command" placeholder="/usr/bin/php /data/www/site/artisan schedule:run">
        </label>
        <label class="text-xs uppercase tracking-wide text-zinc-500">Note (optional)
            <input class="field mt-1" wire:model="note" placeholder="queue worker">
        </label>
        @if ($owner === 'root')
            <label class="text-xs uppercase tracking-wide text-zinc-500">
                Type <span class="font-mono normal-case">RUN-AS-ROOT</span>
                <input class="field mt-1 font-mono text-sm" wire:model="confirm" placeholder="RUN-AS-ROOT">
            </label>
            <p class="md:col-span-2 text-xs text-bad">
                A root job can do anything on this host. Prefer a site owner unless this is host
                maintenance — a site's job does not need root to run that site's own code.
            </p>
        @endif
        <div class="md:col-span-2">
            <button class="btn-primary" type="button" wire:click="add">Add job</button>
        </div>
    </div>

    @if ($this->unmanaged() !== [])
        <div class="panel p-5">
            <h2 class="text-sm font-medium">Root crontab lines the panel does not manage</h2>
            <p class="mt-1 text-xs text-zinc-500">
                These were on this host before, or were added outside the panel. They are left exactly
                as they are — the panel only rewrites its own block.
            </p>
            <pre class="mt-3 max-h-40 overflow-auto font-mono text-[11px] text-zinc-400">{{ implode("\n", $this->unmanaged()) }}</pre>
        </div>
    @endif

    <p class="text-xs text-zinc-500">
        Output and exit codes are recorded per job under <span class="font-mono">/var/log/azerioid-panel/cron</span>,
        kept 14 days — cron's own default is to mail them somewhere nobody reads, which is why a failing
        job is usually noticed by its consequences. <span class="font-mono">@reboot</span> is not accepted:
        it is a startup hook rather than a schedule.
    </p>
</div>
