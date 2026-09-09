@php
    $kind = \App\Livewire\VhostFilesPage::fileKind($name ?? '', $type ?? 'file');
    $color = match ($kind) {
        'folder' => 'text-brass-400',
        'php' => 'text-[#7eb8c9]',
        'html' => 'text-warn',
        'css' => 'text-[#6ea8c4]',
        'js' => 'text-brass-400',
        'json' => 'text-good',
        'image' => 'text-good',
        'md' => 'text-zinc-300',
        'yaml' => 'text-warn',
        'env' => 'text-zinc-400',
        default => 'text-zinc-500',
    };
@endphp
<span class="inline-flex h-4 w-4 shrink-0 items-center justify-center {{ $color }}" aria-hidden="true">
    @switch($kind)
        @case('folder')
            <svg viewBox="0 0 16 16" class="h-4 w-4 fill-current"><path d="M1.5 3.5A1.5 1.5 0 0 1 3 2h3.2c.3 0 .6.1.8.3L8.2 3.5H13A1.5 1.5 0 0 1 14.5 5v7A1.5 1.5 0 0 1 13 13.5H3A1.5 1.5 0 0 1 1.5 12Z"/></svg>
            @break
        @case('php')
            <svg viewBox="0 0 16 16" class="h-4 w-4 fill-current"><path d="M2 4.5A1.5 1.5 0 0 1 3.5 3h9A1.5 1.5 0 0 1 14 4.5v7A1.5 1.5 0 0 1 12.5 13h-9A1.5 1.5 0 0 1 2 11.5Zm3.2 2.1h1.1c1.1 0 1.7.4 1.7 1.4s-.6 1.4-1.7 1.4H6.3v1.1H5.2Zm1.1 2h.4c.5 0 .8-.2.8-.6s-.3-.6-.8-.6h-.4Zm3.2-2h1.9c.9 0 1.4.5 1.4 1.4s-.5 1.4-1.4 1.4h-.8v1.1h-1.1Zm1.1 2h.6c.4 0 .6-.2.6-.6s-.2-.6-.6-.6h-.6Z"/></svg>
            @break
        @case('image')
            <svg viewBox="0 0 16 16" class="h-4 w-4 fill-current"><path d="M2 3.5A1.5 1.5 0 0 1 3.5 2h9A1.5 1.5 0 0 1 14 3.5v9A1.5 1.5 0 0 1 12.5 14h-9A1.5 1.5 0 0 1 2 12.5ZM4 11l2.2-2.6 1.6 1.8L10.3 7 13 11Zm1.2-5.2a1.1 1.1 0 1 0 0-2.2 1.1 1.1 0 0 0 0 2.2Z"/></svg>
            @break
        @default
            <svg viewBox="0 0 16 16" class="h-4 w-4 fill-current"><path d="M4 1.5A1.5 1.5 0 0 0 2.5 3v10A1.5 1.5 0 0 0 4 14.5h8A1.5 1.5 0 0 0 13.5 13V6.2L9.8 1.5Zm5.5.7 3.3 4H9.5Z"/></svg>
    @endswitch
</span>
