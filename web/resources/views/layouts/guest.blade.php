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
    <div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-12">
        <div class="mb-8 text-center">
            <div class="font-semibold tracking-wide text-zinc-100">AZERIOID Stack Manager</div>
            <div class="font-mono text-[10px] uppercase tracking-[0.25em] text-zinc-500">
                privileged operations
                @if (config('azerioid.require_totp'))
                    · 2FA required
                @else
                    · password only
                @endif
            </div>
        </div>
        <div class="panel p-6">
            {{ $slot }}
        </div>
        <p class="mt-6 text-center font-mono text-[11px] text-zinc-600">
            @php
                $panelUrl = parse_url((string) config('app.url'));
                $bindHost = $panelUrl['host'] ?? '127.0.0.1';
                $bindPort = $panelUrl['port'] ?? null;
            @endphp
            Bind: {{ $bindHost }}@if ($bindPort):{{ $bindPort }}@endif · access via SSH tunnel
        </p>
    </div>
    @livewireScripts
</body>
</html>
