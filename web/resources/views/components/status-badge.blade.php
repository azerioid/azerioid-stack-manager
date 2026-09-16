@props([
    'state' => 'healthy', // healthy|failed|pending|disabled
    'label' => null,
    'detail' => null,
])

@php
    $state = strtolower((string) $state);
    $defaults = [
        'healthy' => ['label' => 'Healthy', 'tone' => 'good'],
        'failed' => ['label' => 'Failed', 'tone' => 'bad'],
        'pending' => ['label' => 'Pending', 'tone' => 'warn'],
        'disabled' => ['label' => 'Disabled', 'tone' => 'neutral'],
    ];
    $meta = $defaults[$state] ?? $defaults['healthy'];
    $text = $label ?? $meta['label'];
    $detail = is_string($detail) && trim($detail) !== '' ? trim($detail) : null;
@endphp

<span {{ $attributes->class('inline-flex max-w-[14rem] flex-col items-start gap-0.5') }}>
    @if ($detail)
        <x-badge :tone="$meta['tone']" :title="$detail">{{ $text }}</x-badge>
    @else
        <x-badge :tone="$meta['tone']">{{ $text }}</x-badge>
    @endif
    @if ($detail)
        <span class="max-w-full truncate font-mono text-[10px] leading-tight text-zinc-500" title="{{ $detail }}">{{ $detail }}</span>
    @endif
</span>
