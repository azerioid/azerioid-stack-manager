<?php

namespace App\Livewire;

use App\Services\Broker\BrokerCallException;
use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Vhost files · AZERIOID Stack Manager')]
class VhostFilesPage extends Component
{
    use WithFileUploads;

    public string $domain = '';
    public string $path = '';
    public ?string $username = null;
    public ?string $root = null;
    public int $maxBytes = 20971520;
    /** @var list<array<string, mixed>> */
    public array $entries = [];
    public ?string $error = null;
    public ?string $flash = null;

    public string $newFolder = '';
    public ?string $confirmDelete = null;
    public bool $confirmRecursive = false;
    /** @var list<string> */
    public array $pendingDeletes = [];
    public ?string $renameFrom = null;
    public string $renameTo = '';

    public ?string $editorPath = null;
    public string $editorContent = '';
    public bool $editorText = true;

    public string $sort = 'name';
    public string $sortDir = 'asc';

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    /** @var list<string> */
    private const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'tif', 'tiff',
        'zip', 'tar', 'gz', 'tgz', 'bz2', '7z', 'rar', 'xz',
        'pdf', 'woff', 'woff2', 'ttf', 'eot', 'otf',
        'mp3', 'mp4', 'webm', 'ogg', 'wav', 'mov', 'avi',
        'sqlite', 'db', 'exe', 'so', 'dylib', 'bin', 'wasm', 'class', 'o',
    ];

    public function mount(string $domain, BrokerClient $broker): void
    {
        $this->domain = Validator::domain($domain);
        $this->reload($broker);
    }

    public function openDir(string $rel, BrokerClient $broker): void
    {
        $this->path = $rel;
        $this->editorPath = null;
        $this->editorContent = '';
        $this->confirmDelete = null;
        $this->pendingDeletes = [];
        $this->renameFrom = null;
        $this->reload($broker);
    }

    public function toggleSort(string $column): void
    {
        $column = strtolower($column);
        if (! in_array($column, ['name', 'type', 'size', 'mtime'], true)) {
            return;
        }
        if ($this->sort === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->sortDir = 'asc';
        }
        $this->sortEntries();
    }

    public function startEdit(string $rel, BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $res = $broker->call('vhost.files.read', [$this->domain], $this->payload(['path' => $rel]), 60);
            if (! $res->ok) {
                $this->error = (string) $res->error;

                return;
            }
            $bytes = base64_decode((string) ($res->data['content_base64'] ?? ''), true);
            if ($bytes === false) {
                $this->error = 'Unable to decode file.';

                return;
            }
            $this->editorPath = (string) ($res->data['path'] ?? $rel);
            $bytesSafe = $bytes !== false ? $bytes : '';
            $this->editorText = ! $this->looksBinary($this->editorPath, $bytesSafe, (bool) ($res->data['text'] ?? false));
            $this->editorContent = $this->editorText ? $bytesSafe : '';
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function saveEdit(BrokerClient $broker): void
    {
        if ($this->editorPath === null || ! $this->editorText) {
            return;
        }
        $this->mutate('vhost.files.write', [
            'path' => $this->editorPath,
            'content_base64' => base64_encode($this->editorContent),
        ], $broker, 'Saved ' . $this->editorPath);
        $this->dispatch('editor-saved');
    }

    public function closeEditor(): void
    {
        $this->editorPath = null;
        $this->editorContent = '';
        $this->editorText = true;
    }

    public function mkdir(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $name = trim($this->newFolder);
            if ($name === '') {
                $this->error = 'Folder name is required.';

                return;
            }
            $dest = $this->join($this->path, $name);
            $this->mutate('vhost.files.mkdir', ['path' => $dest], $broker, 'Created folder ' . $dest);
            $this->newFolder = '';
            $this->reload($broker);
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function updatedUpload(BrokerClient $broker): void
    {
        $this->error = null;
        $this->validate([
            'upload' => 'required|file|max:20480',
        ], [
            'upload.max' => 'Upload exceeds the 20 MiB limit.',
        ]);
        if (! $this->upload instanceof TemporaryUploadedFile) {
            return;
        }
        $bytes = file_get_contents($this->upload->getRealPath());
        if ($bytes === false) {
            $this->error = 'Unable to read the uploaded file.';
            $this->upload = null;

            return;
        }
        if (strlen($bytes) > $this->maxBytes) {
            $this->error = 'Upload exceeds the size limit (' . $this->maxBytes . ' bytes).';
            $this->upload = null;

            return;
        }
        $name = $this->upload->getClientOriginalName();
        try {
            $dest = $this->join($this->path, $name);
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
            $this->upload = null;

            return;
        }
        $this->mutate('vhost.files.write', [
            'path' => $dest,
            'content_base64' => base64_encode($bytes),
        ], $broker, 'Uploaded ' . $dest);
        $this->upload = null;
        $this->reload($broker);
    }

    public function askDelete(string $rel, bool $dir): void
    {
        $this->confirmDelete = $rel;
        $this->pendingDeletes = [$rel];
        $this->confirmRecursive = $dir;
    }

    /**
     * @param  list<string>  $rels
     */
    public function askDeleteMany(array $rels): void
    {
        $paths = [];
        $anyDir = false;
        foreach ($rels as $rel) {
            $rel = (string) $rel;
            if ($rel === '' || str_contains($rel, "\0")) {
                continue;
            }
            $paths[] = $rel;
            if ($this->isDirectoryPath($rel)) {
                $anyDir = true;
            }
        }
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return;
        }
        $this->pendingDeletes = $paths;
        $this->confirmDelete = $paths[0];
        $this->confirmRecursive = $anyDir;
    }

    public function delete(BrokerClient $broker): void
    {
        $targets = $this->pendingDeletes !== []
            ? $this->pendingDeletes
            : ($this->confirmDelete !== null ? [$this->confirmDelete] : []);
        if ($targets === []) {
            return;
        }
        $deleted = 0;
        foreach ($targets as $target) {
            $recursive = $this->isDirectoryPath($target);
            $this->mutate('vhost.files.delete', [
                'path' => $target,
                'recursive' => $recursive,
            ], $broker, 'Deleted ' . $target);
            if ($this->error !== null) {
                break;
            }
            $deleted++;
            if ($this->editorPath === $target) {
                $this->editorPath = null;
                $this->editorContent = '';
            }
        }
        if ($deleted > 1 && $this->error === null) {
            $this->flash = 'Deleted ' . $deleted . ' items.';
        }
        $this->confirmDelete = null;
        $this->pendingDeletes = [];
        $this->reload($broker);
    }

    public function cancelDelete(): void
    {
        $this->confirmDelete = null;
        $this->pendingDeletes = [];
        $this->confirmRecursive = false;
    }

    public function cancelRename(): void
    {
        $this->renameFrom = null;
        $this->renameTo = '';
    }

    public function startRename(string $rel): void
    {
        $this->renameFrom = $rel;
        $this->renameTo = basename($rel);
    }

    public function rename(BrokerClient $broker): void
    {
        if ($this->renameFrom === null) {
            return;
        }
        $name = trim($this->renameTo);
        if ($name === '') {
            $this->error = 'New name is required.';

            return;
        }
        $parent = dirname($this->renameFrom);
        $dir = ($parent === '.' || $parent === '') ? '' : $parent;
        try {
            $dest = $this->join($dir, $name);
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->mutate('vhost.files.rename', [
            'path' => $this->renameFrom,
            'dest' => $dest,
        ], $broker, 'Renamed to ' . $dest);
        $this->renameFrom = null;
        $this->reload($broker);
    }

    public function render()
    {
        $crumbs = [];
        $acc = '';
        foreach (explode('/', $this->path) as $seg) {
            if ($seg === '') {
                continue;
            }
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $crumbs[] = ['name' => $seg, 'path' => $acc];
        }

        return view('livewire.vhost-files', [
            'crumbs' => $crumbs,
        ])->layoutData([
            'heading' => 'Files',
            'sub' => $this->domain !== '' ? $this->domain . ' — vhost directory only' : '',
        ]);
    }

    private function reload(BrokerClient $broker): void
    {
        $this->error = null;
        try {
            $res = $broker->call('vhost.files.list', [$this->domain], $this->payload(['path' => $this->path]), null, false);
            if (! $res->ok) {
                $this->error = (string) $res->error;
                $this->entries = [];

                return;
            }
            $this->path = (string) ($res->data['path'] ?? $this->path);
            $this->username = (string) ($res->data['username'] ?? '');
            $this->root = (string) ($res->data['root'] ?? '');
            $this->maxBytes = (int) ($res->data['max_bytes'] ?? $this->maxBytes);
            $this->entries = is_array($res->data['entries'] ?? null) ? $res->data['entries'] : [];
            $this->sortEntries();
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
            $this->entries = [];
        }
    }

    public function applySort(): void
    {
        $this->sortEntries();
    }

    public static function fileKind(string $name, string $type): string
    {
        if ($type === 'dir') {
            return 'folder';
        }
        $base = strtolower($name);
        if ($base === '.env' || str_starts_with($base, '.env.')) {
            return 'env';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'php', 'phtml' => 'php',
            'html', 'htm' => 'html',
            'css', 'scss', 'less' => 'css',
            'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx' => 'js',
            'json' => 'json',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'svg', 'tif', 'tiff' => 'image',
            'md', 'markdown' => 'md',
            'yml', 'yaml' => 'yaml',
            default => 'file',
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra): array
    {
        return array_merge([
            'domain' => $this->domain,
            'admin_user_id' => (string) auth()->id(),
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function mutate(string $action, array $input, BrokerClient $broker, string $okFlash): void
    {
        $this->error = null;
        $this->flash = null;
        try {
            $timeout = $action === 'vhost.files.write' ? 120 : null;
            $res = $broker->call($action, [$this->domain], $this->payload($input), $timeout);
            if (! $res->ok) {
                $this->error = (string) $res->error;

                return;
            }
            $this->flash = $okFlash;
        } catch (BrokerCallException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function join(string $dir, string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new BrokerCallException('Invalid file name.', 2);
        }
        if ($dir === '' || $dir === '.') {
            return $name;
        }

        return $dir . '/' . $name;
    }

    private function sortEntries(): void
    {
        $col = $this->sort;
        $dir = $this->sortDir === 'desc' ? -1 : 1;
        usort($this->entries, static function (array $a, array $b) use ($col, $dir): int {
            $ad = ($a['type'] ?? '') === 'dir' ? 0 : 1;
            $bd = ($b['type'] ?? '') === 'dir' ? 0 : 1;
            if ($ad !== $bd) {
                return $ad <=> $bd;
            }
            $av = match ($col) {
                'size' => (int) ($a['size'] ?? 0),
                'mtime' => (int) ($a['mtime'] ?? 0),
                'type' => strtolower((string) ($a['type'] ?? '')),
                default => strtolower((string) ($a['name'] ?? '')),
            };
            $bv = match ($col) {
                'size' => (int) ($b['size'] ?? 0),
                'mtime' => (int) ($b['mtime'] ?? 0),
                'type' => strtolower((string) ($b['type'] ?? '')),
                default => strtolower((string) ($b['name'] ?? '')),
            };
            if (is_int($av) && is_int($bv)) {
                return ($av <=> $bv) * $dir;
            }

            return strcasecmp((string) $av, (string) $bv) * $dir;
        });
    }

    private function looksBinary(string $path, string $bytes, bool $brokerSaysText): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::BINARY_EXTENSIONS, true)) {
            return true;
        }
        if (str_contains($bytes, "\0")) {
            return true;
        }

        return ! $brokerSaysText;
    }

    private function isDirectoryPath(string $rel): bool
    {
        foreach ($this->entries as $row) {
            $name = (string) ($row['name'] ?? '');
            $full = $this->path === '' ? $name : $this->path . '/' . $name;
            if ($full === $rel) {
                return ($row['type'] ?? '') === 'dir';
            }
        }

        return false;
    }
}
