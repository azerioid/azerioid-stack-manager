<div class="space-y-4" wire:poll.2s="refresh">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
        <a href="/vhosts" class="btn-ghost inline-block">Back to vhosts</a>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-zinc-400">
            <div>
                <span class="font-mono text-zinc-200">{{ $domain }}</span>
                · last {{ $lines }} lines
                @unless ($ok)
                    <span class="ml-2 text-warn">(docker logs exited non-zero)</span>
                @endunless
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn-ghost text-xs" wire:click="refresh">Refresh now</button>
                <a href="/vhosts" class="btn-ghost text-xs">Back</a>
            </div>
        </div>
        <pre class="panel max-h-[min(70vh,640px)] overflow-auto p-4 font-mono text-xs leading-relaxed text-zinc-200 whitespace-pre-wrap">{{ $output !== '' ? $output : 'Waiting for log output…' }}</pre>
    @endif
</div>
