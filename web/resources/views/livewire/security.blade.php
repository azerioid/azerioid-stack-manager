<div class="space-y-6">
    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif
    @if ($error)
        <pre class="max-h-48 overflow-auto rounded-md border border-bad/40 bg-bad/10 px-4 py-3 font-mono text-xs text-bad">{{ $error }}</pre>
    @endif

    <section class="panel p-5">
        <h2 class="text-sm font-medium">SSH / auth.log</h2>
        <p class="mt-1 text-xs text-zinc-500">{{ $auth['path'] ?? '' }} · failed {{ $auth['failed_count'] ?? 0 }}</p>
        <div class="mt-3 grid gap-4 md:grid-cols-2">
            <div>
                <div class="text-xs uppercase tracking-wide text-zinc-500">Accepted</div>
                @foreach ($auth['success'] ?? [] as $row)
                    <div class="mt-1 font-mono text-[11px] text-zinc-300">{{ $row['user'] }} {{ $row['method'] }} {{ $row['ip'] }}</div>
                @endforeach
            </div>
            <div>
                <div class="text-xs uppercase tracking-wide text-zinc-500">Failed</div>
                @foreach ($auth['failed'] ?? [] as $row)
                    <div class="mt-1 font-mono text-[11px] text-bad">{{ $row['user'] }} {{ $row['ip'] }}</div>
                @endforeach
            </div>
        </div>
        @if (!empty($auth['new_root_ips']))
            <div class="mt-3 text-sm text-warn">New root source IPs flagged in this window.</div>
        @endif
    </section>

    <section class="panel p-5">
        <h2 class="text-sm font-medium">UFW</h2>
        @if (!($firewall['ufw']['installed'] ?? false))
            <p class="mt-2 text-sm text-zinc-500">UFW is not installed (read-only view).</p>
        @else
            <pre class="mt-3 max-h-48 overflow-auto font-mono text-[11px] text-zinc-300">{{ $firewall['ufw']['status'] ?? '' }}</pre>
        @endif
    </section>

    <section class="panel p-5 space-y-3">
        <h2 class="text-sm font-medium">fail2ban</h2>
        @if (!($firewall['fail2ban']['installed'] ?? false))
            <p class="text-sm text-zinc-400">Not installed. One-click installs a sane sshd jail (does not change UFW).</p>
            <input class="field max-w-sm" wire:model="confirm" placeholder="INSTALL-FAIL2BAN">
            <button class="btn-primary" type="button" wire:click="installFail2ban" wire:confirm="Install fail2ban via apt?">Install fail2ban</button>
        @else
            <pre class="max-h-40 overflow-auto font-mono text-[11px] text-zinc-300">{{ $firewall['fail2ban']['status'] ?? '' }}</pre>
            @foreach ($firewall['fail2ban']['jails'] ?? [] as $j)
                <div class="font-mono text-xs">{{ $j['jail'] }} · banned {{ implode(', ', $j['banned'] ?? []) ?: 'none' }}</div>
            @endforeach
            <div class="flex flex-wrap gap-2">
                <input class="field max-w-xs" wire:model="unban_ip" placeholder="1.2.3.4">
                <input class="field max-w-[8rem]" wire:model="unban_jail">
                <button class="btn-ghost" type="button" wire:click="unban">Unban</button>
            </div>
        @endif
    </section>

    <section class="panel p-5 space-y-3">
        <h2 class="text-sm font-medium">MariaDB bind rollback</h2>
        <p class="text-sm text-zinc-400">The guided fix on Overview writes a <span class="font-mono">.lacmp-bak-*</span> next to 50-server.cnf. Paste that path to restore the previous bind-address (restarts MariaDB).</p>
        <input class="field" wire:model="bind_backup" placeholder="/etc/mysql/mariadb.conf.d/50-server.cnf.lacmp-bak-…">
        <button class="btn-ghost" type="button" wire:click="rollbackMariadb" wire:confirm="Restart MariaDB from this backup?">Rollback bind-address</button>
    </section>

    <form wire:submit="saveCron" class="panel p-5 space-y-3">
        <h2 class="text-sm font-medium">Root crontab</h2>
        <p class="text-sm text-warn">These run as root. Syntax is validated; command substitution is rejected. Do not put secrets here.</p>
        <textarea class="field h-40" wire:model="crontab_text"></textarea>
        <input class="field font-mono text-sm" wire:model="confirm" placeholder="Type UPDATE-ROOT-CRON to confirm" autocomplete="off">
        <button class="btn-danger" type="submit" wire:confirm="Replace the entire root crontab?">Save crontab</button>
    </form>
</div>
