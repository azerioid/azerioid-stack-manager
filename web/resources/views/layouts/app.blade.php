<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'AZERIOID Stack Manager' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
    <div class="flex min-h-screen">
        <aside class="hidden w-56 shrink-0 border-r border-white/5 bg-ink-900/80 md:flex md:flex-col">
            <div class="flex items-center gap-2 border-b border-white/5 px-5 py-5">
                <span class="led led-on"></span>
                <div>
                    <div class="font-semibold tracking-wide text-zinc-100">AZERIOID</div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.2em] text-zinc-500">stack manager</div>
                </div>
            </div>
            <nav class="flex flex-1 flex-col gap-0.5 p-3 text-sm">
                @php
                    $links = [
                        ['dashboard', 'Overview', '/'],
                        ['alerts', 'Alerts', '/alerts'],
                        ['updates', 'Updates', '/updates'],
                        ['vhosts', 'Virtual hosts', '/vhosts'],
                        ['databases', 'Databases', '/databases'],
                        ['backups', 'Backups', '/backups'],
                        ['services', 'Services', '/services'],
                        ['processes', 'Processes', '/processes'],
                        ['components', 'Components', '/components'],
                        ['logs', 'Logs', '/logs'],
                        ['security', 'Security', '/security'],
                        ['audit', 'Audit', '/audit'],
                        ['settings', 'Settings', '/settings'],
                    ];
                @endphp
                @foreach ($links as [$name, $label, $href])
                    @php
                        $active = $name === 'vhosts'
                            ? request()->routeIs('vhosts', 'vhosts.*')
                            : request()->routeIs($name);
                    @endphp
                    <a href="{{ $href }}"
                       class="rounded-md px-3 py-2 {{ $active ? 'bg-ink-700 text-brass-400' : 'text-zinc-400 hover:bg-ink-700 hover:text-zinc-100' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="border-t border-white/5 p-4">
                @csrf
                <div class="mb-2 truncate font-mono text-xs text-zinc-500">{{ auth()->user()?->email }}</div>
                <button type="submit" class="text-xs text-zinc-400 hover:text-bad">Sign out</button>
            </form>
        </aside>
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center justify-between border-b border-white/5 px-4 py-3 md:px-8">
                <div>
                    <h1 class="text-lg font-medium text-zinc-100">{{ $heading ?? $title ?? 'Overview' }}</h1>
                    <p class="font-mono text-xs text-zinc-500">{{ $sub ?? 'Linux · Caddy · PHP · SQLite' }}</p>
                </div>
            <nav class="flex gap-3 overflow-x-auto px-4 py-2 text-xs md:hidden">
                <a href="/" class="shrink-0 text-zinc-400">Overview</a>
                <a href="/alerts" class="shrink-0 text-zinc-400">Alerts</a>
                <a href="/updates" class="shrink-0 text-zinc-400">Updates</a>
                <a href="/vhosts" class="shrink-0 text-zinc-400">Vhosts</a>
                <a href="/databases" class="shrink-0 text-zinc-400">DB</a>
                <a href="/backups" class="shrink-0 text-zinc-400">Backups</a>
                <a href="/components" class="shrink-0 text-zinc-400">Components</a>
                <a href="/services" class="shrink-0 text-zinc-400">Services</a>
                <a href="/processes" class="shrink-0 text-zinc-400">Processes</a>
                <a href="/logs" class="shrink-0 text-zinc-400">Logs</a>
                <a href="/security" class="shrink-0 text-zinc-400">Security</a>
                <a href="/audit" class="shrink-0 text-zinc-400">Audit</a>
                <a href="/settings" class="shrink-0 text-zinc-400">Settings</a>
            </nav>
            </header>
            <main class="flex-1 p-4 md:p-8">
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>
