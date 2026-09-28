<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\Actions\BackupRestore;
use AzerioidPanel\Broker\Actions\BackupRun;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyParser;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Cron\CronManager;
use AzerioidPanel\Broker\Cron\CronStore;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;
use AzerioidPanel\Broker\Supervisor\SupervisorManager;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\DockerSettings;
use AzerioidPanel\Broker\Vhost\VhostUser;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * A vhost bundle (B6 / request #8, ADR A52): one site's files, configuration, runtime state and
 * databases, as a manifest plus independently restorable parts (operator decision: not one
 * monolithic archive — partial restore is the common need, and a 20 GB monolith cannot be
 * restored on a small host).
 *
 *   <root>/vhost/<domain>/<stamp>/manifest.lacmp2.bin     parts, sizes, sha256, identity
 *   <root>/vhost/<domain>/<stamp>/files.lacmp2.bin        the site's top directory (tar.gz)
 *   <root>/vhost/<domain>/<stamp>/config.lacmp2.bin       vhost config, Docker settings + env,
 *                                                         runtime metadata, Supervisor programs,
 *                                                         the site's cron jobs
 *   <root>/vhost/<domain>/<stamp>/db-<engine>-<name>.lacmp2.bin   one dump per associated database
 *
 * where <root> is the local backup directory or `azerioid/` in Spaces. Every part is LACMP2.
 *
 * Not in a bundle, deliberately: TLS private keys (Caddy re-issues HTTP-01 certificates on its
 * own and the panel re-issues DNS-01 ones — a restored key is a liability, not a convenience),
 * and mail (Maildirs have their own lifecycle; see A36). Restore is to the same domain only
 * (operator decision).
 */
final class VhostBundle
{
    public const SETTINGS_DIR = '/var/lib/azerioid-panel/vhost-bundles';

    public const ENGINES = ['mariadb', 'postgresql', 'mongodb'];

    public const MAX_DATABASES = 10;

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    // -------------------------------------------------------- association

    /** @return array{databases: list<array{engine:string, name:string}>} */
    public function settings(string $domain): array
    {
        $domain = Validator::domain($domain);
        $path = $this->settingsPath($domain);
        if (!$this->runtime->fileExists($path)) {
            return ['databases' => []];
        }
        $decoded = json_decode($this->runtime->readFile($path), true);
        $dbs = [];
        foreach (is_array($decoded['databases'] ?? null) ? $decoded['databases'] : [] as $row) {
            if (is_array($row) && in_array($row['engine'] ?? '', self::ENGINES, true) && is_string($row['name'] ?? null)) {
                $dbs[] = ['engine' => $row['engine'], 'name' => $row['name']];
            }
        }

        return ['databases' => $dbs];
    }

    /**
     * Which databases belong to a site. Nothing records this anywhere else: db.list and
     * vhost.list are unrelated, so a bundle cannot guess.
     *
     * @param  array<string,mixed>  $input
     * @return array{databases: list<array{engine:string, name:string}>}
     */
    public function saveSettings(string $domain, array $input): array
    {
        $domain = Validator::domain($domain);
        $this->findVhost($domain);
        $rows = $input['databases'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_DATABASES) {
            throw new BrokerException('databases must be a list of at most ' . self::MAX_DATABASES . ' {engine, name}.', 2);
        }
        $dbs = [];
        $seen = [];
        foreach ($rows as $row) {
            $engine = strtolower(trim((string) ($row['engine'] ?? '')));
            if (!in_array($engine, self::ENGINES, true)) {
                throw new BrokerException('engine must be mariadb, postgresql or mongodb.', 2);
            }
            $name = Validator::dbName((string) ($row['name'] ?? ''));
            if ($name === 'all' || isset($seen[$engine . '/' . $name])) {
                continue;
            }
            $seen[$engine . '/' . $name] = true;
            $dbs[] = ['engine' => $engine, 'name' => $name];
        }
        $this->runtime->mkdir(self::SETTINGS_DIR, 0750);
        $this->runtime->writeFile($this->settingsPath($domain), json_encode(['databases' => $dbs], JSON_PRETTY_PRINT) . "\n", 0640);

        return ['databases' => $dbs];
    }

    // ---------------------------------------------------------------- run

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(string $domain, array $input): array
    {
        $domain = Validator::domain($domain);
        $passphrase = Validator::password((string) ($input['passphrase'] ?? ''));
        $destination = $this->destination($input);
        $kdf = isset($input['kdf']) ? (string) $input['kdf'] : null;
        [$vhost, $confPath] = $this->findVhost($domain);
        $root = (string) ($vhost['root'] ?? '');
        $top = $this->siteTop($root);
        $stamp = gmdate('Ymd\THis') . 'Z';
        $client = $destination === 'spaces' ? SpacesClient::fromInput($input['spaces'] ?? []) : null;
        $pipeline = new BackupPipeline($this->runtime);
        $written = [];
        $parts = [];

        try {
            if ($top !== null && $this->runtime->isDir($top)) {
                $site = basename($top);
                $parts[] = $this->writePart($destination, $client, $domain, $stamp, 'files', $pipeline, $passphrase, $kdf, [
                    'command' => [
                        '/usr/bin/tar', '-C', rtrim($this->config->wwwRoot, '/'), '-czf', '-',
                        '--exclude=' . $site . '/node_modules', '--exclude=' . $site . '/storage/logs',
                        $site,
                    ],
                    'cleanup' => static function (): void {
                    },
                ], $written) + ['site' => $site];
            }

            $staging = $this->stageConfig($domain, $vhost, $confPath);
            try {
                $parts[] = $this->writePart($destination, $client, $domain, $stamp, 'config', $pipeline, $passphrase, $kdf, [
                    'command' => ['/usr/bin/tar', '-C', $staging, '-czf', '-', '.'],
                    'cleanup' => static function (): void {
                    },
                ], $written);
            } finally {
                $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $staging], null, 60);
            }

            foreach ($this->settings($domain)['databases'] as $db) {
                $driver = (new BackupEngines($this->config, $this->runtime))->for($db['engine']);
                $spec = $driver->dumpCommand($db['name']);
                $parts[] = $this->writePart($destination, $client, $domain, $stamp, 'db-' . $db['engine'] . '-' . $db['name'], $pipeline, $passphrase, $kdf, [
                    'command' => $spec['command'],
                    'cleanup' => $spec['cleanup'],
                ], $written) + ['engine' => $db['engine'], 'database' => $db['name']];
            }

            $manifest = [
                'format' => 'azerioid-vhost-bundle',
                'version' => 1,
                'domain' => $domain,
                'created_at' => $this->runtime->now(),
                'root' => $root,
                'top' => $top,
                'identity' => VhostUser::username($domain),
                'runtime' => (string) ($vhost['runtime'] ?? 'fpm'),
                'engine' => (string) ($vhost['engine'] ?? 'caddy'),
                'parts' => $parts,
                'not_included' => ['tls-private-keys', 'mail'],
            ];
            $cipher = ArchiveCipher::encryptBlob((string) json_encode($manifest, JSON_UNESCAPED_SLASHES), $passphrase, $kdf);
            $key = $this->partKey($destination, $domain, $stamp, 'manifest');
            if ($client !== null) {
                $client->put($key, $cipher);
            } else {
                $this->runtime->writeFile($key, $cipher, 0600);
            }
            $written[] = $key;
        } catch (\Throwable $e) {
            // A bundle is complete or it is not there: half a bundle restores half a site.
            foreach ($written as $key) {
                try {
                    $client !== null ? $client->delete($key) : $this->runtime->deleteFile($key);
                } catch (\Throwable) {
                }
            }
            if ($client === null) {
                $this->runtime->exec(['/bin/rmdir', $this->bundleDir($domain, $stamp)], null, 10);
            }
            throw $e instanceof BrokerException ? $e : new BrokerException($e->getMessage(), 1);
        }

        return [
            'domain' => $domain,
            'bundle' => $stamp,
            'destination' => $destination,
            'parts' => $parts,
            'size' => array_sum(array_column($parts, 'size')),
        ];
    }

    /**
     * @param  array{command:list<string>, cleanup:callable():void}  $source
     * @param  list<string>  $written
     * @return array{part:string, key:string, size:int, sha256:string, plain_bytes:int}
     */
    private function writePart(
        string $destination,
        ?SpacesClient $client,
        string $domain,
        string $stamp,
        string $part,
        BackupPipeline $pipeline,
        string $passphrase,
        ?string $kdf,
        array $source,
        array &$written,
    ): array {
        $key = $this->partKey($destination, $domain, $stamp, $part);
        $sink = $client !== null ? new SpacesArchiveSink($client, $key) : new LocalArchiveSink($this->runtime, $key);
        try {
            $meta = $pipeline->run($source['command'], $sink, $passphrase, $kdf);
        } finally {
            ($source['cleanup'])();
        }
        $written[] = $key;

        return ['part' => $part, 'key' => $key, 'size' => (int) $meta['size'], 'sha256' => (string) $meta['sha256'], 'plain_bytes' => (int) $meta['bytes_in']];
    }

    /**
     * The site's configuration, gathered into a private staging directory and tarred from there.
     *
     * @param  array<string,mixed>  $vhost
     */
    private function stageConfig(string $domain, array $vhost, string $confPath): string
    {
        $dir = rtrim($this->config->stagingDir, '/') . '/bundle-' . bin2hex(random_bytes(8));
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        $this->runtime->mkdir($dir, 0700);
        $this->runtime->writeFile($dir . '/vhost.conf', $this->runtime->readFile($confPath), 0600);

        $docker = DockerSettings::dir($domain);
        if ($this->runtime->isDir($docker)) {
            $this->runtime->mkdir($dir . '/docker', 0700);
            foreach (['settings.json', 'env', 'ports.yml'] as $file) {
                if ($this->runtime->fileExists($docker . '/' . $file)) {
                    $this->runtime->writeFile($dir . '/docker/' . $file, $this->runtime->readFile($docker . '/' . $file), 0600);
                }
            }
        }
        foreach (['pm2-meta', 'docker-meta'] as $meta) {
            $path = '/var/lib/azerioid-panel/' . $meta . '/' . $this->slug($domain) . '.json';
            if ($this->runtime->fileExists($path)) {
                $this->runtime->writeFile($dir . '/' . $meta . '.json', $this->runtime->readFile($path), 0600);
            }
        }
        $programs = [];
        try {
            foreach ((new SupervisorManager($this->config, $this->runtime))->listPrograms()['programs'] as $row) {
                if (($row['vhost_domain'] ?? null) === $domain) {
                    $programs[] = array_intersect_key($row, array_flip(['name', 'command', 'directory', 'autostart', 'autorestart', 'vhost_domain']));
                }
            }
        } catch (BrokerException) {
            // No Supervisor on this host.
        }
        $this->runtime->writeFile($dir . '/supervisor.json', (string) json_encode($programs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600);
        $jobs = [];
        foreach ((new CronStore($this->runtime))->all() as $job) {
            if ($job->owner === $domain) {
                $jobs[] = ['schedule' => $job->schedule, 'command' => $job->command, 'enabled' => $job->enabled, 'note' => $job->note];
            }
        }
        $this->runtime->writeFile($dir . '/cron.json', (string) json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600);
        $this->runtime->writeFile($dir . '/databases.json', (string) json_encode($this->settings($domain), JSON_PRETTY_PRINT), 0600);

        return $dir;
    }

    // --------------------------------------------------------------- list

    /**
     * Bundles and their parts, from names alone (no passphrase, no download).
     *
     * @param  array<string,mixed>  $input
     * @return array{destination:string, bundles: list<array<string,mixed>>}
     */
    public function list(array $input): array
    {
        $destination = $this->destination($input);
        $bundles = [];
        if ($destination === 'spaces') {
            foreach (SpacesClient::fromInput($input['spaces'] ?? [])->list('azerioid/vhost/')['objects'] as $obj) {
                $p = explode('/', (string) $obj['key']);
                if (count($p) !== 5) {
                    continue;
                }
                $id = $p[2] . '/' . $p[3];
                $bundles[$id] ??= ['domain' => $p[2], 'bundle' => $p[3], 'parts' => [], 'size' => 0];
                $bundles[$id]['parts'][] = str_replace(BackupRun::SUFFIX, '', $p[4]);
                $bundles[$id]['size'] += (int) ($obj['size'] ?? 0);
            }
        } else {
            $base = rtrim($this->config->localBackupDir, '/') . '/vhost';
            foreach ($this->runtime->isDir($base) ? $this->runtime->listDir($base) : [] as $domain) {
                foreach ($this->runtime->isDir($base . '/' . $domain) ? $this->runtime->listDir($base . '/' . $domain) : [] as $stamp) {
                    $dir = $base . '/' . $domain . '/' . $stamp;
                    if (!$this->runtime->isDir($dir)) {
                        continue;
                    }
                    $row = ['domain' => $domain, 'bundle' => $stamp, 'parts' => [], 'size' => 0];
                    foreach ($this->runtime->listDir($dir) as $file) {
                        if (str_ends_with($file, BackupRun::SUFFIX)) {
                            $row['parts'][] = str_replace(BackupRun::SUFFIX, '', $file);
                            $row['size'] += $this->runtime->fileSize($dir . '/' . $file);
                        }
                    }
                    $bundles[$domain . '/' . $stamp] = $row;
                }
            }
        }
        foreach ($bundles as &$b) {
            sort($b['parts']);
            $b['complete'] = in_array('manifest', $b['parts'], true);
            $b['created_at'] = self::stampToIso((string) $b['bundle']);
        }
        unset($b);
        $rows = array_values($bundles);
        usort($rows, static fn (array $a, array $b): int => [$a['domain'], $b['bundle']] <=> [$b['domain'], $a['bundle']]);

        return ['destination' => $destination, 'bundles' => $rows];
    }

    // ------------------------------------------------------------ restore

    /**
     * Same-domain restore of a bundle, whole or in parts. Without apply it only reads the
     * manifest and says what would happen.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function restore(string $domain, array $input): array
    {
        $domain = Validator::domain($domain);
        $passphrase = Validator::password((string) ($input['passphrase'] ?? ''));
        $destination = $this->destination($input);
        $stamp = (string) ($input['bundle'] ?? '');
        if (!preg_match('/^\d{8}T\d{6}Z$/', $stamp)) {
            throw new BrokerException('bundle must be a bundle stamp such as 20260928T030000Z.', 2);
        }
        $client = $destination === 'spaces' ? SpacesClient::fromInput($input['spaces'] ?? []) : null;
        $manifestKey = $this->partKey($destination, $domain, $stamp, 'manifest');
        $manifest = json_decode(ArchiveCipher::decryptBlob(
            $client !== null ? $client->get($manifestKey) : $this->runtime->readFile($manifestKey),
            $passphrase
        ), true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'azerioid-vhost-bundle') {
            throw new BrokerException('Not a vhost bundle manifest.', 2);
        }
        if (($manifest['domain'] ?? '') !== $domain) {
            throw new BrokerException('This bundle belongs to ' . ($manifest['domain'] ?? '?') . '; restoring into another domain is not supported.', 3);
        }
        $available = array_column((array) $manifest['parts'], 'part');
        $wanted = $input['parts'] ?? $available;
        if (!is_array($wanted) || $wanted === []) {
            throw new BrokerException('parts must name at least one part of the bundle.', 2);
        }
        foreach ($wanted as $part) {
            if (!in_array($part, $available, true)) {
                throw new BrokerException("Part {$part} is not in this bundle (" . implode(', ', $available) . ').', 2);
            }
        }
        if (!(bool) ($input['apply'] ?? false)) {
            return ['domain' => $domain, 'bundle' => $stamp, 'applied' => false, 'manifest' => $manifest, 'would_restore' => array_values($wanted)];
        }
        Validator::typedConfirm((string) ($input['confirm'] ?? ''), strtoupper($domain));

        $results = [];
        $common = ['passphrase' => $passphrase, 'destination' => $destination, 'spaces' => $input['spaces'] ?? []];
        $restore = new BackupRestore();
        // Files first (the directory the config points at), then config, then databases.
        foreach ((array) $manifest['parts'] as $part) {
            if (!in_array($part['part'], $wanted, true) || $part['part'] !== 'files') {
                continue;
            }
            $site = (string) ($part['site'] ?? basename((string) $manifest['top']));
            $results['files'] = $restore->handle('backup.restore.files', [], $common + [
                'key' => $part['key'], 'site' => $site, 'apply' => true, 'force' => true, 'confirm' => strtoupper($site),
            ], $this->runtime, $this->config);
        }
        if (in_array('config', $wanted, true)) {
            $part = $this->partOf($manifest, 'config');
            $plain = ArchiveCipher::decryptBlob($client !== null ? $client->get($part['key']) : $this->runtime->readFile($part['key']), $passphrase);
            $results['config'] = $this->restoreConfig($domain, (string) $manifest['root'], $plain);
        } elseif (isset($results['files'])) {
            VhostUser::ensure($this->runtime, $this->config, $domain, (string) $manifest['root']);
        }
        foreach ((array) $manifest['parts'] as $part) {
            if (!in_array($part['part'], $wanted, true) || !str_starts_with((string) $part['part'], 'db-')) {
                continue;
            }
            $results[$part['part']] = $restore->handle('backup.restore.db', [], $common + [
                'key' => $part['key'], 'target' => $part['database'], 'engine' => $part['engine'],
                'overwrite' => (string) ($input['db_confirm'] ?? '') === 'OVERWRITE',
                'confirm' => (string) ($input['db_confirm'] ?? ''),
            ], $this->runtime, $this->config);
        }

        return ['domain' => $domain, 'bundle' => $stamp, 'applied' => true, 'results' => $results];
    }

    /** @return array<string,mixed> */
    private function restoreConfig(string $domain, string $root, string $plain): array
    {
        $pos = 0;
        $tar = @gzdecode($plain);
        $tar = is_string($tar) ? $tar : $plain;
        ArchiveGuard::inspectStream(static function (int $n) use ($tar, &$pos): string {
            $chunk = substr($tar, $pos, $n);
            $pos += strlen($chunk);

            return $chunk;
        });
        $staging = rtrim($this->config->stagingDir, '/') . '/bundle-restore-' . bin2hex(random_bytes(8));
        $this->runtime->mkdir($staging, 0700);
        $archive = $staging . '.tgz';
        $this->runtime->writeFile($archive, $plain, 0600);
        $done = [];
        try {
            $x = $this->runtime->exec(['/usr/bin/tar', '-xzf', $archive, '-C', $staging, '--no-same-owner', '--no-same-permissions'], null, 120);
            if (!$x->ok()) {
                throw new BrokerException('Could not unpack the config part: ' . trim($x->stderr), 1);
            }

            // The vhost config: validated before it is kept, the previous one put back if not.
            $confPath = rtrim($this->config->caddyConfD, '/') . '/' . $domain . '.conf';
            foreach ($this->runtime->glob(rtrim($this->config->caddyConfD, '/') . '/*.conf') as $file) {
                $parsed = CaddyParser::parseFile($file, $this->runtime->readFile($file), $this->config->readonlyVhosts);
                if (($parsed['domain'] ?? '') === $domain) {
                    $confPath = $file;
                }
            }
            $previous = $this->runtime->fileExists($confPath) ? $this->runtime->readFile($confPath) : null;
            $this->runtime->writeFile($confPath, $this->runtime->readFile($staging . '/vhost.conf'), 0644);
            try {
                WebServers::for($this->config)->reload($this->runtime, $this->config);
            } catch (\Throwable $e) {
                $previous !== null ? $this->runtime->writeFile($confPath, $previous, 0644) : $this->runtime->deleteFile($confPath);
                WebServers::for($this->config)->reload($this->runtime, $this->config);
                throw new BrokerException('The restored vhost config was refused by the web server and the previous one put back: ' . $e->getMessage(), 1);
            }
            $done[] = 'vhost config';
            VhostUser::ensure($this->runtime, $this->config, $domain, $root);
            $done[] = 'site identity';

            if ($this->runtime->isDir($staging . '/docker')) {
                DockerSettings::ensureDir($this->runtime, $domain);
                foreach (['settings.json' => false, 'env' => true, 'ports.yml' => true] as $file => $shared) {
                    $src = $staging . '/docker/' . $file;
                    if (!$this->runtime->fileExists($src)) {
                        continue;
                    }
                    $dest = DockerSettings::dir($domain) . '/' . $file;
                    $shared
                        ? DockerSettings::writeShared($this->runtime, $dest, $this->runtime->readFile($src))
                        : $this->runtime->writeFile($dest, $this->runtime->readFile($src), 0600);
                }
                $done[] = 'Docker settings and environment';
            }
            foreach (['pm2-meta', 'docker-meta'] as $meta) {
                if ($this->runtime->fileExists($staging . '/' . $meta . '.json')) {
                    $this->runtime->mkdir('/var/lib/azerioid-panel/' . $meta, 0750);
                    $this->runtime->writeFile('/var/lib/azerioid-panel/' . $meta . '/' . $this->slug($domain) . '.json', $this->runtime->readFile($staging . '/' . $meta . '.json'), 0640);
                }
            }

            $programs = json_decode($this->runtime->readFile($staging . '/supervisor.json'), true);
            if (is_array($programs) && $programs !== []) {
                $supervisor = new SupervisorManager($this->config, $this->runtime);
                $existing = array_column($supervisor->listPrograms()['programs'], 'name');
                foreach ($programs as $row) {
                    $spec = ['command' => $row['command'], 'directory' => $row['directory'], 'autostart' => $row['autostart'] ?? true,
                        'autorestart' => $row['autorestart'] ?? true, 'vhost_domain' => $domain];
                    in_array($row['name'], $existing, true)
                        ? $supervisor->update((string) $row['name'], $spec)
                        : $supervisor->create(['name' => $row['name']] + $spec);
                    $supervisor->control((string) $row['name'], 'restart');
                }
                $done[] = count($programs) . ' Supervisor program(s)';
            }

            $jobs = json_decode($this->runtime->readFile($staging . '/cron.json'), true);
            if (is_array($jobs) && $jobs !== []) {
                $have = [];
                foreach ((new CronStore($this->runtime))->all() as $job) {
                    $have[$job->owner . "\0" . $job->schedule . "\0" . $job->command] = true;
                }
                $cron = new CronManager($this->config, $this->runtime);
                $added = 0;
                foreach ($jobs as $job) {
                    if (!isset($have[$domain . "\0" . $job['schedule'] . "\0" . $job['command']])) {
                        $cron->add(['owner' => $domain] + $job);
                        $added++;
                    }
                }
                $done[] = $added . ' cron job(s) added (' . (count($jobs) - $added) . ' already present)';
            }
            if ($this->runtime->fileExists($staging . '/databases.json')) {
                $dbs = json_decode($this->runtime->readFile($staging . '/databases.json'), true);
                if (is_array($dbs)) {
                    $this->saveSettings($domain, $dbs);
                }
            }
        } finally {
            $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $staging], null, 60);
            if ($this->runtime->fileExists($archive)) {
                $this->runtime->deleteFile($archive);
            }
        }

        return ['restored' => $done];
    }

    // -------------------------------------------------------------- prune

    /**
     * Age-based retention for bundles: whole bundles older than $days go, but the newest
     * $minKeep of each domain always stay, however old — a site that stopped being backed up
     * keeps its last bundle.
     *
     * @param  array<string,mixed>  $input
     * @return list<string> removed bundle ids
     */
    public function pruneAge(array $input, int $days, int $minKeep, ?string $onlyDomain = null): array
    {
        $cutoff = gmdate('Ymd\THis', (int) strtotime($this->runtime->now()) - $days * 86400) . 'Z';
        $listed = $this->list($input)['bundles'];
        $byDomain = [];
        foreach ($listed as $b) {
            if ($onlyDomain === null || $b['domain'] === $onlyDomain) {
                $byDomain[$b['domain']][] = $b;
            }
        }
        $client = $this->destination($input) === 'spaces' ? SpacesClient::fromInput($input['spaces'] ?? []) : null;
        $removed = [];
        foreach ($byDomain as $domain => $rows) {
            usort($rows, static fn (array $a, array $b): int => strcmp($b['bundle'], $a['bundle']));
            $rows = array_values(array_filter($rows, static fn (array $b): bool => $b['parts'] !== []));
            foreach (array_slice($rows, max(1, $minKeep)) as $b) {
                if (strcmp($b['bundle'], $cutoff) >= 0) {
                    continue;
                }
                foreach ($b['parts'] as $part) {
                    $key = $this->partKey($client !== null ? 'spaces' : 'local', $domain, $b['bundle'], $part);
                    $client !== null ? $client->delete($key) : $this->runtime->deleteFile($key);
                }
                if ($client === null) {
                    $this->runtime->exec(['/bin/rmdir', $this->bundleDir($domain, $b['bundle'])], null, 10);
                }
                $removed[] = $domain . '/' . $b['bundle'];
            }
        }

        return $removed;
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: array<string,mixed>, 1: string} the vhost and its config file */
    private function findVhost(string $domain): array
    {
        foreach ($this->runtime->glob(rtrim($this->config->caddyConfD, '/') . '/*.conf') as $file) {
            $parsed = CaddyParser::parseFile($file, $this->runtime->readFile($file), $this->config->readonlyVhosts);
            if (($parsed['domain'] ?? '') === $domain) {
                if (!empty($parsed['readonly'])) {
                    throw new BrokerException("{$domain} is managed outside the panel; it has no bundle.", 3);
                }

                return [$parsed, $file];
            }
        }

        throw new BrokerException('Vhost config does not exist.', 3);
    }

    private function siteTop(string $root): ?string
    {
        $www = rtrim($this->config->wwwRoot, '/') . '/';
        if (!str_starts_with($root, $www)) {
            return null;
        }
        $first = explode('/', substr($root, strlen($www)))[0];

        return $first === '' ? null : $www . $first;
    }

    /** @param array<string,mixed> $manifest */
    private function partOf(array $manifest, string $name): array
    {
        foreach ((array) $manifest['parts'] as $part) {
            if (($part['part'] ?? '') === $name) {
                return $part;
            }
        }

        throw new BrokerException("Part {$name} is not in this bundle.", 2);
    }

    private function partKey(string $destination, string $domain, string $stamp, string $part): string
    {
        return $destination === 'spaces'
            ? 'azerioid/vhost/' . $domain . '/' . $stamp . '/' . $part . BackupRun::SUFFIX
            : $this->bundleDir($domain, $stamp) . '/' . $part . BackupRun::SUFFIX;
    }

    private function bundleDir(string $domain, string $stamp): string
    {
        $base = rtrim($this->config->localBackupDir, '/');
        foreach ([$base, $base . '/vhost', $base . '/vhost/' . $domain, $base . '/vhost/' . $domain . '/' . $stamp] as $dir) {
            if (!$this->runtime->isDir($dir)) {
                $this->runtime->mkdir($dir, 0750);
            }
        }

        return $base . '/vhost/' . $domain . '/' . $stamp;
    }

    /** @param array<string,mixed> $input */
    private function destination(array $input): string
    {
        $dest = strtolower(trim((string) ($input['destination'] ?? 'spaces')));

        return match ($dest) {
            'local' => 'local',
            'spaces', '' => 'spaces',
            default => throw new BrokerException('destination must be spaces or local.', 2),
        };
    }

    private function settingsPath(string $domain): string
    {
        return self::SETTINGS_DIR . '/' . $this->slug($domain) . '.json';
    }

    private function slug(string $domain): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)), '-');
    }

    private static function stampToIso(string $stamp): ?string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $stamp, $m) === 1
            ? "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}Z"
            : null;
    }
}
