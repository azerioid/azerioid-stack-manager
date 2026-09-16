@props([
    /** @var list<array{label?: string, items: list<array{type?: string, label: string, href?: string, wireClick?: string, danger?: bool}>}> */
    'groups' => [],
    'label' => 'Actions',
])

@php
    $flat = [];
    foreach ($groups as $group) {
        foreach ($group['items'] ?? [] as $item) {
            if (! is_array($item) || empty($item['label'])) {
                continue;
            }
            $flat[] = $item;
        }
    }
    $hasItems = $flat !== [];
    $menuId = 'row-actions-'.substr(md5($label.json_encode($groups)), 0, 12);
@endphp

@if ($hasItems)
    <div
        class="relative inline-flex justify-end"
        x-data="{
            open: false,
            focusIndex: -1,
            menuStyle: {},
            items: [],
            init() {
                this.refreshItems();
            },
            refreshItems() {
                this.items = Array.from(this.$refs.menu?.querySelectorAll('[data-menu-item]') ?? []);
            },
            placeMenu() {
                const t = this.$refs.trigger?.getBoundingClientRect();
                if (! t) return;
                const width = 216;
                const estimatedHeight = Math.max(this.$refs.menu?.offsetHeight || 0, 220);
                let left = t.right - width;
                if (left < 8) left = 8;
                if (left + width > window.innerWidth - 8) left = window.innerWidth - width - 8;
                let top = t.bottom + 4;
                if (top + estimatedHeight > window.innerHeight - 8) {
                    top = Math.max(8, t.top - estimatedHeight - 4);
                }
                // ink-800 (#171b22) — same solid surface as the Files context menu / panel chrome.
                // Set inline so opacity cannot depend on a Vite rebuild picking up a new utility class.
                this.menuStyle = {
                    position: 'fixed',
                    top: top + 'px',
                    left: left + 'px',
                    width: width + 'px',
                    zIndex: 100,
                    backgroundColor: '#171b22',
                    boxShadow: '0 0 0 1px rgba(255,255,255,0.08), 0 16px 40px rgba(0,0,0,0.55)',
                };
            },
            openMenu() {
                this.placeMenu();
                this.open = true;
                this.$nextTick(() => {
                    this.placeMenu();
                    this.refreshItems();
                    this.focusIndex = 0;
                    this.items[0]?.focus();
                });
            },
            closeMenu() {
                this.open = false;
                this.focusIndex = -1;
                this.$refs.trigger?.focus();
            },
            toggle() {
                this.open ? this.closeMenu() : this.openMenu();
            },
            onTriggerKeydown(e) {
                if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.openMenu();
                }
            },
            onMenuKeydown(e) {
                if (! this.open) return;
                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.closeMenu();
                    return;
                }
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.refreshItems();
                    if (this.items.length === 0) return;
                    this.focusIndex = (this.focusIndex + 1) % this.items.length;
                    this.items[this.focusIndex]?.focus();
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.refreshItems();
                    if (this.items.length === 0) return;
                    this.focusIndex = (this.focusIndex - 1 + this.items.length) % this.items.length;
                    this.items[this.focusIndex]?.focus();
                }
                if (e.key === 'Home') {
                    e.preventDefault();
                    this.refreshItems();
                    this.focusIndex = 0;
                    this.items[0]?.focus();
                }
                if (e.key === 'End') {
                    e.preventDefault();
                    this.refreshItems();
                    this.focusIndex = this.items.length - 1;
                    this.items[this.focusIndex]?.focus();
                }
                if (e.key === 'Tab') {
                    this.closeMenu();
                }
            },
        }"
        @keydown="onMenuKeydown($event)"
        @click.outside="if (open) closeMenu()"
        @resize.window="if (open) placeMenu()"
        @scroll.window="if (open) placeMenu()"
    >
        <button
            type="button"
            x-ref="trigger"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-400 hover:bg-ink-700 hover:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-brass-400/40"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-controls="{{ $menuId }}"
            aria-label="{{ $label }}"
            @click="toggle()"
            @keydown="onTriggerKeydown($event)"
        >
            <span class="text-lg leading-none" aria-hidden="true">⋮</span>
        </button>

        <div
            id="{{ $menuId }}"
            x-ref="menu"
            x-cloak
            x-show="open"
            x-transition.opacity.duration.100ms
            :style="menuStyle"
            role="menu"
            aria-label="{{ $label }}"
            class="rounded-md border border-white/15 bg-ink-800 py-1 shadow-panel"
            @keydown.escape.stop.prevent="closeMenu()"
        >
            @foreach ($groups as $gi => $group)
                @php
                    $items = array_values(array_filter(
                        $group['items'] ?? [],
                        static fn ($item) => is_array($item) && ! empty($item['label'])
                    ));
                @endphp
                @continue($items === [])
                @if ($gi > 0)
                    <div class="my-1 border-t border-white/10" role="separator"></div>
                @endif
                @if (! empty($group['label']))
                    <div class="px-3 py-1 font-mono text-[10px] uppercase tracking-wide text-zinc-500" aria-hidden="true">{{ $group['label'] }}</div>
                @endif
                @foreach ($items as $item)
                    @php
                        $danger = ! empty($item['danger']) || (($item['type'] ?? '') === 'danger');
                        $itemClass = $danger
                            ? 'text-bad hover:bg-bad/10 focus:bg-bad/10'
                            : 'text-zinc-200 hover:bg-ink-700 focus:bg-ink-700';
                    @endphp
                    @if (! empty($item['href']))
                        <a
                            href="{{ $item['href'] }}"
                            role="menuitem"
                            data-menu-item
                            tabindex="-1"
                            class="block w-full px-3 py-1.5 text-left text-xs {{ $itemClass }} focus:outline-none"
                            @click="closeMenu()"
                        >{{ $item['label'] }}</a>
                    @else
                        <button
                            type="button"
                            role="menuitem"
                            data-menu-item
                            tabindex="-1"
                            class="block w-full px-3 py-1.5 text-left text-xs {{ $itemClass }} focus:outline-none"
                            @if (! empty($item['wireClick']))
                                wire:click="{{ $item['wireClick'] }}"
                            @endif
                            @click="closeMenu()"
                        >{{ $item['label'] }}</button>
                    @endif
                @endforeach
            @endforeach
        </div>
    </div>
@endif
