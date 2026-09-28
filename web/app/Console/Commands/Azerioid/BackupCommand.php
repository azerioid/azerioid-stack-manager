<?php

namespace App\Console\Commands\Azerioid;

use App\Console\Commands\RunScheduledBackup;
use App\Models\Setting;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:backup
        {action : create|list|restore|verify|bundle|bundles|bundle-restore|bundle-dbs}
        {domain? : site domain (bundle, bundle-restore, bundle-dbs)}
        {--local : Store/list/restore local encrypted archives under /var/lib/azerioid-panel/backups}
        {--spaces : Use DigitalOcean Spaces (requires saved Spaces credentials)}
        {--db=all : Database name for create (default all)}
        {--keep=14 : Retention keep-last-N for local create}
        {--file= : Local path or Spaces object key to restore}
        {--target= : Target database name for restore}
        {--overwrite : Overwrite existing target DB (requires --confirm; sends OVERWRITE)}
        {--confirm : Required for restore}
        {--kdf= : create: pbkdf2 (default, portable) or argon2id (needs libsodium)}
        {--deep : verify: also restore a single-database dump into a scratch database and drop it}
        {--bundle= : bundle-restore: bundle stamp, e.g. 20260928T030000Z}
        {--parts= : bundle-restore: comma-separated parts (default all), e.g. files,config}
        {--apply : bundle-restore: restore (without it: preview only)}
        {--db-confirm= : bundle-restore: OVERWRITE to let existing databases be overwritten}
        {--set=* : bundle-dbs: engine:name (repeatable); none clears the list}
        {--json : JSON output}';

    protected $description = 'Encrypted DB backups via broker backup.* (local disk or Spaces)';

    public function handle(): int
    {
        return match (strtolower((string) $this->argument('action'))) {
            'create', 'run' => $this->create(),
            'list' => $this->listBackups(),
            'restore' => $this->restore(),
            'verify' => $this->verify(),
            'bundle' => $this->bundle(),
            'bundles' => $this->bundles(),
            'bundle-restore' => $this->bundleRestore(),
            'bundle-dbs' => $this->bundleDbs(),
            default => $this->badAction(),
        };
    }

    private function bundleDest(): string
    {
        return (bool) $this->option('spaces') && ! (bool) $this->option('local') ? 'spaces' : 'local';
    }

    /** A52: back up one site as a bundle (files, config, its databases). */
    private function bundle(): int
    {
        try {
            $dest = $this->bundleDest();
            $data = $this->brokerData('backup.vhost.run', [(string) $this->argument('domain')], $this->stdinFor($dest) + ['destination' => $dest], 3600);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $this->info('Bundle ' . $data['bundle'] . ' of ' . $data['domain'] . ' (' . $dest . '): '
            . implode(', ', array_column((array) $data['parts'], 'part')) . ', ' . round(((int) $data['size']) / 1048576, 1) . ' MB.');

        return self::SUCCESS;
    }

    private function bundles(): int
    {
        try {
            $dest = $this->bundleDest();
            $stdin = ['destination' => $dest];
            if ($dest === 'spaces') {
                $stdin['spaces'] = RunScheduledBackup::spacesStdin() ?? throw new \RuntimeException('Spaces credentials are incomplete.');
            }
            $data = $this->brokerData('backup.vhost.list', [], $stdin, 120, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        foreach ((array) $data['bundles'] as $b) {
            $this->line($b['domain'] . '  ' . $b['bundle'] . '  ' . implode(',', $b['parts']) . ($b['complete'] ? '' : '  (incomplete)'));
        }

        return self::SUCCESS;
    }

    private function bundleRestore(): int
    {
        try {
            $domain = (string) $this->argument('domain');
            $dest = $this->bundleDest();
            $stdin = $this->stdinFor($dest) + ['destination' => $dest, 'bundle' => (string) $this->option('bundle')];
            if ((string) $this->option('parts') !== '') {
                $stdin['parts'] = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('parts')))));
            }
            if ((bool) $this->option('apply')) {
                if (! (bool) $this->option('confirm')) {
                    $this->error('Refusing to restore without --confirm (this replaces the live site).');

                    return self::INVALID;
                }
                $stdin += ['apply' => true, 'confirm' => strtoupper($domain), 'db_confirm' => (string) $this->option('db-confirm')];
            }
            $data = $this->brokerData('backup.vhost.restore', [$domain], $stdin, 3600);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        if (! ($data['applied'] ?? false)) {
            $this->line('Preview — would restore: ' . implode(', ', (array) ($data['would_restore'] ?? [])) . '. Add --apply --confirm to restore.');

            return self::SUCCESS;
        }
        foreach ((array) ($data['results'] ?? []) as $part => $result) {
            $this->line($part . ': ' . (is_array($result['restored'] ?? null) ? implode('; ', $result['restored']) : 'restored'));
        }
        $this->info('Restored ' . $domain . ' from ' . $data['bundle'] . '.');

        return self::SUCCESS;
    }

    private function bundleDbs(): int
    {
        try {
            $domain = (string) $this->argument('domain');
            $set = (array) $this->option('set');
            if ($set === []) {
                $data = $this->brokerData('backup.vhost.settings', [$domain], [], 30, false);
            } else {
                $rows = [];
                foreach ($set as $spec) {
                    if ($spec === 'none') {
                        continue;
                    }
                    [$engine, $name] = array_pad(explode(':', $spec, 2), 2, '');
                    $rows[] = ['engine' => $engine, 'name' => $name];
                }
                $data = $this->brokerData('backup.vhost.settings.set', [$domain], ['databases' => $rows], 30);
            }
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $dbs = array_map(static fn (array $d): string => $d['engine'] . ':' . $d['name'], (array) $data['databases']);
        $this->line($dbs === [] ? 'No databases in bundles of this site.' : 'Bundles include: ' . implode(', ', $dbs));

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->error('verify requires --file=<local path or Spaces object key>');

            return self::INVALID;
        }

        try {
            $dest = (bool) $this->option('spaces') ? 'spaces' : 'local';
            if ((bool) $this->option('local')) {
                $dest = 'local';
            }
            $stdin = $this->stdinFor($dest);
            $stdin['destination'] = $dest;
            if ((bool) $this->option('deep')) {
                $stdin['deep'] = true;
            }
            $data = $this->brokerData('backup.verify', [$file], $stdin, 1800, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->line('key      : ' . (string) ($data['key'] ?? ''));
        $this->line('kind     : ' . (string) ($data['kind'] ?? ''));
        $this->line('format   : ' . (string) ($data['format'] ?? ''));
        $this->line('size     : ' . (string) ($data['plain_bytes'] ?? '') . ' bytes (decrypted)');
        $this->line('sha256   : ' . (string) ($data['sha256'] ?? ''));
        $structure = is_array($data['structure'] ?? null) ? $data['structure'] : [];
        $this->line('structure: ' . (string) ($structure['detail'] ?? 'not checked'));
        if (is_array($data['restore_check'] ?? null)) {
            $this->line('restore  : ' . (string) ($data['restore_check']['detail'] ?? ''));
        }
        $this->newLine();

        if (($data['authenticated'] ?? false) === true) {
            $this->info('Verified: integrity authenticated and structure readable.');

            return self::SUCCESS;
        }

        // Structurally fine, but the legacy format cannot prove it was not altered.
        $this->warn((string) ($data['note'] ?? 'Legacy archive: not authenticated.'));

        return self::SUCCESS;
    }

    private function badAction(): int
    {
        $this->error('Usage: azerioid backup create|list|restore|verify [--local|--spaces] …');

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
            // Listing reads no archive contents, so it never needs the passphrase (KI-1).
            $stdin = $this->stdinFor($dest, withPassphrase: false);
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
                ($o['legacy'] ?? false) ? 'legacy (no auth)' : (string) ($o['format'] ?? ''),
            ];
        }

        return $this->emitTable(['key', 'kind', 'size', 'last_modified', 'format'], $rows);
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
    private function stdinFor(string $destination, bool $withPassphrase = true): array
    {
        $pass = $withPassphrase ? ['passphrase' => $this->passphrase()] : [];
        if ($destination === 'local') {
            return $pass + ['destination' => 'local'];
        }

        $spaces = RunScheduledBackup::spacesStdin();
        if ($spaces === null) {
            throw new \RuntimeException(
                'Spaces credentials are not configured. Save them in the Backups UI, or use --local.'
            );
        }

        return ['spaces' => $spaces] + $pass + ['destination' => 'spaces'];
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
