@assets
    @vite('resources/js/vhost-files.js')
@endassets

@php
    $parentPath = ($path === '' || dirname($path) === '.') ? '' : dirname($path);
    $zipUrl = route('vhosts.files.zip', ['domain' => $domain]);
    $downloadBase = route('vhosts.files.download', ['domain' => $domain]);
@endphp

<div class="space-y-4" x-data="azFiles"
     @keydown.window="onKey($event)"
     @click.window="menu.open = false">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
        @if (! $username && ! $root)
            <a href="/vhosts" class="btn-ghost inline-block">Back to vhosts</a>
        @endif
    @endif

    @if ($flash)
        <div class="rounded-md border border-good/30 bg-good/10 px-4 py-3 text-sm text-good">{{ $flash }}</div>
    @endif

    @if ($username || $root)
        <div class="panel flex min-h-[28rem] flex-col overflow-hidden {{ $editorPath ? 'md:h-[calc(100vh-8.75rem)]' : '' }}">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/5 px-4 py-3 text-sm text-zinc-400">
                <div class="min-w-0">
                    <span class="font-mono text-zinc-200">{{ $domain }}</span>
                    · user <span class="font-mono text-brass-400">{{ $username }}</span>
                    · root <span class="font-mono text-zinc-300">{{ $root }}</span>
                </div>
                <a href="/vhosts" class="btn-ghost text-xs">Back to vhosts</a>
            </div>

            <div class="flex min-h-0 flex-1 flex-col md:flex-row">
                <div class="flex min-h-0 min-w-0 flex-col {{ $editorPath ? 'md:w-80 md:shrink-0 md:border-r md:border-white/5' : 'flex-1' }}">
                    <nav class="flex flex-wrap items-center gap-1 border-b border-white/5 px-4 py-2 font-mono text-xs text-zinc-400" aria-label="Path">
                        <button type="button" class="text-brass-400 hover:underline" @click="nav('')">{{ $domain }}</button>
                        @foreach ($crumbs as $crumb)
                            <span class="text-zinc-600">/</span>
                            <button type="button" class="text-brass-400 hover:underline" @click="nav({{ \Illuminate\Support\Js::from($crumb['path']) }})">{{ $crumb['name'] }}</button>
                        @endforeach
                    </nav>

                    <div class="flex flex-wrap items-end gap-3 border-b border-white/5 px-4 py-3">
                        <label class="text-xs uppercase tracking-wide text-zinc-500">New folder
                            <span class="mt-1 flex gap-2">
                                <input class="field" wire:model="newFolder" maxlength="255" placeholder="folder-name" @keydown.enter.prevent="$wire.mkdir()">
                                <button type="button" class="btn-ghost text-xs" wire:click="mkdir">Create</button>
                            </span>
                        </label>
                        <div class="text-xs uppercase tracking-wide text-zinc-500">
                            Upload
                            <div class="mt-1 flex gap-2">
                                <button type="button" class="btn-ghost text-xs" @click="$refs.fileInput.click()">Choose files</button>
                                <input class="sr-only" type="file" x-ref="fileInput" multiple @change="uploadFromInput($event)">
                            </div>
                            <span class="mt-1 block text-[11px] font-normal normal-case tracking-normal text-zinc-500">
                                Max {{ number_format($maxBytes / 1048576, 0) }} MiB each. Drop files onto the list.
                            </span>
                        </div>
                    </div>

                    <div x-show="selected.length > 0" x-cloak
                         class="flex flex-wrap items-center gap-2 border-b border-brass-500/20 bg-brass-500/5 px-4 py-2 text-xs">
                        <span class="font-mono text-zinc-300" x-text="selected.length + ' selected'"></span>
                        <button type="button" class="btn-ghost text-xs" @click="askDeleteSelected()">Delete</button>
                        <button type="button" class="btn-ghost text-xs" @click="zipSelected()">Download zip</button>
                        <button type="button" class="text-zinc-500 hover:text-zinc-200" @click="selected = []">Clear</button>
                    </div>

                    <div x-show="uploading" x-cloak class="border-b border-white/5 px-4 py-2">
                        <div class="mb-1 flex justify-between font-mono text-[11px] text-zinc-400">
                            <span x-text="uploadLabel"></span>
                            <span x-text="uploadPct + '%'"></span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded bg-ink-950">
                            <div class="h-full bg-brass-500 transition-[width] duration-150" :style="'width:' + uploadPct + '%'"></div>
                        </div>
                    </div>

                    <div class="relative min-h-0 flex-1 overflow-auto"
                         @dragenter.prevent="dropActive = true"
                         @dragover.prevent="dropActive = true"
                         @dragleave.self="dropActive = false"
                         @drop.prevent="onDrop($event)"
                         :class="dropActive ? 'ring-2 ring-inset ring-brass-400/50 bg-brass-500/5' : ''">
                        <div wire:loading.flex wire:target="openDir,mkdir,delete,rename" class="absolute inset-0 z-10 flex-col gap-2 bg-ink-800/80 p-4">
                            @for ($i = 0; $i < 6; $i++)
                                <div class="h-8 animate-pulse rounded bg-ink-700/80"></div>
                            @endfor
                        </div>

                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 z-[1] bg-ink-800 font-mono text-[11px] uppercase tracking-wide text-zinc-500">
                                <tr>
                                    <th class="w-8 px-3 py-2">
                                        <input type="checkbox" class="rounded border-white/10 bg-ink-950"
                                               :checked="allChecked()"
                                               @click.prevent="toggleAll($event)">
                                    </th>
                                    <th class="px-2 py-2">
                                        <button type="button" class="inline-flex items-center gap-1 hover:text-zinc-300" wire:click="toggleSort('name')">
                                            Name {!! $sort === 'name' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                                        </button>
                                    </th>
                                    <th class="hidden px-2 py-2 sm:table-cell">
                                        <button type="button" class="inline-flex items-center gap-1 hover:text-zinc-300" wire:click="toggleSort('type')">
                                            Type {!! $sort === 'type' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                                        </button>
                                    </th>
                                    <th class="px-2 py-2">
                                        <button type="button" class="inline-flex items-center gap-1 hover:text-zinc-300" wire:click="toggleSort('size')">
                                            Size {!! $sort === 'size' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                                        </button>
                                    </th>
                                    <th class="hidden px-2 py-2 md:table-cell">
                                        <button type="button" class="inline-flex items-center gap-1 hover:text-zinc-300" wire:click="toggleSort('mtime')">
                                            Modified {!! $sort === 'mtime' ? ($sortDir === 'asc' ? '▲' : '▼') : '' !!}
                                        </button>
                                    </th>
                                    <th class="w-24 px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                @if ($path !== '')
                                    <tr class="hover:bg-white/[0.03]">
                                        <td class="px-3 py-2"></td>
                                        <td class="px-2 py-2" colspan="5">
                                            <button type="button" class="font-mono text-brass-400 hover:underline" @click="nav({{ \Illuminate\Support\Js::from($parentPath) }})">↑ Parent directory</button>
                                        </td>
                                    </tr>
                                @endif
                                @forelse ($entries as $index => $row)
                                    @php
                                        $rel = $path === '' ? $row['name'] : $path . '/' . $row['name'];
                                        $escaped = ! empty($row['escaped']);
                                        $isDir = ($row['type'] ?? '') === 'dir' && ! $escaped;
                                        $kind = \App\Livewire\VhostFilesPage::fileKind((string) $row['name'], (string) ($row['type'] ?? 'file'));
                                        $relJs = \Illuminate\Support\Js::from($rel);
                                        $nameJs = \Illuminate\Support\Js::from((string) $row['name']);
                                    @endphp
                                    <tr class="group hover:bg-white/[0.03]"
                                        wire:key="row-{{ $rel }}"
                                        @click="rowClick({{ $relJs }}, $event, {{ (int) $index }}, {{ $isDir ? 'true' : 'false' }}, {{ $escaped ? 'true' : 'false' }})"
                                        @dblclick="rowOpen({{ $relJs }}, {{ $isDir ? 'true' : 'false' }}, {{ $escaped ? 'true' : 'false' }})"
                                        @contextmenu.prevent="openMenu($event, {{ $relJs }}, {{ $isDir ? 'true' : 'false' }}, {{ $escaped ? 'true' : 'false' }})">
                                        <td class="px-3 py-2" @click.stop>
                                            <input type="checkbox" class="rounded border-white/10 bg-ink-950"
                                                   :checked="selected.includes({{ $relJs }})"
                                                   @change="toggle({{ $relJs }}, $event, {{ (int) $index }})">
                                        </td>
                                        <td class="px-2 py-2 font-mono">
                                            @if ($renameFrom === $rel)
                                                <input class="field py-1 text-xs" wire:model="renameTo"
                                                       x-init="$nextTick(() => { $el.focus(); $el.select() })"
                                                       @keydown.enter.prevent="$wire.rename()"
                                                       @keydown.escape.prevent="$wire.cancelRename()"
                                                       @click.stop>
                                            @else
                                                <span class="inline-flex min-w-0 items-center gap-2">
                                                    @include('livewire.partials.file-icon', ['name' => $row['name'], 'type' => $isDir ? 'dir' : ($row['type'] ?? 'file')])
                                                    @if ($isDir)
                                                        <button type="button" class="truncate text-brass-400 hover:underline" @click.stop="nav({{ $relJs }})">{{ $row['name'] }}/</button>
                                                    @elseif ($escaped)
                                                        <span class="truncate text-bad">{{ $row['name'] }}</span>
                                                        <span class="shrink-0 text-[10px] uppercase tracking-wide text-bad">outside vhost</span>
                                                    @else
                                                        <button type="button" class="truncate text-zinc-200 hover:text-brass-400" @click.stop="edit({{ $relJs }})">{{ $row['name'] }}</button>
                                                    @endif
                                                </span>
                                            @endif
                                        </td>
                                        <td class="hidden px-2 py-2 font-mono text-xs text-zinc-500 sm:table-cell">{{ $kind }}{{ ! empty($row['link']) ? ' · link' : '' }}</td>
                                        <td class="px-2 py-2 font-mono text-xs text-zinc-500">{{ ($row['type'] ?? '') === 'dir' ? '—' : \App\Support\Format::bytes((int) ($row['size'] ?? 0)) }}</td>
                                        <td class="hidden px-2 py-2 font-mono text-xs text-zinc-500 md:table-cell">{{ \App\Support\Format::timestamp((int) ($row['mtime'] ?? 0)) }}</td>
                                        <td class="px-2 py-2 text-right" @click.stop>
                                            <div class="flex justify-end gap-1 opacity-0 focus-within:opacity-100 group-hover:opacity-100">
                                                @if (! $escaped && ($row['type'] ?? '') === 'file')
                                                    <button type="button" class="rounded p-1 text-zinc-400 hover:bg-ink-600 hover:text-brass-400" title="Edit" @click="edit({{ $relJs }})">
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="currentColor"><path d="M11.1 1.9a1.3 1.3 0 0 1 1.8 0l1.2 1.2a1.3 1.3 0 0 1 0 1.8L6.3 12.7 2.5 13.5l.8-3.8Z"/></svg>
                                                    </button>
                                                    <a href="{{ route('vhosts.files.download', ['domain' => $domain, 'path' => $rel]) }}" class="rounded p-1 text-zinc-400 hover:bg-ink-600 hover:text-brass-400" title="Download">
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="currentColor"><path d="M8 2v8.2L5.4 7.6 4.3 8.7 8 12.4l3.7-3.7-1.1-1.1L9 10.2V2ZM3 13h10v1.5H3Z"/></svg>
                                                    </a>
                                                @endif
                                                <button type="button" class="rounded p-1 text-zinc-400 hover:bg-ink-600 hover:text-brass-400" title="Rename" @click="$wire.startRename({{ $relJs }})">
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="currentColor"><path d="M2 12.2V14h1.8l6.6-6.6-1.8-1.8Zm9.7-7.9.8-.8a1 1 0 0 1 1.4 0l.8.8a1 1 0 0 1 0 1.4l-.8.8Z"/></svg>
                                                </button>
                                                <button type="button" class="rounded p-1 text-zinc-400 hover:bg-ink-600 hover:text-bad" title="Delete" @click="$wire.askDelete({{ $relJs }}, {{ $isDir ? 'true' : 'false' }})">
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="currentColor"><path d="M6 2h4l.5 1H14v1.5H2V3h3.5Zm.5 4v6h-1.5V6Zm2.5 0v6H7.5V6Zm2.5 0v6H10V6ZM3.5 13.5A1.5 1.5 0 0 0 5 15h6a1.5 1.5 0 0 0 1.5-1.5V5h-9Z"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-12 text-center text-sm text-zinc-500">
                                            This folder is empty — drag files here or create one.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($editorPath)
                    <div class="flex min-h-[24rem] min-w-0 flex-1 flex-col border-t border-white/5 md:border-t-0">
                        <div class="flex items-center justify-between gap-2 border-b border-white/5 px-4 py-2 text-sm">
                            <div class="flex min-w-0 items-center gap-2 font-mono text-zinc-200">
                                <span x-show="dirty" x-cloak class="h-2 w-2 shrink-0 rounded-full bg-brass-400" title="Unsaved changes"></span>
                                <span class="truncate">{{ $editorPath }}</span>
                                @if ($editorText)
                                    <span class="text-[10px] uppercase tracking-wide text-zinc-500">{{ \App\Livewire\VhostFilesPage::fileKind(basename($editorPath), 'file') }}</span>
                                @endif
                            </div>
                            <div class="flex shrink-0 gap-2">
                                @if ($editorText)
                                    <button type="button" class="btn-primary text-xs" @click="save()">Save</button>
                                @endif
                                <a href="{{ route('vhosts.files.download', ['domain' => $domain, 'path' => $editorPath]) }}" class="btn-ghost text-xs">Download</a>
                                <button type="button" class="btn-ghost text-xs" @click="closeEd()">Close</button>
                            </div>
                        </div>
                        @if ($editorText)
                            <div class="az-cm min-h-[22rem] flex-1" wire:ignore wire:key="cm-{{ $editorPath }}"
                                 x-init="bindEditor($el)"></div>
                        @else
                            <div class="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                                <p class="text-sm text-zinc-300">Binary file — use Download.</p>
                                <p class="max-w-md text-xs text-zinc-500">This file is not editable as text (image, archive, or other binary). Download it instead of opening it in the editor.</p>
                                <a href="{{ route('vhosts.files.download', ['domain' => $domain, 'path' => $editorPath]) }}" class="btn-primary text-xs">Download {{ basename($editorPath) }}</a>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if ($confirmDelete)
                <div class="border-t border-bad/30 bg-bad/10 px-4 py-3 text-sm">
                    <p class="text-bad">
                        Delete
                        @if (count($pendingDeletes) > 1)
                            <span class="font-mono">{{ count($pendingDeletes) }} items</span>
                        @else
                            <span class="font-mono">{{ $confirmDelete }}</span>
                        @endif
                        @if ($confirmRecursive) (including folder contents)@endif?
                    </p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" class="btn-primary bg-bad" wire:click="delete">Confirm delete</button>
                        <button type="button" class="btn-ghost" wire:click="cancelDelete">Cancel</button>
                    </div>
                </div>
            @endif
        </div>

        <div x-show="menu.open" x-cloak
             class="fixed z-50 min-w-[10rem] rounded-md border border-white/10 bg-ink-800 py-1 shadow-panel"
             :style="'top:' + menu.y + 'px;left:' + menu.x + 'px'"
             @click.stop>
            <button type="button" class="block w-full px-3 py-1.5 text-left text-sm text-zinc-200 hover:bg-ink-600" x-show="menu.dir" @click="nav(menu.rel); menu.open = false">Open</button>
            <button type="button" class="block w-full px-3 py-1.5 text-left text-sm text-zinc-200 hover:bg-ink-600" x-show="!menu.dir && !menu.escaped" @click="edit(menu.rel); menu.open = false">Edit</button>
            <a class="block px-3 py-1.5 text-sm text-zinc-200 hover:bg-ink-600" x-show="!menu.dir && !menu.escaped" :href="downloadUrl(menu.rel)" @click="menu.open = false">Download</a>
            <button type="button" class="block w-full px-3 py-1.5 text-left text-sm text-zinc-200 hover:bg-ink-600" @click="$wire.startRename(menu.rel); menu.open = false">Rename</button>
            <button type="button" class="block w-full px-3 py-1.5 text-left text-sm text-bad hover:bg-ink-600" @click="$wire.askDelete(menu.rel, menu.dir); menu.open = false">Delete</button>
        </div>
    @endif
</div>

@script
<script>
Alpine.data('azFiles', () => ({
    selected: [],
    lastIndex: null,
    dropActive: false,
    dirty: false,
    uploading: false,
    uploadPct: 0,
    uploadLabel: '',
    menu: { open: false, x: 0, y: 0, rel: '', dir: false, escaped: false },
    zipUrl: @json($zipUrl),
    downloadBase: @json($downloadBase),

    entryPaths() {
        const path = $wire.path || '';
        return ($wire.entries || []).map((row) => path === '' ? row.name : path + '/' + row.name);
    },

    init() {
        this._beforeUnload = (e) => {
            if (!this.dirty) return;
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', this._beforeUnload);
        $wire.on('editor-saved', () => {
            this.dirty = false;
            window.azVhostEditor?.markClean();
        });
        const saved = localStorage.getItem('az-files-sort');
        if (saved) {
            const [col, dir] = saved.split(':');
            if (col && dir && (col !== $wire.sort || dir !== $wire.sortDir)) {
                $wire.set('sort', col);
                $wire.set('sortDir', dir);
                $wire.applySort();
            }
        }
        this.$watch(() => $wire.sort + ':' + $wire.sortDir, (v) => {
            if (v) localStorage.setItem('az-files-sort', v);
        });
    },

    destroy() {
        window.removeEventListener('beforeunload', this._beforeUnload);
        window.azVhostEditor?.destroy();
    },

    allChecked() {
        const paths = this.entryPaths();
        return paths.length > 0 && paths.every((p) => this.selected.includes(p));
    },

    toggleAll(ev) {
        this.selected = this.allChecked() ? [] : [...this.entryPaths()];
        ev.target.checked = this.selected.length > 0;
    },

    toggle(rel, ev, index) {
        const paths = this.entryPaths();
        if (ev.shiftKey && this.lastIndex !== null) {
            const [a, b] = [this.lastIndex, index].sort((x, y) => x - y);
            const range = paths.slice(a, b + 1);
            this.selected = [...new Set([...this.selected, ...range])];
        } else if (this.selected.includes(rel)) {
            this.selected = this.selected.filter((p) => p !== rel);
        } else {
            this.selected = [...this.selected, rel];
        }
        this.lastIndex = index;
    },

    rowClick(rel, ev, index, isDir, escaped) {
        if (ev.target.closest('button, a, input')) return;
        if (ev.metaKey || ev.ctrlKey || ev.shiftKey) {
            this.toggle(rel, ev, index);
            return;
        }
        this.rowOpen(rel, isDir, escaped);
    },

    rowOpen(rel, isDir, escaped) {
        if (escaped) return;
        if (isDir) this.nav(rel);
        else this.edit(rel);
    },

    async nav(rel) {
        if (!(await this.guardDirty())) return;
        this.selected = [];
        this.menu.open = false;
        return $wire.openDir(rel);
    },

    async edit(rel) {
        if (!(await this.guardDirty())) return;
        return $wire.startEdit(rel);
    },

    async closeEd() {
        if (!(await this.guardDirty())) return;
        return $wire.closeEditor();
    },

    async guardDirty() {
        if (!this.dirty) return true;
        return window.confirm('Discard unsaved changes?');
    },

    save() {
        const doc = window.azVhostEditor?.getDoc?.();
        if (typeof doc === 'string') {
            return $wire.set('editorContent', doc).then(() => $wire.saveEdit());
        }
        return $wire.saveEdit();
    },

    bindEditor(el) {
        const boot = () => {
            if (!window.azVhostEditor) return;
            window.azVhostEditor.mount(el, {
                path: $wire.editorPath,
                doc: $wire.editorContent,
                onChange: (_text, dirty) => {
                    this.dirty = dirty;
                },
                onSave: (text) => {
                    $wire.set('editorContent', text).then(() => $wire.saveEdit());
                },
            });
            this.dirty = false;
        };
        if (window.azVhostEditor) boot();
        else window.addEventListener('az-vhost-editor-ready', boot, { once: true });
    },

    askDeleteSelected() {
        if (this.selected.length === 0) return;
        $wire.askDeleteMany(this.selected);
    },

    openMenu(ev, rel, dir, escaped) {
        this.menu = {
            open: true,
            x: Math.min(ev.clientX, window.innerWidth - 180),
            y: Math.min(ev.clientY, window.innerHeight - 180),
            rel,
            dir,
            escaped,
        };
        if (!this.selected.includes(rel)) this.selected = [rel];
    },

    downloadUrl(rel) {
        return this.downloadBase + '?path=' + encodeURIComponent(rel);
    },

    onKey(e) {
        const tag = (e.target && e.target.tagName) || '';
        const typing = tag === 'INPUT' || tag === 'TEXTAREA' || e.target?.isContentEditable;
        const inCm = e.target?.closest?.('.cm-editor');
        if ((e.metaKey || e.ctrlKey) && e.key === 's') {
            if ($wire.editorPath && $wire.editorText) {
                e.preventDefault();
                this.save();
            }
            return;
        }
        if (inCm || typing) return;
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'a') {
            e.preventDefault();
            this.selected = [...this.entryPaths()];
            return;
        }
        if (e.key === 'F2') {
            e.preventDefault();
            const rel = this.selected[this.selected.length - 1];
            if (rel) $wire.startRename(rel);
            return;
        }
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (this.selected.length === 0) return;
            e.preventDefault();
            this.askDeleteSelected();
        }
    },

    async uploadFromInput(ev) {
        const files = [...(ev.target.files || [])];
        ev.target.value = '';
        await this.uploadFiles(files);
    },

    async onDrop(ev) {
        this.dropActive = false;
        const files = [...(ev.dataTransfer?.files || [])];
        await this.uploadFiles(files);
    },

    async uploadFiles(files) {
        if (!files.length) return;
        this.uploading = true;
        for (const file of files) {
            this.uploadPct = 0;
            this.uploadLabel = file.name;
            await new Promise((resolve, reject) => {
                $wire.upload('upload', file,
                    () => resolve(),
                    () => reject(new Error('upload failed')),
                    (ev) => { this.uploadPct = ev.progress ?? 0; }
                );
            }).catch(() => {});
        }
        this.uploading = false;
        this.uploadPct = 0;
        this.uploadLabel = '';
    },

    async zipSelected() {
        if (this.selected.length === 0) return;
        const body = new FormData();
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        body.append('_token', csrf);
        for (const p of this.selected) body.append('paths[]', p);
        const res = await fetch(this.zipUrl, { method: 'POST', body, credentials: 'same-origin' });
        if (!res.ok) {
            const text = await res.text();
            window.alert(text.replace(/<[^>]+>/g, ' ').slice(0, 200) || 'Zip download failed.');
            return;
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = ($wire.domain || 'files') + '-files.zip';
        a.click();
        URL.revokeObjectURL(url);
    },
}));
</script>
@endscript
