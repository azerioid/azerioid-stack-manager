@php
    $badge = static function (array $component): string {
        return match ($component['kind'] ?? '') {
            'system' => 'system',
            'observed' => 'observed',
            default => match ($component['status'] ?? '') {
                'not_installed' => 'not installed',
                'broken' => 'broken',
                'active' => 'active',
                'installed' => 'installed',
                default => (string) ($component['status'] ?? 'unknown'),
            },
        };
    };

    $badgeClass = static function (array $component): string {
        return match ($component['kind'] ?? '') {
            'system' => 'bg-brass-400/10 text-brass-400',
            'observed' => 'bg-sky-400/10 text-sky-300',
            default => match ($component['status'] ?? '') {
                'not_installed' => 'bg-zinc-700/40 text-zinc-400',
                'broken' => 'bg-bad/10 text-bad',
                'active', 'installed' => 'bg-good/10 text-good',
                default => 'bg-zinc-700/40 text-zinc-400',
            },
        };
    };

    $operationBusy = $operationBusy ?? false;
@endphp

<section class="space-y-3">
    <div>
        <h2 class="text-sm font-medium text-zinc-200">{{ $title }}</h2>
        @if (!empty($hint))
            <p class="mt-1 text-sm text-zinc-500">{{ $hint }}</p>
        @endif
    </div>

    @if (($components ?? []) === [])
        @if (!empty($empty))
            <p class="text-sm text-zinc-500">{{ $empty }}</p>
        @endif
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($components as $component)
                @php
                    $id = $component['id'] ?? '';
                    $canInstall = ($component['installable'] ?? false)
                        && ($component['status'] ?? '') === 'not_installed'
                        && ($component['kind'] ?? '') === 'managed';
                    $canUninstall = ($component['kind'] ?? '') === 'managed'
                        && in_array($component['status'] ?? '', ['installed', 'active'], true);
                    $canAdopt = ($component['adoptable'] ?? false) === true;
                @endphp
                <article class="panel p-5 space-y-2" wire:key="component-{{ $id ?: $loop->index }}">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-medium text-zinc-100">{{ $component['display_name'] ?? $id }}</h3>
                        <span class="shrink-0 rounded px-2 py-0.5 font-mono text-[10px] uppercase tracking-wide {{ $badgeClass($component) }}">
                            {{ $badge($component) }}
                        </span>
                    </div>
                    <p class="text-xs uppercase tracking-wide text-zinc-500">{{ $component['category'] ?? 'other' }}</p>
                    @if (!empty($component['description']))
                        <p class="text-sm text-zinc-400">{{ $component['description'] }}</p>
                    @endif
                    @if (!empty($component['status_detail']))
                        <p class="text-xs text-zinc-500">{{ $component['status_detail'] }}</p>
                    @endif
                    @if (!empty($component['unit']))
                        <p class="font-mono text-xs text-zinc-600">unit: {{ $component['unit'] }}</p>
                    @endif
                    @if (($component['kind'] ?? '') === 'system')
                        <p class="text-xs text-brass-400/80">Non-removable panel runtime</p>
                    @endif
                    <div class="flex flex-wrap gap-2 pt-1">
                        @if ($canAdopt)
                            <button
                                type="button"
                                class="btn-primary text-xs"
                                wire:click="adopt(@js($id))"
                                wire:confirm="Bring this existing installation under panel management?"
                                @disabled($operationBusy)
                            >Adopt</button>
                        @endif
                        @if ($canInstall)
                            <button
                                type="button"
                                class="btn-primary text-xs"
                                wire:click="askInstall(@js($id))"
                                @disabled($operationBusy)
                            >Install</button>
                        @endif
                        @if ($canUninstall)
                            <button
                                type="button"
                                class="btn-ghost text-xs text-bad"
                                wire:click="askUninstall(@js($id))"
                                @disabled($operationBusy)
                            >Uninstall</button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
