@props([
    'tone' => 'neutral', // neutral|accent|good|warn|bad
    'size' => 'sm', // sm|md
])

@php
    $toneClass = match ($tone) {
        'accent' => 'bg-brass-500/15 text-brass-400',
        'good' => 'bg-good/15 text-good',
        'warn' => 'bg-warn/15 text-warn',
        'bad' => 'bg-bad/15 text-bad',
        default => 'bg-ink-700 text-zinc-400',
    };
    $sizeClass = $size === 'md'
        ? 'px-2 py-0.5 text-[11px]'
        : 'px-1.5 py-0.5 text-[10px]';
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded font-mono uppercase tracking-wide',
    $toneClass,
    $sizeClass,
]) }}>{{ $slot }}</span>
