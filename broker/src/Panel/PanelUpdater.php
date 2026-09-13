<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;

/**
 * Panel self-update against semver git tags (vX.Y.Z) via a managed source tree + PREFIX rsync deploy.
 *
 * PREFIX (/usr/local/lib/azerioid-panel) is not itself a git checkout — install uses rsync.
 * Updates fetch tags into paths.panel_source, check out the target tag, then redeploy.
 */
final class PanelUpdater
{
    public const CONFIRM = 'PANEL-UPDATE';
    public const CHANNEL = 'main'; // clone default branch only — releases are tags
    public const DEFAULT_REMOTE = 'https://github.com/azerioid/azerioid-stack-manager.git';
    public const LOG_SUMMARY_LIMIT = 30;
    public const TAG_LIST_LIMIT = 50;

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $source = $this->sourcePath();
        $this->ensureSourceRepo($source, null);
        $this->fetchTags($source);

        $tags = $this->listReleaseTags($source);
        $latest = Semver::latest($tags);
        $deployedCommit = $this->readDeployedCommit();
        $deployedTag = $this->readDeployedTag();
        $dirty = $this->dirtyEntries($source);

        $latestCommit = $latest !== null ? $this->revParse($source, $latest) : null;
        $updateAvailable = false;
        $upToDate = false;
        if ($latest !== null) {
            if ($deployedTag !== null) {
                $cmp = Semver::compare($deployedTag, $latest);
                $updateAvailable = $cmp < 0;
                $upToDate = $cmp === 0 && ($deployedCommit === null || $deployedCommit === $latestCommit);
            } elseif ($deployedCommit !== null && $latestCommit !== null) {
                if ($deployedCommit === $latestCommit) {
                    $updateAvailable = false;
                    $upToDate = true;
                } elseif ($this->isAncestor($source, $latestCommit, $deployedCommit)) {
                    // Installed from main tip (or similar) that is already ahead of the latest release tag.
                    $updateAvailable = false;
                    $upToDate = true;
                } else {
                    $updateAvailable = true;
                    $upToDate = false;
                }
            } else {
                $updateAvailable = true;
            }
        }

        $logLines = [];
        if ($deployedCommit !== null && $latestCommit !== null && $deployedCommit !== $latestCommit) {
            $logLines = $this->logOneline($source, $deployedCommit, $latestCommit);
        } elseif ($deployedTag !== null && $latest !== null && $deployedTag !== $latest) {
            $logLines = $this->logOneline($source, $deployedTag, $latest);
        }

        return [
            'channel' => 'tags',
            'remote' => $this->gitRemote(),
            'source_path' => $source,
            'deployed_tag' => $deployedTag,
            'deployed_commit' => $deployedCommit,
            'deployed_commit_short' => $deployedCommit !== null ? substr($deployedCommit, 0, 7) : null,
            'latest_tag' => $latest,
            'latest_commit' => $latestCommit,
            'latest_commit_short' => $latestCommit !== null ? substr($latestCommit, 0, 7) : null,
            // Back-compat keys for older UI/CLI while migrating:
            'remote_commit' => $latestCommit,
            'remote_commit_short' => $latestCommit !== null ? substr($latestCommit, 0, 7) : null,
            'update_available' => $updateAvailable,
            'up_to_date' => $upToDate,
            'dirty' => $dirty !== [],
            'dirty_entries' => $dirty,
            'log_summary' => $logLines,
            'tags' => array_slice($tags, 0, self::TAG_LIST_LIMIT),
            'tags_truncated' => count($tags) > self::TAG_LIST_LIMIT,
            'version' => $this->readVersionFile(),
            'scope' => 'panel-self-update',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(string $operationId, string $confirm, ?string $requestedTag = null): array
    {
        Validator::typedConfirm($confirm, self::CONFIRM);
        $operationId = Validator::operationId($operationId);
        $log = $this->logger($operationId);
        $source = $this->sourcePath();
        $prefix = rtrim($this->config->panelRoot, '/');
        $preCommit = $this->readDeployedCommit();
        $preTag = $this->readDeployedTag();
        $rolledBack = false;
        $targetTag = null;
        $target = null;

        try {
            $log->info('Panel self-update starting (semver tags).');
            $this->ensureSourceRepo($source, $log);

            $dirty = $this->dirtyEntries($source);
            if ($dirty !== []) {
                throw new BrokerException(
                    'Refusing panel update: source working tree is dirty. Resolve or stash local changes first: '
                    . implode(', ', array_slice($dirty, 0, 12))
                    . (count($dirty) > 12 ? ' …' : ''),
                    3
                );
            }

            $this->fetchTags($source);
            $tags = $this->listReleaseTags($source);
            if ($tags === []) {
                throw new BrokerException(
                    'No release tags (vX.Y.Z) found on origin. Create and push a semver tag before updating.',
                    3
                );
            }

            $targetTag = $this->resolveTargetTag($requestedTag, $tags);
            $target = $this->revParse($source, $targetTag);
            $log->info('Target tag: ' . $targetTag . ' (' . substr($target, 0, 7) . ')');

            if ($preCommit === null) {
                $log->warn('No COMMIT marker under PREFIX; using current source HEAD as rollback point.');
                $preCommit = $this->revParse($source, 'HEAD');
            }

            // Implicit "apply latest" must not walk backwards from an untagged tip that is
            // already past the newest release tag (fresh install from main). Explicit --v= is fine.
            if (
                $requestedTag === null
                && $preCommit !== $target
                && $this->isAncestor($source, $target, $preCommit)
            ) {
                throw new BrokerException(
                    'Refusing panel update: deployed commit '
                    . substr($preCommit, 0, 7)
                    . ' is already ahead of latest tag '
                    . $targetTag
                    . '. Create a newer release tag, or pass --v=<tag> explicitly to move to that tag.',
                    3
                );
            }

            $downgrade = $preTag !== null && Semver::compare($targetTag, $preTag) < 0;
            if ($downgrade) {
                $log->warn(
                    'Downgrade requested: ' . $preTag . ' → ' . $targetTag
                    . '. Migrations applied by newer releases are NOT automatically reversed; '
                    . 'verify schema compatibility before relying on this panel.'
                );
            }

            if ($preCommit === $target && $preTag === $targetTag) {
                $log->info('Already on ' . $targetTag . ' — nothing to apply.');
                $this->writeDeployedMarkers($target, $targetTag);

                return [
                    'channel' => 'tags',
                    'from_tag' => $preTag,
                    'to_tag' => $targetTag,
                    'from_commit' => $preCommit,
                    'to_commit' => $target,
                    'changed' => false,
                    'downgrade' => false,
                    'rolled_back' => false,
                    'migrations' => 'none',
                    'operation_id' => $operationId,
                    'log_path' => $log->path(),
                ];
            }

            $log->info('Rollback point: ' . ($preTag ?? '(untagged)') . ' @ ' . $preCommit);

            $checkout = $this->git($source, ['checkout', '-f', '--detach', $target], 60);
            if (!$checkout->ok()) {
                throw new BrokerException(
                    'Could not check out ' . $targetTag . ': ' . $this->execDetail($checkout),
                    1
                );
            }
            $log->info('Checked out ' . $targetTag . '.');

            $this->deployFromSource($source, $prefix, $log);
            $migrations = $this->runMigrations($prefix, $log);
            $this->rebuildCaches($prefix, $log);
            $this->writeDeployedMarkers($target, $targetTag);
            $this->writeVersionFromSource($source, $prefix);
            $this->reloadRuntime($log, deferQueueRestart: true);

            $log->info('Panel self-update completed successfully → ' . $targetTag);

            return [
                'channel' => 'tags',
                'from_tag' => $preTag,
                'to_tag' => $targetTag,
                'from_commit' => $preCommit,
                'to_commit' => $target,
                'changed' => true,
                'downgrade' => $downgrade,
                'rolled_back' => false,
                'migrations' => $migrations,
                'operation_id' => $operationId,
                'log_path' => $log->path(),
            ];
        } catch (\Throwable $e) {
            $log->warn('Update failed: ' . $e->getMessage());
            if ($preCommit !== null) {
                try {
                    $log->info('Rolling back to ' . ($preTag ?? substr($preCommit, 0, 7)) . ' …');
                    $this->git($source, ['checkout', '-f', '--detach', $preCommit], 60);
                    $this->deployFromSource($source, $prefix, $log);
                    $this->runMigrations($prefix, $log);
                    $this->rebuildCaches($prefix, $log);
                    $this->writeDeployedMarkers($preCommit, $preTag);
                    $this->writeVersionFromSource($source, $prefix);
                    $this->reloadRuntime($log, deferQueueRestart: true);
                    $rolledBack = true;
                    $log->info(
                        'Rollback completed; panel left on '
                        . ($preTag ?? substr($preCommit, 0, 7)) . '.'
                    );
                } catch (\Throwable $rollbackError) {
                    $log->warn('Rollback failed: ' . $rollbackError->getMessage());
                    throw new BrokerException(
                        'Panel update failed and rollback also failed. Manual recovery required. '
                        . 'Update error: ' . $e->getMessage()
                        . ' Rollback error: ' . $rollbackError->getMessage(),
                        1
                    );
                }
            }

            throw new BrokerException(
                ($rolledBack
                    ? 'Panel update failed and was rolled back to the previous release. '
                    : 'Panel update failed (no rollback point available). ')
                . $e->getMessage(),
                1
            );
        }
    }

    /** @return array<string, mixed> */
    public function operationLog(string $operationId): array
    {
        $operationId = Validator::operationId($operationId);
        $path = $this->logPath($operationId);
        if (!$this->runtime->fileExists($path)) {
            return ['operation_id' => $operationId, 'path' => $path, 'lines' => [], 'missing' => true];
        }
        $lines = array_values(array_filter(explode("\n", trim($this->runtime->readFile($path)))));

        return [
            'operation_id' => $operationId,
            'path' => $path,
            'lines' => $lines,
            'missing' => false,
        ];
    }

    private function sourcePath(): string
    {
        return rtrim($this->config->panelSourcePath, '/');
    }

    private function gitRemote(): string
    {
        $remote = trim($this->config->panelGitRemote);

        return $remote !== '' ? $remote : self::DEFAULT_REMOTE;
    }

    private function ensureSourceRepo(string $source, ?OperationLogger $log): void
    {
        $gitDir = $source . '/.git';
        if ($this->runtime->isDir($gitDir) || $this->runtime->fileExists($gitDir)) {
            return;
        }

        $log?->info('Cloning panel source into ' . $source);
        $parent = dirname($source);
        if (!$this->runtime->isDir($parent)) {
            $this->runtime->mkdir($parent, 0750);
        }
        if ($this->runtime->isDir($source)) {
            $listing = $this->runtime->listDir($source);
            if ($listing !== []) {
                throw new BrokerException(
                    "Panel source path {$source} exists but is not a git repository. Move or empty it, then retry.",
                    3
                );
            }
            $this->runtime->exec(['/bin/rmdir', $source], null, 10);
        }

        $clone = $this->runtime->exec([
            '/usr/bin/git',
            'clone',
            '--branch',
            self::CHANNEL,
            $this->gitRemote(),
            $source,
        ], null, 300);
        if (!$clone->ok()) {
            $this->runtime->exec(['/bin/rm', '-rf', $source], null, 60);
            throw new BrokerException('git clone failed: ' . $this->execDetail($clone), 1);
        }

        // Prefer matching deployed commit when known.
        $deployed = $this->readDeployedCommit();
        if ($deployed !== null) {
            $this->git($source, ['checkout', '-f', '-B', 'main', $deployed], 60);
        }
    }

    /** @return list<string> */
    private function dirtyEntries(string $source): array
    {
        $status = $this->git($source, ['status', '--porcelain'], 30);
        if (!$status->ok()) {
            throw new BrokerException('git status failed: ' . $this->execDetail($status), 1);
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($status->stdout)) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function revParse(string $source, string $ref): string
    {
        // Peel annotated tags to the underlying commit (plain rev-parse returns the tag object).
        $r = $this->git($source, ['rev-parse', $ref . '^{commit}'], 30);
        $hash = $r->ok() ? trim($r->stdout) : '';
        if (!preg_match('/^[0-9a-f]{40}$/', $hash)) {
            $r = $this->git($source, ['rev-parse', $ref], 30);
            if (!$r->ok()) {
                throw new BrokerException('git rev-parse ' . $ref . ' failed: ' . $this->execDetail($r), 1);
            }
            $hash = trim($r->stdout);
        }
        if (!preg_match('/^[0-9a-f]{40}$/', $hash)) {
            throw new BrokerException('Unexpected git rev-parse output for ' . $ref . '.', 1);
        }

        return $hash;
    }

    /** True when $maybeAncestor is an ancestor of (or equal to) $descendant. */
    private function isAncestor(string $source, string $maybeAncestor, string $descendant): bool
    {
        $r = $this->git($source, ['merge-base', '--is-ancestor', $maybeAncestor, $descendant], 30);

        return $r->ok();
    }

    /** @return list<string> */
    private function logOneline(string $source, string $from, string $to): array
    {
        $range = $from . '..' . $to;
        $r = $this->git($source, ['log', $range, '--oneline', '-n', (string) self::LOG_SUMMARY_LIMIT], 30);
        if (!$r->ok()) {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($r->stdout)) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));
    }

    private function fetchTags(string $source): void
    {
        $fetch = $this->git($source, ['fetch', '--prune', '--tags', 'origin'], 120);
        if (!$fetch->ok()) {
            // Older remotes may reject --tags with prune; retry without prune flags combo.
            $fetch = $this->git($source, ['fetch', '--tags', 'origin'], 120);
        }
        if (!$fetch->ok()) {
            throw new BrokerException('git fetch --tags failed: ' . $this->execDetail($fetch), 1);
        }
    }

    /** @return list<string> newest-first normalized release tags */
    private function listReleaseTags(string $source): array
    {
        $listed = $this->git($source, ['tag', '-l', 'v*'], 30);
        if (!$listed->ok()) {
            throw new BrokerException('git tag -l failed: ' . $this->execDetail($listed), 1);
        }
        $raw = preg_split('/\r\n|\r|\n/', trim($listed->stdout)) ?: [];
        $tags = [];
        foreach ($raw as $line) {
            $line = trim($line);
            if ($line !== '') {
                $tags[] = $line;
            }
        }

        return Semver::sortDescending($tags);
    }

    /**
     * @param  list<string>  $tags
     */
    private function resolveTargetTag(?string $requestedTag, array $tags): string
    {
        if ($requestedTag === null || trim($requestedTag) === '') {
            $latest = Semver::latest($tags);
            if ($latest === null) {
                throw new BrokerException('No valid semver tags available.', 3);
            }

            return $latest;
        }

        $normalized = Semver::normalize($requestedTag);
        if ($normalized === null) {
            throw new BrokerException(
                'Invalid tag "' . trim($requestedTag) . '". Expected semver like v0.2.1.',
                2
            );
        }

        $available = [];
        foreach ($tags as $tag) {
            $available[Semver::normalize($tag) ?? $tag] = $tag;
        }
        if (!isset($available[$normalized])) {
            $suggestion = Semver::suggest($normalized, $tags);
            $hint = $suggestion !== null ? ' Did you mean ' . $suggestion . '?' : '';
            throw new BrokerException(
                'Unknown release tag "' . $normalized . '".' . $hint
                . ' Available (newest first): ' . implode(', ', array_slice($tags, 0, 12)),
                3
            );
        }

        return $normalized;
    }

    private function readDeployedCommit(): ?string
    {
        $path = rtrim($this->config->panelRoot, '/') . '/COMMIT';
        if (!$this->runtime->fileExists($path)) {
            return null;
        }
        $hash = strtolower(trim($this->runtime->readFile($path)));
        if (!preg_match('/^[0-9a-f]{40}$/', $hash)) {
            return null;
        }

        return $hash;
    }

    private function readDeployedTag(): ?string
    {
        $path = rtrim($this->config->panelRoot, '/') . '/TAG';
        if (!$this->runtime->fileExists($path)) {
            return null;
        }

        return Semver::normalize(trim($this->runtime->readFile($path)));
    }

    private function writeDeployedMarkers(string $hash, ?string $tag): void
    {
        $prefix = rtrim($this->config->panelRoot, '/');
        $this->runtime->writeFile($prefix . '/COMMIT', $hash . "\n", 0644);
        $tagPath = $prefix . '/TAG';
        if ($tag !== null && Semver::isValid($tag)) {
            $this->runtime->writeFile($tagPath, Semver::normalize($tag) . "\n", 0644);
        } elseif ($this->runtime->fileExists($tagPath)) {
            $this->runtime->deleteFile($tagPath);
        }
    }

    private function writeDeployedCommit(string $hash): void
    {
        $this->writeDeployedMarkers($hash, $this->readDeployedTag());
    }

    private function readVersionFile(): ?string
    {
        $path = rtrim($this->config->panelRoot, '/') . '/VERSION';
        if (!$this->runtime->fileExists($path)) {
            return null;
        }

        return trim($this->runtime->readFile($path));
    }

    private function writeVersionFromSource(string $source, string $prefix): void
    {
        $src = $source . '/VERSION';
        if (!$this->runtime->fileExists($src)) {
            return;
        }
        $this->runtime->writeFile($prefix . '/VERSION', $this->runtime->readFile($src), 0644);
    }

    private function deployFromSource(string $source, string $prefix, OperationLogger $log): void
    {
        $log->info('Deploying broker + panel app from source → ' . $prefix);
        $webUser = $this->config->webUser;

        if (!$this->runtime->isDir($prefix)) {
            $this->runtime->mkdir($prefix, 0751);
        }

        // Broker PHP sources (root-only).
        $this->runtime->exec(['/bin/rm', '-rf', $prefix . '/src'], null, 60);
        $this->assertOk(
            $this->runtime->exec(['/bin/cp', '-a', $source . '/broker/src', $prefix . '/src'], null, 60),
            'copy broker/src'
        );
        $this->runtime->exec(['/bin/chmod', '-R', 'go-rwx', $prefix . '/src'], null, 30);
        $this->assertOk(
            $this->runtime->exec(['/bin/cp', '-f', $source . '/broker/broker', $prefix . '/broker'], null, 30),
            'install broker launcher'
        );
        $this->runtime->chmod($prefix . '/broker', 0750);
        $this->assertOk(
            $this->runtime->exec(['/bin/cp', '-f', $source . '/broker/broker.php', $prefix . '/broker.php'], null, 30),
            'install broker.php'
        );
        if ($this->runtime->fileExists($source . '/deploy/bin/azerioid')) {
            $this->assertOk(
                $this->runtime->exec(['/bin/cp', '-f', $source . '/deploy/bin/azerioid', '/usr/local/bin/azerioid'], null, 30),
                'install azerioid CLI'
            );
            $this->runtime->chmod('/usr/local/bin/azerioid', 0755);
        }

        // Keep sudoers aligned with broker.json web_user (FPM/queue must be able to invoke the broker).
        $sudoers = "/etc/sudoers.d/azerioid-panel";
        $sudoBody = "# AZERIOID Stack Manager — sudoers (panel self-update)\n"
            . "Defaults:{$webUser} !requiretty\n"
            . "Defaults:{$webUser} umask=0022\n"
            . "{$webUser} ALL=(root) NOPASSWD: {$prefix}/broker\n";
        $this->runtime->writeFile($sudoers, $sudoBody, 0440);
        $visudo = $this->runtime->exec(['/usr/sbin/visudo', '-c'], null, 15);
        if (!$visudo->ok()) {
            throw new BrokerException('Generated sudoers failed visudo -c: ' . $this->execDetail($visudo), 1);
        }

        // Registry
        if ($this->runtime->isDir($source . '/registry')) {
            if (!$this->runtime->isDir($prefix . '/registry')) {
                $this->runtime->mkdir($prefix . '/registry', 0755);
            }
            $this->assertOk(
                $this->runtime->exec([
                    '/usr/bin/rsync', '-a', '--delete',
                    $source . '/registry/',
                    $prefix . '/registry/',
                ], null, 120),
                'rsync registry'
            );
        }

        // Web app
        if (!$this->runtime->isDir($prefix . '/web')) {
            $this->runtime->mkdir($prefix . '/web', 0750);
        }
        $this->assertOk(
            $this->runtime->exec([
                '/usr/bin/rsync', '-a', '--delete',
                '--exclude', '.env',
                '--exclude', 'vendor/',
                '--exclude', 'node_modules/',
                '--exclude', 'tests/',
                '--exclude', 'bootstrap/cache/*.php',
                '--exclude', 'storage/logs/',
                '--exclude', 'storage/framework/cache/',
                '--exclude', 'storage/framework/sessions/',
                '--exclude', 'storage/framework/views/',
                '--exclude', 'storage/framework/tmp/',
                $source . '/web/',
                $prefix . '/web/',
            ], null, 180),
            'rsync web/'
        );

        $brokerLib = $prefix . '/web/lib/azerioid-broker';
        if (!$this->runtime->isDir($prefix . '/web/lib')) {
            $this->runtime->mkdir($prefix . '/web/lib', 0750);
        }
        if (!$this->runtime->isDir($brokerLib)) {
            $this->runtime->mkdir($brokerLib, 0750);
        }
        $this->assertOk(
            $this->runtime->exec([
                '/usr/bin/rsync', '-a', '--delete',
                $source . '/broker/src/',
                $brokerLib . '/',
            ], null, 120),
            'rsync web/lib/azerioid-broker'
        );

        foreach ([
            $prefix . '/web/storage',
            $prefix . '/web/storage/logs',
            $prefix . '/web/storage/framework',
            $prefix . '/web/storage/framework/cache',
            $prefix . '/web/storage/framework/cache/data',
            $prefix . '/web/storage/framework/sessions',
            $prefix . '/web/storage/framework/views',
            $prefix . '/web/storage/framework/tmp',
            $prefix . '/web/storage/app',
            $prefix . '/web/bootstrap/cache',
        ] as $dir) {
            if (!$this->runtime->isDir($dir)) {
                $this->runtime->mkdir($dir, 0770);
            }
        }

        $this->runtime->exec(['/usr/bin/chown', '-R', $webUser . ':' . $webUser, $prefix . '/web'], null, 120);

        // Panel SQLite + state dir must remain accessible to the FPM/queue user (web_user).
        $stateDir = '/var/lib/azerioid-panel';
        if ($this->runtime->isDir($stateDir)) {
            $this->runtime->exec(['/usr/bin/chown', $webUser . ':' . $webUser, $stateDir], null, 30);
            $db = $stateDir . '/panel.sqlite';
            if ($this->runtime->fileExists($db)) {
                $this->runtime->exec(['/usr/bin/chown', $webUser . ':' . $webUser, $db], null, 15);
                foreach ([$db . '-shm', $db . '-wal'] as $side) {
                    if ($this->runtime->fileExists($side)) {
                        $this->runtime->exec(['/usr/bin/chown', $webUser . ':' . $webUser, $side], null, 15);
                    }
                }
            }
        }

        $php = $this->phpBin();
        $composer = $this->composerBin();
        $log->info('composer install --no-dev --optimize-autoloader');
        $composerHome = '/tmp/azerioid-composer-' . getmypid();
        $this->runtime->mkdir($composerHome, 0750);
        $this->runtime->chown($composerHome, $webUser, $webUser);

        $composerCmd = $this->runAsWeb([
            '/usr/bin/env',
            'COMPOSER_HOME=' . $composerHome,
            $php,
            $composer,
            'install',
            '--no-dev',
            '--optimize-autoloader',
            '--no-interaction',
            '--no-scripts',
        ], $prefix . '/web', 600);
        $this->runtime->exec(['/bin/rm', '-rf', $composerHome], null, 30);
        if (!$composerCmd->ok()) {
            throw new BrokerException('composer install failed: ' . $this->execDetail($composerCmd), 1);
        }

        foreach (['packages.php', 'services.php'] as $cacheFile) {
            $p = $prefix . '/web/bootstrap/cache/' . $cacheFile;
            if ($this->runtime->fileExists($p)) {
                $this->runtime->deleteFile($p);
            }
        }

        $this->assertOk(
            $this->runAsWeb([$php, 'artisan', 'package:discover', '--ansi', '--no-interaction'], $prefix . '/web', 120),
            'artisan package:discover'
        );
        $dump = $this->runAsWeb([
            '/usr/bin/env',
            'COMPOSER_HOME=/tmp',
            $php,
            $composer,
            'dump-autoload',
            '-o',
            '--no-interaction',
            '--no-scripts',
        ], $prefix . '/web', 180);
        if (!$dump->ok()) {
            $log->warn('composer dump-autoload warned: ' . $this->execDetail($dump));
        }

        $log->info('Deploy file sync + composer complete.');
    }

    private function runMigrations(string $prefix, OperationLogger $log): string
    {
        $php = $this->phpBin();
        $status = $this->runAsWeb([$php, 'artisan', 'migrate:status', '--no-interaction'], $prefix . '/web', 120);
        $before = $status->stdout . "\n" . $status->stderr;

        $log->info('Running php artisan migrate --force');
        $migrate = $this->runAsWeb([$php, 'artisan', 'migrate', '--force', '--no-interaction'], $prefix . '/web', 300);
        if (!$migrate->ok()) {
            throw new BrokerException('artisan migrate failed: ' . $this->execDetail($migrate), 1);
        }
        $out = trim($migrate->stdout . "\n" . $migrate->stderr);
        $log->info($out !== '' ? $out : 'migrate finished with empty output.');

        if (str_contains($out, 'Nothing to migrate') || str_contains($out, 'No pending migrations')) {
            return 'none';
        }
        if (preg_match('/Migrating:/', $out) || preg_match('/\d+\s+migrated/', $out)) {
            return 'ran';
        }
        // Fallback: if status had Pending and migrate ok → ran
        if (str_contains($before, 'Pending')) {
            return 'ran';
        }

        return 'none';
    }

    private function rebuildCaches(string $prefix, OperationLogger $log): void
    {
        $php = $this->phpBin();
        $log->info('Rebuilding Laravel caches');
        $this->runAsWeb([$php, 'artisan', 'optimize:clear', '--no-interaction'], $prefix . '/web', 120);
        $cfg = $this->runAsWeb([$php, 'artisan', 'config:cache', '--no-interaction'], $prefix . '/web', 120);
        if (!$cfg->ok()) {
            $log->warn('config:cache failed: ' . $this->execDetail($cfg));
        }
        $view = $this->runAsWeb([$php, 'artisan', 'view:cache', '--no-interaction'], $prefix . '/web', 120);
        if (!$view->ok()) {
            $log->warn('view:cache failed: ' . $this->execDetail($view));
        }
        $route = $this->runAsWeb([$php, 'artisan', 'route:cache', '--no-interaction'], $prefix . '/web', 120);
        if (!$route->ok()) {
            $log->warn('route:cache skipped/failed (non-fatal): ' . $this->execDetail($route));
        }
    }

    private function reloadRuntime(OperationLogger $log, bool $deferQueueRestart): void
    {
        $fpmUnit = $this->config->panelFpmUnit;
        $log->info('Reloading PHP-FPM unit ' . $fpmUnit);
        try {
            Systemd::control($this->runtime, 'reload', $fpmUnit);
        } catch (BrokerException $e) {
            $log->warn('reload failed, trying restart: ' . $e->getMessage());
            Systemd::control($this->runtime, 'restart', $fpmUnit);
        }

        $queueUnit = $this->config->panelRuntimeQueueUnit;
        if ($deferQueueRestart) {
            $log->info('Scheduling deferred restart of ' . $queueUnit . ' (so the apply job can finish).');
            $unitName = 'azerioid-panel-queue-restart-' . substr(hash('sha256', (string) microtime(true)), 0, 8);
            $deferred = $this->runtime->exec([
                '/usr/bin/systemd-run',
                '--unit=' . $unitName,
                '--on-active=5s',
                '--description=AZERIOID panel queue restart after self-update',
                '/bin/systemctl',
                'restart',
                $queueUnit,
            ], null, 30);
            if (!$deferred->ok()) {
                $log->warn('systemd-run defer failed; restarting queue immediately: ' . $this->execDetail($deferred));
                Systemd::control($this->runtime, 'restart', $queueUnit);
            }
        } else {
            Systemd::control($this->runtime, 'restart', $queueUnit);
        }
    }

    /** @param list<string> $args */
    private function git(string $source, array $args, int $timeout): \AzerioidPanel\Broker\ExecResult
    {
        return $this->runtime->exec(array_merge(['/usr/bin/git', '-C', $source], $args), null, $timeout);
    }

    /** @param list<string> $command */
    private function runAsWeb(array $command, string $cwd, int $timeout): \AzerioidPanel\Broker\ExecResult
    {
        $runuser = $this->runuserBin();
        $shell = 'cd ' . escapeshellarg($cwd) . ' && exec ' . implode(' ', array_map('escapeshellarg', $command));

        return $this->runtime->exec([$runuser, '-u', $this->config->webUser, '--', '/bin/bash', '-lc', $shell], null, $timeout);
    }

    private function runuserBin(): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }
        throw new BrokerException('runuser is required for panel self-update composer/artisan steps.', 1);
    }

    private function phpBin(): string
    {
        $ver = $this->config->panelPhpVersion !== '' ? $this->config->panelPhpVersion : '8.4';
        foreach (['/usr/bin/php' . $ver, '/usr/bin/php'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }
        $which = $this->runtime->exec(['/usr/bin/which', 'php'], null, 10);
        if ($which->ok() && trim($which->stdout) !== '') {
            return trim($which->stdout);
        }
        throw new BrokerException('PHP CLI binary not found for panel self-update.', 1);
    }

    private function composerBin(): string
    {
        foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }
        $which = $this->runtime->exec(['/usr/bin/which', 'composer'], null, 10);
        if ($which->ok() && trim($which->stdout) !== '') {
            return trim($which->stdout);
        }
        throw new BrokerException('composer binary not found for panel self-update.', 1);
    }

    private function logger(string $operationId): OperationLogger
    {
        $dir = rtrim($this->config->stagingDir, '/') . '/operations';
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0750);
        }

        return new OperationLogger($this->runtime, $dir . '/' . $operationId . '.log');
    }

    private function logPath(string $operationId): string
    {
        return rtrim($this->config->stagingDir, '/') . '/operations/' . $operationId . '.log';
    }

    private function assertOk(\AzerioidPanel\Broker\ExecResult $result, string $label): void
    {
        if (!$result->ok()) {
            throw new BrokerException($label . ' failed: ' . $this->execDetail($result), 1);
        }
    }

    private function execDetail(\AzerioidPanel\Broker\ExecResult $result): string
    {
        return trim($result->stderr . "\n" . $result->stdout) ?: ('exit ' . $result->exitCode);
    }
}
