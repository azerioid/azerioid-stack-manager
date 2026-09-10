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
 * Panel self-update against origin/main via a managed git source tree + PREFIX rsync deploy.
 *
 * PREFIX (/usr/local/lib/azerioid-panel) is not itself a git checkout — install uses rsync.
 * Updates clone/fetch into paths.panel_source, then redeploy like install_broker/install_panel_app.
 */
final class PanelUpdater
{
    public const CONFIRM = 'PANEL-UPDATE';
    public const CHANNEL = 'main';
    public const DEFAULT_REMOTE = 'https://github.com/azerioid/azerioid-stack-manager.git';
    public const LOG_SUMMARY_LIMIT = 30;

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

        $fetch = $this->git($source, ['fetch', '--prune', 'origin'], 120);
        if (!$fetch->ok()) {
            throw new BrokerException(
                'git fetch origin failed: ' . $this->execDetail($fetch),
                1
            );
        }

        $remote = $this->revParse($source, 'origin/' . self::CHANNEL);
        $deployed = $this->readDeployedCommit();
        $dirty = $this->dirtyEntries($source);
        $behind = $deployed !== null && $deployed !== $remote;
        $logLines = [];
        if ($deployed !== null && $behind) {
            $logLines = $this->logOneline($source, $deployed, $remote);
        } elseif ($deployed === null) {
            $logLines = $this->logOneline($source, $remote . '~10', $remote);
        }

        $version = $this->readVersionFile();

        return [
            'channel' => self::CHANNEL,
            'remote' => $this->gitRemote(),
            'source_path' => $source,
            'deployed_commit' => $deployed,
            'deployed_commit_short' => $deployed !== null ? substr($deployed, 0, 7) : null,
            'remote_commit' => $remote,
            'remote_commit_short' => substr($remote, 0, 7),
            'update_available' => $deployed === null ? true : $behind,
            'up_to_date' => $deployed !== null && !$behind,
            'dirty' => $dirty !== [],
            'dirty_entries' => $dirty,
            'log_summary' => $logLines,
            'version' => $version,
            'limitation' => 'Tracks origin/' . self::CHANNEL . ' only — no stable/tag channel yet.',
            'scope' => 'panel-self-update',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(string $operationId, string $confirm): array
    {
        Validator::typedConfirm($confirm, self::CONFIRM);
        $operationId = Validator::operationId($operationId);
        $log = $this->logger($operationId);
        $source = $this->sourcePath();
        $prefix = rtrim($this->config->panelRoot, '/');
        $preCommit = $this->readDeployedCommit();
        $rolledBack = false;
        $target = null;

        try {
            $log->info('Panel self-update starting (channel=origin/' . self::CHANNEL . ').');
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

            $fetch = $this->git($source, ['fetch', '--prune', 'origin'], 120);
            if (!$fetch->ok()) {
                throw new BrokerException('git fetch failed: ' . $this->execDetail($fetch), 1);
            }

            $target = $this->revParse($source, 'origin/' . self::CHANNEL);
            if ($preCommit === null) {
                $log->warn('No COMMIT marker under PREFIX; treating pre-update rollback point as current source HEAD after sync.');
                // Align source to whatever we are about to leave behind if deploy fails mid-way:
                // use current origin tip's parent only after we have deployed something once.
                // For first apply: snapshot source at target before merge is N/A — checkout target,
                // and on failure re-deploy is best-effort from whatever COMMIT we write only after success.
                $preCommit = $this->revParse($source, 'HEAD');
            }

            if ($preCommit === $target) {
                $log->info('Already on ' . substr($target, 0, 7) . ' — nothing to apply.');
                $this->writeDeployedCommit($target);

                return [
                    'channel' => self::CHANNEL,
                    'from_commit' => $preCommit,
                    'to_commit' => $target,
                    'changed' => false,
                    'rolled_back' => false,
                    'migrations' => 'none',
                    'operation_id' => $operationId,
                    'log_path' => $log->path(),
                ];
            }

            $log->info('Rollback point: ' . $preCommit);
            $log->info('Target origin/' . self::CHANNEL . ': ' . $target);

            // Ensure source is on pre-commit before fast-forward (so reset --hard pre is meaningful).
            $checkoutPre = $this->git($source, ['checkout', '-f', 'main'], 60);
            if (!$checkoutPre->ok()) {
                $checkoutPre = $this->git($source, ['checkout', '-f', '-B', 'main', $preCommit], 60);
            }
            $resetPre = $this->git($source, ['reset', '--hard', $preCommit], 60);
            if (!$resetPre->ok()) {
                throw new BrokerException(
                    'Could not align source tree to rollback point ' . $preCommit . ': ' . $this->execDetail($resetPre),
                    1
                );
            }

            $merge = $this->git($source, ['merge', '--ff-only', $target], 60);
            if (!$merge->ok()) {
                throw new BrokerException(
                    'Fast-forward to origin/' . self::CHANNEL . ' is not possible (refusing non-FF update). '
                    . $this->execDetail($merge),
                    3
                );
            }
            $log->info('Fast-forward OK.');

            $this->deployFromSource($source, $prefix, $log);
            $migrations = $this->runMigrations($prefix, $log);
            $this->rebuildCaches($prefix, $log);
            $this->writeDeployedCommit($target);
            $this->writeVersionFromSource($source, $prefix);
            $this->reloadRuntime($log, deferQueueRestart: true);

            $log->info('Panel self-update completed successfully → ' . substr($target, 0, 7));

            return [
                'channel' => self::CHANNEL,
                'from_commit' => $preCommit,
                'to_commit' => $target,
                'changed' => true,
                'rolled_back' => false,
                'migrations' => $migrations,
                'operation_id' => $operationId,
                'log_path' => $log->path(),
            ];
        } catch (\Throwable $e) {
            $log->warn('Update failed: ' . $e->getMessage());
            if ($preCommit !== null) {
                try {
                    $log->info('Rolling back to ' . $preCommit . ' …');
                    $this->git($source, ['reset', '--hard', $preCommit], 60);
                    $this->deployFromSource($source, $prefix, $log);
                    $this->runMigrations($prefix, $log);
                    $this->rebuildCaches($prefix, $log);
                    $this->writeDeployedCommit($preCommit);
                    $this->writeVersionFromSource($source, $prefix);
                    $this->reloadRuntime($log, deferQueueRestart: true);
                    $rolledBack = true;
                    $log->info('Rollback completed; panel left on ' . substr($preCommit, 0, 7) . '.');
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
                    ? 'Panel update failed and was rolled back to the previous commit. '
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
        $r = $this->git($source, ['rev-parse', $ref], 30);
        if (!$r->ok()) {
            throw new BrokerException('git rev-parse ' . $ref . ' failed: ' . $this->execDetail($r), 1);
        }
        $hash = trim($r->stdout);
        if (!preg_match('/^[0-9a-f]{40}$/', $hash)) {
            throw new BrokerException('Unexpected git rev-parse output for ' . $ref . '.', 1);
        }

        return $hash;
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

    private function writeDeployedCommit(string $hash): void
    {
        $path = rtrim($this->config->panelRoot, '/') . '/COMMIT';
        $this->runtime->writeFile($path, $hash . "\n", 0644);
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
