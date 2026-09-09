<?php

namespace App\Console\Commands\Azerioid;

use App\Console\Commands\RunScheduledBackup;
use App\Models\Setting;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:backup
        {action : create|list|restore}
        {--local : Store/list/restore local encrypted archives under /var/lib/azerioid-panel/backups}
        {--spaces : Use DigitalOcean Spaces (requires saved Spaces credentials)}
        {--db=all : Database name for create (default all)}
        {--keep=14 : Retention keep-last-N for local create}
        {--file= : Local path or Spaces object key to restore}
        {--target= : Target database name for restore}
        {--overwrite : Overwrite existing target DB (requires --confirm; sends OVERWRITE)}
        {--confirm : Required for restore}
        {--json : JSON output}';

    protected $description = 'Encrypted DB backups via broker backup.* (local disk or Spaces)';

    public function handle(): int
    {
        return match (strtolower((string) $this->argument('action'))) {
            'create', 'run' => $this->create(),
            'list' => $this->listBackups(),
            'restore' => $this->restore(),
            default => $this->badAction(),
        };
    }

    private function badAction(): int
    {
        $this->error('Usage: azerioid backup create|list|restore [--local|--spaces] …');

        return self::INVALID;
    }

    private function create(): int
    {
        try {
            if ((bool) $this->option('local') && (bool) $this->option('spaces')) {
                throw new \RuntimeException('Pass only one of --local or --spaces.');
            }
            // Explicit --spaces uploads; default (and --local) write encrypted archives to disk.
            $dest = (bool) $this->option('spaces') ? 'spaces' : 'local';
            $stdin = $this->stdinFor($dest);
            $stdin['destination'] = $dest;
            $stdin['keep'] = max(1, min(365, (int) $this->option('keep')));
            $db = trim((string) $this->option('db')) ?: 'all';
            $data = $this->brokerData('backup.db', [$db], $stdin, 900);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->info('Backup created (encrypted).');
        $this->line('Destination: '.($data['destination'] ?? ''));
        $this->line('Key: '.($data['key'] ?? ''));
        $this->line('Size: '.($data['size'] ?? ''));
        $pruned = $data['pruned'] ?? [];
        if (is_array($pruned) && $pruned !== []) {
            $this->line('Pruned: '.count($pruned));
        }

        return self::SUCCESS;
    }

    private function listBackups(): int
    {
        try {
            $dest = (bool) $this->option('spaces') ? 'spaces' : 'local';
            if ((bool) $this->option('local')) {
                $dest = 'local';
            }
            $stdin = $this->stdinFor($dest);
            $stdin['destination'] = $dest;
            $data = $this->brokerData('backup.list', [], $stdin, 60, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $objects = is_array($data['objects'] ?? null) ? $data['objects'] : [];
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $rows = [];
        foreach ($objects as $o) {
            if (! is_array($o)) {
                continue;
            }
            $rows[] = [
                (string) ($o['key'] ?? ''),
                (string) ($o['kind'] ?? ''),
                (string) ($o['size'] ?? ''),
                (string) ($o['last_modified'] ?? ''),
            ];
        }

        return $this->emitTable(['key', 'kind', 'size', 'last_modified'], $rows);
    }

    private function restore(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to restore a database without --confirm.');

            return self::INVALID;
        }

        $file = trim((string) $this->option('file'));
        $target = trim((string) $this->option('target'));
        if ($file === '' || $target === '') {
            $this->error('Provide --file=<path|key> and --target=<db>.');

            return self::INVALID;
        }

        try {
            $dest = str_starts_with($file, '/') || (bool) $this->option('local')
                ? 'local'
                : ((bool) $this->option('spaces') ? 'spaces' : (str_starts_with($file, 'azerioid/') ? 'spaces' : 'local'));
            $stdin = $this->stdinFor($dest);
            $stdin['destination'] = $dest;
            $stdin['key'] = $file;
            $stdin['target'] = $target;
            $stdin['overwrite'] = (bool) $this->option('overwrite');
            if ($stdin['overwrite']) {
                $stdin['confirm'] = 'OVERWRITE';
            }
            $data = $this->brokerData('backup.restore.db', [$file], $stdin, 900);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->info('Restored into '.$target.(($data['overwrite'] ?? false) ? ' (overwrite)' : ''));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function stdinFor(string $destination): array
    {
        $pass = $this->passphrase();
        if ($destination === 'local') {
            return ['passphrase' => $pass, 'destination' => 'local'];
        }

        $spaces = RunScheduledBackup::spacesStdin();
        if ($spaces === null) {
            throw new \RuntimeException(
                'Spaces credentials are not configured. Save them in the Backups UI, or use --local.'
            );
        }

        return ['spaces' => $spaces, 'passphrase' => $pass, 'destination' => 'spaces'];
    }

    private function passphrase(): string
    {
        $env = getenv('AZERIOID_BACKUP_PASSPHRASE');
        if (is_string($env) && $env !== '') {
            if (strlen($env) < 16) {
                throw new \RuntimeException('AZERIOID_BACKUP_PASSPHRASE must be at least 16 characters.');
            }

            return $env;
        }

        $stored = Setting::getSecret('backup.passphrase');
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        throw new \RuntimeException(
            'Backup passphrase missing. Save it in the Backups UI, or set AZERIOID_BACKUP_PASSPHRASE in the environment (never argv).'
        );
    }
}
