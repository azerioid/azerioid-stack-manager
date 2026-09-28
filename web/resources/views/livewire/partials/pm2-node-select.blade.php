{{-- Node.js choice for a PM2 vhost (A51). --}}
<label class="mt-3 block text-xs uppercase tracking-wide text-zinc-500">Node.js
    <select class="field mt-1 max-w-xs" wire:model="pm2Node">
        @if ($pm2NodeTarget === null)
            <option value="">Default ({{ in_array('system', $pm2NodeRuntimes, true) ? 'system' : (end($pm2NodeRuntimes) ?: 'none installed') }})</option>
        @endif
        @foreach ($pm2NodeRuntimes as $rt)
            <option value="{{ $rt }}">{{ $rt === 'system' ? 'System (NodeSource, /usr/bin/node)' : 'Node.js '.$rt }}</option>
        @endforeach
    </select>
    <span class="mt-1 block normal-case tracking-normal text-zinc-500">
        More versions: install Node.js 20, 22 or 24 from Components. Each runs side by side with its own PM2.
    </span>
</label>
