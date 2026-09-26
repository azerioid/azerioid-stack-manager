<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\BackupEngines;
use AzerioidPanel\Broker\Backup\BackupPipeline;
use AzerioidPanel\Broker\Backup\LocalArchiveSink;
use AzerioidPanel\Broker\Backup\SpacesArchiveSink;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\WebServers;

final class BackupRun
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $passphrase = Validator::password((string) ($input['passphrase'] ?? ''));
        $destination = $this->destination($input);
        $kdf = isset($input['kdf']) ? (string) $input['kdf'] : null;
        $stamp = gmdate('Ymd\THis') . sprintf('%06d', (int) ((microtime(true) - floor(microtime(true))) * 1_000_000)) . 'Z';
        $keep = max(1, min(365, (int) ($input['keep'] ?? 14)));

        $source = match ($action) {
            'backup.db' => $this->dumpDbSource(
                $runtime,
                $config,
                (string) ($args[0] ?? ($input['database'] ?? 'all')),
                isset($input['engine']) ? (string) $input['engine'] : null
            ),
            'backup.files' => $this->tarSiteSource($runtime, $config, (string) ($args[0] ?? '')),
            'backup.caddy' => $this->tarCaddySource($runtime, $config, (bool) ($input['include_fpm'] ?? false)),
            default => throw new BrokerException('Unknown backup action.', 2),
        };

        // The archive is streamed straight from the source process into the sink,
        // so neither the plaintext nor the ciphertext is ever held whole (A2.3).
        if ($destination === 'local') {
            $base = rtrim($config->localBackupDir, '/');
            $dir = $base . '/' . $source['kind'] . '/' . $source['name'];
            $runtime->mkdir($base, 0750);
            $runtime->mkdir($base . '/' . $source['kind'], 0750);
            $runtime->mkdir($dir, 0750);
            $path = $dir . '/' . $stamp . '.bin';
            if ($runtime->resolveUnderBase($path, $base) === null) {
                throw new BrokerException('Local backup path escaped backup root.', 3);
            }
            $sink = new LocalArchiveSink($runtime, $path);
            $key = $path;
        } else {
            $client = SpacesClient::fromInput($input['spaces'] ?? []);
            $key = 'azerioid/' . $source['kind'] . '/' . $source['name'] . '/' . $stamp . '.bin';
            $sink = new SpacesArchiveSink($client, $key);
        }

        try {
            $meta = (new BackupPipeline($runtime))->run(
                $source['command'],
                $sink,
                $passphrase,
                $kdf,
                $source['cwd'] ?? null
            );
        } finally {
            ($source['cleanup'])();
        }

        $out = [
            'key' => $key,
            'size' => $meta['size'],
            'kind' => $source['kind'],
            'name' => $source['name'],
            'sha256' => $meta['sha256'],
            'destination' => $destination,
            'encrypted' => true,
            'format' => 'lacmp2',
            'kdf' => $meta['kdf'] === 1 ? 'argon2id' : 'pbkdf2',
            'plain_bytes' => $meta['bytes_in'],
            'engine' => $source['engine'] ?? null,
        ];

        if ($destination === 'local') {
            $out['pruned'] = $this->pruneLocal(
                $runtime,
                rtrim($config->localBackupDir, '/') . '/' . $source['kind'] . '/' . $source['name'],
                $keep
            );
        }

        return $out;
    }

    /** @param array<string,mixed> $input */
    private function destination(array $input): string
    {
        $dest = strtolower(trim((string) ($input['destination'] ?? 'spaces')));
        if ($dest === 'local') {
            return 'local';
        }
        if ($dest === 'spaces' || $dest === '') {
            return 'spaces';
        }
        throw new BrokerException('destination must be spaces or local.', 2);
    }

    /** @return list<string> */
    private function pruneLocal(Runtime $runtime, string $dir, int $keep): array
    {
        $files = [];
        foreach ($runtime->listDir($dir) as $name) {
            if (!str_ends_with($name, '.bin')) {
                continue;
            }
            $path = rtrim($dir, '/') . '/' . $name;
            if ($runtime->fileExists($path)) {
                $files[] = $path;
            }
        }
        rsort($files, SORT_STRING);
        $deleted = [];
        foreach (array_slice($files, $keep) as $old) {
            $runtime->deleteFile($old);
            $deleted[] = $old;
        }
        return $deleted;
    }

    /**
     * Each source returns the argv that writes the archive to stdout, plus a
     * cleanup closure for anything that must outlive the spawn (a credentials
     * file cannot be deleted before the child has read it).
     *
     * The engine is resolved per host, so PostgreSQL and MongoDB are backed up
     * through their own tools instead of the previous mysqldump-only path (A2.4).
     *
     * @return array{kind:string,name:string,command:list<string>,cwd:?string,cleanup:callable():void,engine:string}
     */
    private function dumpDbSource(Runtime $runtime, Config $config, string $which, ?string $engine): array
    {
        $driver = (new BackupEngines($config, $runtime))->for($engine);
        $spec = $driver->dumpCommand($which);

        return [
            'kind' => 'db',
            'name' => $spec['name'],
            'command' => $spec['command'],
            'cwd' => null,
            'cleanup' => $spec['cleanup'],
            'engine' => $driver->engine(),
        ];
    }

    /** @return array{kind:string,name:string,command:list<string>,cwd:?string,cleanup:callable():void} */
    private function tarSiteSource(Runtime $runtime, Config $config, string $site): array
    {
        $site = Validator::siteName($site);
        $root = rtrim($config->wwwRoot, '/') . '/' . $site;
        if ($runtime->resolveUnderBase($root, $config->wwwRoot) === null) {
            throw new BrokerException('Site path escaped www root.', 3);
        }
        if (!$runtime->isDir($root) && !$runtime->fileExists($root)) {
            throw new BrokerException('Site directory does not exist.', 2);
        }

        return [
            'kind' => 'files',
            'name' => $site,
            // `-` writes to stdout, so no intermediate tarball is staged on disk.
            'command' => [
                '/usr/bin/tar',
                '-C', $config->wwwRoot,
                '-czf', '-',
                '--exclude=' . $site . '/vendor',
                '--exclude=' . $site . '/node_modules',
                '--exclude=' . $site . '/storage/logs',
                $site,
            ],
            'cwd' => null,
            'cleanup' => static function (): void {
            },
        ];
    }

    /** @return array{kind:string,name:string,command:list<string>,cwd:?string,cleanup:callable():void} */
    private function tarCaddySource(Runtime $runtime, Config $config, bool $includeFpm): array
    {
        $cmd = array_merge(['/usr/bin/tar', '-czf', '-'], WebServers::for($config)->backupPaths($config));
        if ($includeFpm) {
            $cmd[] = '/etc/php';
        }

        return [
            'kind' => 'caddy',
            'name' => $includeFpm ? 'caddy-php' : 'caddy',
            'command' => $cmd,
            'cwd' => null,
            'cleanup' => static function (): void {
            },
        ];
    }

}
