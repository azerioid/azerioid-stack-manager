<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;

/**
 * ADR A39 Part A: move the panel onto its own account and its own PHP-FPM master.
 *
 * End state, on every OS family:
 *
 *  - a dedicated `azerioid-panel` system account runs the panel pool, the queue
 *    worker and the scheduler, and is the ONLY holder of the broker sudo grant;
 *  - the pool runs under `azerioid-panel-php-fpm.service`, a php-fpm master of
 *    its own with its own php.ini (/etc/azerioid-panel/php.ini), so the distro
 *    php.ini no longer has to allow `proc_open` for the panel's sake and is
 *    restored to what the operator had before the panel touched it;
 *  - Caddy reaches the panel through the socket group and PanelFileAccess, not
 *    by being the panel.
 *
 * This is the single implementation of that end state. A fresh install runs it
 * at the end of install.sh, and an existing host runs it after self-update
 * (triggered by the panel scheduler via converge(), because the release that
 * first ships this code is deployed by the previous release's updater, which
 * cannot call it).
 *
 * Migration model (operator decision, 2026-09-26): additive sudoers for both
 * identities → files → pool/master → verify a real broker call and a real HTTP
 * request as the new identity → only then drop the old grant. Every mutation is
 * journalled; any failure reverts all of them and records the failure, so a
 * host is never left with neither identity authorised.
 */
final class PanelIdentityMigrator
{
    public const USER = 'azerioid-panel';

    public const UNIT = 'azerioid-panel-php-fpm';

    public const CONVERGE_UNIT = 'azerioid-panel-identity';

    public const ETC = '/etc/azerioid-panel';

    public const INI = self::ETC . '/php.ini';

    public const MASTER_CONF = self::ETC . '/php-fpm.conf';

    public const POOL = self::ETC . '/php-fpm.d/azerioid-panel.conf';

    public const UNIT_FILE = '/etc/systemd/system/' . self::UNIT . '.service';

    /** Outcome of the last attempt. Root-only: the panel user must not be able to clear a failure. */
    public const STATE_FILE = self::ETC . '/panel-identity.json';

    public const TMPFILES = '/etc/tmpfiles.d/azerioid-panel.conf';

    /** Present while install.sh runs (removed by its EXIT trap; /run clears on reboot). */
    public const INSTALLING_MARKER = '/run/azerioid-panel-installing';

    /** Ordered rollback journal: each entry undoes one mutation. */
    private array $journal = [];

    private array $log = [];

    /**
     * @param int $pollMicros pause between readiness polls (tests pass 0)
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
        private readonly int $pollMicros = 200000,
    ) {
    }

    // ---------------------------------------------------------------- status

    /**
     * Read-only. Safe at any time.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $pool = $this->panelPoolPath();
        $poolUser = $pool !== null ? $this->poolValue($pool, 'user') : null;
        $sudoers = PanelSudoers::users($this->runtime, $this->config);
        $unitBody = $this->runtime->fileExists(self::UNIT_FILE) ? $this->runtime->readFile(self::UNIT_FILE) : '';
        $ownIni = $unitBody !== '' && str_contains($unitBody, '-c ' . self::INI);
        $queueUser = $this->queueUser();

        $checks = [
            'account_exists' => $this->userExists(self::USER),
            'pool_user' => $poolUser === self::USER,
            'pool_on_own_master' => $pool === self::POOL,
            'own_php_ini' => $ownIni && $this->runtime->fileExists(self::INI),
            'sudoers_only_panel' => $sudoers === [self::USER],
            'queue_user' => $queueUser === self::USER,
            'broker_json_panel_user' => $this->config->panelUser === self::USER,
        ];
        $migrated = !in_array(false, $checks, true);
        $state = $this->readState();
        $running = $this->convergeRunning();
        // A `running` record with nothing running means the attempt died mid-way
        // (reboot, OOM kill) without its rollback. Only an operator retries that.
        $result = $state['result'] ?? null;
        $interrupted = $result === 'running' && !$running;
        $blocked = $result === 'failed' || $interrupted;

        return [
            'target_user' => self::USER,
            'migrated' => $migrated,
            'needs_migration' => !$migrated,
            'checks' => $checks,
            'panel_user' => $this->config->panelUser,
            'panel_pool_path' => $pool,
            'panel_pool_user' => $poolUser,
            'queue_user' => $queueUser,
            'sudoers_users' => $sudoers,
            'fpm_unit' => $this->config->panelFpmUnit,
            'last_attempt' => $state,
            'running' => $running,
            'interrupted' => $interrupted,
            'auto_eligible' => !$migrated && !$running && !$blocked,
            'verdict' => $migrated
                ? 'OK: the panel runs as ' . self::USER . ' on its own php-fpm master and is the only broker sudo holder.'
                : ($blocked
                    ? ($interrupted
                        ? 'INTERRUPTED: a migration attempt stopped before finishing or rolling back. Check the host, then run: azerioid panel identity apply --confirm'
                        : 'FAILED: the last migration attempt was rolled back (' . ($state['error'] ?? 'unknown error')
                            . '). Retry with: azerioid panel identity apply --confirm')
                    : 'PENDING: the panel still runs as ' . ($poolUser ?? $this->config->panelUser)
                        . '. It migrates automatically after self-update, or now with: azerioid panel identity apply --confirm'),
        ];
    }

    // ------------------------------------------------------------- triggers

    /**
     * Operator path (CLI, installer): typed confirm, runs inline, retries after a failure.
     *
     * @return array<string,mixed>
     */
    public function apply(string $confirm, bool $dryRun = false): array
    {
        Validator::typedConfirm($confirm, Validator::MIGRATE_PANEL_IDENTITY_CONFIRM);

        return $this->run($dryRun, 'operator');
    }

    /**
     * Automatic path. Without $now it only decides and hands off to a transient
     * systemd unit, because the migration restarts the panel's FPM master and
     * queue worker — the caller (scheduler/queue) must not be in either cgroup.
     *
     * @return array<string,mixed>
     */
    public function converge(bool $now): array
    {
        // install.sh runs the migration itself as its last step; a scheduled
        // run in the middle of it would race the installer for the same files.
        if ($this->runtime->fileExists(self::INSTALLING_MARKER)) {
            return ['started' => false, 'reason' => 'install.sh is running; it migrates the panel itself when it finishes'];
        }
        $status = $this->status();
        if ($status['migrated']) {
            return ['started' => false, 'reason' => 'already migrated'];
        }
        if (($status['last_attempt']['result'] ?? null) === 'failed') {
            return ['started' => false, 'reason' => 'the last attempt failed and was rolled back; automatic retries are off until an operator runs: azerioid panel identity apply --confirm'];
        }
        if ($status['interrupted'] && !$now) {
            return ['started' => false, 'reason' => 'the last attempt was interrupted; an operator must run: azerioid panel identity apply --confirm'];
        }
        if ($now) {
            return $this->run(false, 'automatic');
        }
        if ($status['running']) {
            return ['started' => false, 'reason' => 'a migration is already running'];
        }
        if ($this->updateInProgress()) {
            return ['started' => false, 'reason' => 'a panel self-update is in progress'];
        }

        $broker = rtrim($this->config->panelRoot, '/') . '/broker';
        $start = $this->runtime->exec([
            '/usr/bin/systemd-run',
            '--unit=' . self::CONVERGE_UNIT,
            '--collect',
            '--description=AZERIOID panel identity migration (ADR A39)',
            $broker,
            'panel.identity.converge',
            'now',
        ], null, 30);
        if (!$start->ok()) {
            throw new BrokerException('Could not start the identity migration unit: ' . trim($start->stderr . ' ' . $start->stdout), 1);
        }

        return ['started' => true, 'unit' => self::CONVERGE_UNIT . '.service'];
    }

    // -------------------------------------------------------------- migration

    /**
     * @return array<string,mixed>
     */
    private function run(bool $dryRun, string $trigger): array
    {
        if ($this->updateInProgress()) {
            throw new BrokerException('A panel self-update is in progress; refusing to migrate the panel identity underneath it.', 3);
        }
        if ($trigger === 'operator' && $this->convergeRunning()) {
            throw new BrokerException('An automatic identity migration is running right now (' . self::CONVERGE_UNIT . '.service); wait for it, then check: azerioid panel identity status', 3);
        }

        $before = $this->status();
        if ($before['migrated']) {
            return ['changed' => false, 'already_migrated' => true, 'status' => $before];
        }

        $plan = $this->resolvePlan();
        if ($dryRun) {
            return [
                'changed' => false,
                'dry_run' => true,
                'from_user' => $plan['from'],
                'to_user' => self::USER,
                'plan' => $plan,
                'steps' => $this->describe($plan),
                'status' => $before,
            ];
        }

        $this->journal = [];
        $this->log = [];
        $startedAt = $this->runtime->now();
        $this->writeState(['result' => 'running', 'trigger' => $trigger, 'started_at' => $startedAt]);

        try {
            $this->note("Migrating panel identity {$plan['from']} → " . self::USER . " ({$trigger})");

            $this->ensureAccount();
            // Additive first: every identity that holds the grant now keeps it
            // until the new one has been proven, so the panel never loses its broker path.
            $this->writeSudoers(array_values(array_unique(array_filter(array_merge(
                PanelSudoers::users($this->runtime, $this->config),
                [$plan['from'], self::USER],
            )))), 'additive');

            $this->writePanelIni($plan);
            $this->writeMasterConf();
            $this->writePool($plan);
            $this->migrateFiles($plan);
            $this->rewriteQueueUnit();
            $this->rewriteSchedulerCron();
            $this->rewriteTmpfiles();
            $this->writeUnit($plan);
            $this->cutOver($plan);
            $this->rewriteBrokerJson();
            $this->rewriteRuntimeJson();
            $this->restoreDistroIni($plan);
            $this->restartQueue();

            $this->verify($plan);

            // Only now does the previous identity lose its grant.
            $this->writeSudoers([self::USER], 'final');
        } catch (\Throwable $e) {
            $this->note('FAILED: ' . $e->getMessage());
            $reverted = $this->rollback($plan);
            $this->writeState([
                'result' => 'failed',
                'trigger' => $trigger,
                'started_at' => $startedAt,
                'finished_at' => $this->runtime->now(),
                'error' => $e->getMessage(),
                'reverted_steps' => $reverted,
                'log' => $this->log,
            ]);

            throw new BrokerException(
                'Panel identity migration failed and was rolled back (' . $reverted . ' step(s) reverted); '
                . 'the panel is still running as ' . $plan['from'] . '. Error: ' . $e->getMessage()
                . ' | Log: ' . implode(' ; ', $this->log),
                1
            );
        }

        $this->writeState([
            'result' => 'succeeded',
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => $this->runtime->now(),
            'from_user' => $plan['from'],
            'log' => $this->log,
        ]);

        return [
            'changed' => true,
            'from_user' => $plan['from'],
            'to_user' => self::USER,
            'log' => $this->log,
            'status' => (new self($this->runtime, Config::load($this->config->brokerConfigPath, $this->runtime)))->status(),
        ];
    }

    /**
     * Everything the migration needs to know, resolved before anything changes.
     *
     * @return array<string,mixed>
     */
    private function resolvePlan(): array
    {
        $pool = $this->panelPoolPath();
        if ($pool === null) {
            throw new BrokerException('Could not locate the panel PHP-FPM pool config; refusing to migrate.', 3);
        }
        $from = $this->poolValue($pool, 'user') ?? $this->config->panelUser;
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $from) !== 1) {
            throw new BrokerException('The panel pool runs as an unexpected user (' . $from . '); refusing to migrate.', 3);
        }
        $serverGroup = PanelFileAccess::serverGroup($this->runtime, $this->config);
        if ($serverGroup === null || $serverGroup === self::USER) {
            throw new BrokerException('Could not determine the user Caddy runs as; it needs the panel socket group.', 3);
        }

        $ownMaster = $this->runtime->fileExists(self::UNIT_FILE);
        $distroUnit = $this->distroFpmUnit();
        $fpmBin = $this->fpmBinary($ownMaster);

        return [
            'from' => $from,
            'pool' => $pool,
            // EL already had a dedicated master (SELinux, A3); apt shares the distro one.
            'own_master_before' => $ownMaster,
            'panel_unit_before' => $this->config->panelFpmUnit,
            'distro_unit' => $distroUnit,
            'fpm_bin' => $fpmBin,
            'distro_ini' => $this->distroIni(),
            'server_group' => $serverGroup,
        ];
    }

    /** @return list<string> */
    private function describe(array $plan): array
    {
        return [
            'create system account ' . self::USER . ' if missing',
            "write additive sudoers granting {$plan['from']} and " . self::USER,
            'write ' . self::INI . ' from ' . ($plan['distro_ini'] ?? '(no distro php.ini)') . ' with proc_open allowed',
            'write ' . self::MASTER_CONF . ' and ' . self::POOL . ' (user ' . self::USER . ', socket group ' . $plan['server_group'] . ')',
            'chown panel tree, state, env, database and logs to ' . self::USER . '; Caddy keeps read of web/public via its group',
            'queue unit, scheduler cron → ' . self::USER . '; /run/php back to root',
            ($plan['own_master_before'] ? 'point ' : 'install ') . self::UNIT . '.service at its own php.ini (' . $plan['fpm_bin'] . ')',
            $plan['own_master_before']
                ? 'restart ' . self::UNIT
                : "remove the panel pool from {$plan['distro_unit']}, reload it, start " . self::UNIT,
            'broker.json panel_user + fpm_unit, runtime.json fpm_unit',
            'restore the distro php.ini disable_functions line the installer had loosened, if it did and the ini is FPM-only',
            'verify: unit active, socket owner, broker call as ' . self::USER . ', HTTP /login through Caddy, queue active, CLI proc_open',
            'rewrite sudoers to grant ' . self::USER . ' only',
        ];
    }

    // ------------------------------------------------------------- discovery

    private function panelPoolPath(): ?string
    {
        $candidates = [
            self::POOL,
            '/etc/php/' . $this->config->panelPhpVersion . '/fpm/pool.d/azerioid-panel.conf',
            '/etc/opt/remi/php' . str_replace('.', '', $this->config->panelPhpVersion) . '/php-fpm.d/azerioid-panel.conf',
            '/etc/php-fpm.d/azerioid-panel.conf',
        ];
        foreach ($this->runtime->glob('/etc/php/*/fpm/pool.d/azerioid-panel.conf') as $g) {
            $candidates[] = $g;
        }
        foreach ($candidates as $path) {
            if ($this->runtime->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function poolValue(string $path, string $key): ?string
    {
        try {
            $body = $this->runtime->readFile($path);
        } catch (BrokerException) {
            return null;
        }
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(\S+)\s*$/m', $body, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function queueUser(): ?string
    {
        $path = '/etc/systemd/system/' . $this->config->panelRuntimeQueueUnit;
        if (!$this->runtime->fileExists($path)) {
            return null;
        }
        if (preg_match('/^User=(\S+)\s*$/m', $this->runtime->readFile($path), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function userExists(string $user): bool
    {
        return $this->runtime->exec(['/usr/bin/id', '-u', $user], null, 10)->ok();
    }

    private function distroFpmUnit(): string
    {
        if ($this->config->panelFpmUnit !== '' && $this->config->panelFpmUnit !== self::UNIT) {
            return $this->config->panelFpmUnit;
        }
        $ver = $this->config->panelPhpVersion;
        foreach (['php' . $ver . '-fpm', 'php' . str_replace('.', '', $ver) . '-php-fpm', 'php-fpm'] as $unit) {
            if ($this->runtime->exec(['/usr/bin/systemctl', 'cat', $unit . '.service'], null, 10)->ok()) {
                return $unit;
            }
        }

        return 'php' . $ver . '-fpm';
    }

    private function fpmBinary(bool $ownMaster): string
    {
        // EL: keep the existing bin_t copy — the distro binary is httpd_exec_t
        // and cannot be started as unconfined_service_t (see A3 / fpm.sh).
        if ($ownMaster && preg_match('/^ExecStart=(\S+)/m', $this->runtime->readFile(self::UNIT_FILE), $m) === 1
            && $this->runtime->fileExists($m[1])) {
            return $m[1];
        }
        $ver = $this->config->panelPhpVersion;
        foreach (['/usr/sbin/php-fpm' . $ver, '/usr/sbin/php-fpm'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }
        throw new BrokerException('No php-fpm binary found for PHP ' . $ver . '.', 3);
    }

    /**
     * The php.ini the installer's fpm.sh edited: same candidates in the same
     * order as fpm_ini() in deploy/lib/os-paths.sh. Diverging from it means
     * seeding the panel ini from, and looking for the backup next to, a file
     * the installer never touched.
     */
    private function distroIni(): ?string
    {
        $ver = $this->config->panelPhpVersion;
        foreach ([
            '/etc/php/' . $ver . '/fpm/php.ini',
            '/etc/php.ini',
            '/etc/opt/remi/php' . str_replace('.', '', $ver) . '/php.ini',
        ] as $path) {
            if ($this->runtime->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function convergeRunning(): bool
    {
        $r = $this->runtime->exec(['/usr/bin/systemctl', 'is-active', self::CONVERGE_UNIT . '.service'], null, 10);

        return trim($r->stdout) === 'active' || trim($r->stdout) === 'activating';
    }

    /**
     * The updater runs inside the queue worker, which this migration restarts.
     * Also the only guard during the first hop, when the updater is older code.
     */
    private function updateInProgress(): bool
    {
        $r = $this->runtime->exec(['/usr/bin/pgrep', '-f', 'panel\.update\.apply'], null, 10);

        return $r->ok() && trim($r->stdout) !== '';
    }

    // -------------------------------------------------------------- mutations

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    private function backupFile(string $path): void
    {
        if (!$this->runtime->fileExists($path)) {
            $this->journal[] = ['type' => 'unlink', 'path' => $path];

            return;
        }
        $this->journal[] = [
            'type' => 'restore',
            'path' => $path,
            'body' => $this->runtime->readFile($path),
            'mode' => $this->modeOf($path) ?? 0644,
            'owner' => $this->ownerOf($path),
        ];
    }

    private function ownerOf(string $path): string
    {
        $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%U:%G', $path], null, 10);

        return $r->ok() && trim($r->stdout) !== '' ? trim($r->stdout) : 'root:root';
    }

    private function modeOf(string $path): ?int
    {
        $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%a', $path], null, 10);
        $raw = trim($r->stdout);

        return $r->ok() && preg_match('/^[0-7]{3,4}$/', $raw) === 1 ? intval($raw, 8) : null;
    }

    private function mustExec(array $command, string $what, int $timeout = 30): void
    {
        $r = $this->runtime->exec($command, null, $timeout);
        if (!$r->ok()) {
            throw new BrokerException($what . ' failed: ' . trim($r->stderr . ' ' . $r->stdout), 1);
        }
    }

    private function ensureAccount(): void
    {
        if ($this->userExists(self::USER)) {
            $this->note('account ' . self::USER . ' already exists');

            return;
        }
        $this->mustExec([
            '/usr/sbin/useradd',
            '--system',
            '--user-group',
            '--home-dir', PanelFileAccess::STATE_DIR,
            '--no-create-home',
            '--shell', '/usr/sbin/nologin',
            '--comment', 'AZERIOID panel',
            self::USER,
        ], 'useradd ' . self::USER);
        $this->journal[] = ['type' => 'userdel', 'user' => self::USER];
        $this->note('created system account ' . self::USER);
    }

    /** @param list<string> $users */
    private function writeSudoers(array $users, string $stage): void
    {
        $this->backupFile(PanelSudoers::PATH);
        PanelSudoers::install($this->runtime, $this->config, $users, 'panel identity, A39');
        $this->note("sudoers ({$stage}): " . implode(', ', $users));
    }

    /**
     * The panel's own php.ini: the distro one as it stands, minus the two
     * functions the panel needs to spawn sudo. Copying keeps every other
     * setting (limits, opcache, timezone) exactly as the panel has had them.
     */
    private function writePanelIni(array $plan): void
    {
        $this->ensureDir(self::ETC, 0750);
        $source = $plan['distro_ini'];
        $body = $source !== null ? $this->runtime->readFile($source) : "display_errors = Off\nlog_errors = On\nexpose_php = Off\n";
        $body = self::allowProcOpen($body);
        $header = "; AZERIOID panel php.ini (ADR A39 Part A). Used only by " . self::UNIT . ".service.\n"
            . '; Seeded from ' . ($source ?? '(built-in defaults)') . "; the distro php.ini no longer serves the panel.\n";

        $this->backupFile(self::INI);
        $this->runtime->writeFile(self::INI, $header . $body, 0644);
        $this->note('wrote ' . self::INI);
    }

    public static function allowProcOpen(string $ini): string
    {
        $out = preg_replace_callback('/^disable_functions[ \t]*=[ \t]*(.*)$/m', static function (array $m): string {
            $keep = [];
            foreach (explode(',', $m[1]) as $f) {
                $f = trim($f);
                if ($f !== '' && !in_array($f, ['proc_open', 'proc_get_status'], true) && !in_array($f, $keep, true)) {
                    $keep[] = $f;
                }
            }

            return 'disable_functions = ' . implode(',', $keep);
        }, $ini, 1);

        return is_string($out) ? $out : $ini;
    }

    private function writeMasterConf(): void
    {
        $this->ensureDir(self::ETC . '/php-fpm.d', 0750);
        $this->backupFile(self::MASTER_CONF);
        $this->runtime->writeFile(self::MASTER_CONF, "; AZERIOID panel PHP-FPM master (ADR A39 Part A)\n"
            . "[global]\n"
            . "pid = /run/azerioid-panel-php-fpm.pid\n"
            . "error_log = /var/log/azerioid-panel/php-fpm-master.log\n"
            . "daemonize = no\n"
            . 'include=' . self::ETC . "/php-fpm.d/*.conf\n", 0640);
        $this->note('wrote ' . self::MASTER_CONF);
    }

    private function writePool(array $plan): void
    {
        $body = $this->runtime->readFile($plan['pool']);
        $patched = preg_replace(
            [
                '/^(\s*user\s*=\s*)\S+\s*$/m',
                '/^(\s*group\s*=\s*)\S+\s*$/m',
                '/^(\s*listen\.owner\s*=\s*)\S+\s*$/m',
            ],
            '${1}' . self::USER,
            $body
        );
        $patched = is_string($patched)
            ? preg_replace('/^(\s*listen\.group\s*=\s*)\S+\s*$/m', '${1}' . $plan['server_group'], $patched)
            : null;
        if (!is_string($patched) || preg_match('/^\s*user\s*=\s*' . preg_quote(self::USER, '/') . '\s*$/m', $patched) !== 1) {
            throw new BrokerException('Panel pool rewrite did not take effect.', 1);
        }
        if (preg_match('/^\s*listen\.mode\s*=/m', $patched) !== 1) {
            $patched = rtrim($patched, "\n") . "\nlisten.mode = 0660\n";
        }

        $this->backupFile(self::POOL);
        $this->runtime->writeFile(self::POOL, $patched, 0644);
        $this->note('wrote ' . self::POOL . ' (user ' . self::USER . ', listen.group ' . $plan['server_group'] . ')');
    }

    private function migrateFiles(array $plan): void
    {
        $user = self::USER;
        $prefix = rtrim($this->config->panelRoot, '/');
        $web = $prefix . '/web';
        $state = PanelFileAccess::STATE_DIR;

        if ($this->runtime->isDir($web)) {
            $this->journal[] = ['type' => 'chown-r', 'path' => $web, 'owner' => $this->ownerOf($web)];
            $this->mustExec(['/bin/chown', '-R', "{$user}:{$user}", $web], 'chown -R ' . $web, 180);
            $this->note("chown -R {$user} {$web}");
        }

        foreach ([
            $state,
            $state . '/panel.sqlite',
            $state . '/panel.sqlite-wal',
            $state . '/panel.sqlite-shm',
            '/var/log/azerioid-panel/php-fpm.log',
            '/var/log/azerioid-panel/auth-fail.log',
        ] as $path) {
            if (!$this->runtime->fileExists($path) && !$this->runtime->isDir($path)) {
                continue;
            }
            $this->journal[] = ['type' => 'chown', 'path' => $path, 'owner' => $this->ownerOf($path)];
            $this->mustExec(['/bin/chown', "{$user}:{$user}", $path], 'chown ' . $path);
        }

        if ($this->runtime->isDir('/var/log/azerioid-panel')) {
            $this->journal[] = ['type' => 'chown', 'path' => '/var/log/azerioid-panel', 'owner' => $this->ownerOf('/var/log/azerioid-panel')];
            $this->mustExec(['/bin/chown', 'root:' . $user, '/var/log/azerioid-panel'], 'chown /var/log/azerioid-panel');
        }

        // What Caddy may still see (group on web/ and the state dir, read on public/).
        foreach ([$web, $state] as $dir) {
            if ($this->runtime->isDir($dir)) {
                $this->journal[] = ['type' => 'chmod', 'path' => $dir, 'mode' => $this->modeOf($dir) ?? 0750];
            }
        }
        if ($this->runtime->isDir($web . '/public')) {
            $this->journal[] = ['type' => 'exec', 'command' => ['/bin/chmod', '-R', 'o-rwx', $web . '/public']];
        }
        $panelConfig = clone $this->config;
        $panelConfig->panelUser = $user;
        foreach (PanelFileAccess::apply($this->runtime, $panelConfig) as $line) {
            $this->note($line);
        }
    }

    private function rewriteQueueUnit(): void
    {
        $path = '/etc/systemd/system/' . $this->config->panelRuntimeQueueUnit;
        if (!$this->runtime->fileExists($path)) {
            return;
        }
        $this->backupFile($path);
        $patched = preg_replace(
            ['/^(User=)\S+\s*$/m', '/^(Group=)\S+\s*$/m'],
            '${1}' . self::USER,
            $this->runtime->readFile($path)
        );
        if (!is_string($patched)) {
            throw new BrokerException('Failed to rewrite the queue unit.', 1);
        }
        $this->runtime->writeFile($path, $patched, 0644);
        $this->mustExec(['/usr/bin/systemctl', 'daemon-reload'], 'daemon-reload');
        $this->note('queue unit User/Group → ' . self::USER);
    }

    private function rewriteSchedulerCron(): void
    {
        $path = $this->config->cronDPath;
        if (!$this->runtime->fileExists($path)) {
            return;
        }
        $this->backupFile($path);
        $patched = PanelSudoers::cronUser($this->runtime->readFile($path), self::USER);
        if ($patched === null) {
            throw new BrokerException('Failed to rewrite the scheduler cron line.', 1);
        }
        $this->runtime->writeFile($path, $patched, 0644);
        $this->note('scheduler cron user → ' . self::USER);
    }

    /**
     * /run/php holds the panel socket. Owned by the web user, that user could
     * unlink the socket and bind its own in its place; it goes back to root.
     */
    private function rewriteTmpfiles(): void
    {
        $this->backupFile(self::TMPFILES);
        $this->runtime->writeFile(self::TMPFILES, "d /run/php 0755 root root -\n", 0644);
        if ($this->runtime->isDir('/run/php')) {
            $this->journal[] = ['type' => 'chown', 'path' => '/run/php', 'owner' => $this->ownerOf('/run/php')];
            $this->mustExec(['/bin/chown', 'root:root', '/run/php'], 'chown /run/php');
        }
        $this->note('/run/php → root:root');
    }

    /**
     * No PrivateTmp: the broker is spawned from this unit's pool via sudo and
     * inherits its namespace. On apt it used to escape the distro unit through
     * systemd-run and see the real /tmp (MariaDB may keep its socket there);
     * it must keep doing so now that it runs here. The pool's own temp dirs are
     * under storage/ (sys_temp_dir, upload_tmp_dir), not /tmp.
     */
    private function writeUnit(array $plan): void
    {
        $this->backupFile(self::UNIT_FILE);
        $this->runtime->writeFile(self::UNIT_FILE, "# AZERIOID panel PHP-FPM master (ADR A39 Part A) — written by the broker.\n"
            . "[Unit]\n"
            . "Description=AZERIOID panel PHP-FPM\n"
            . "After=network.target\n"
            . "\n"
            . "[Service]\n"
            . "Type=notify\n"
            . 'ExecStart=' . $plan['fpm_bin'] . ' --nodaemonize -c ' . self::INI . ' --fpm-config ' . self::MASTER_CONF . "\n"
            . "ExecReload=/bin/kill -USR2 \$MAINPID\n"
            . "\n"
            . "[Install]\n"
            . "WantedBy=multi-user.target\n", 0644);

        $this->mustExec([$plan['fpm_bin'], '-t', '-c', self::INI, '--fpm-config', self::MASTER_CONF], 'php-fpm -t (panel master)');
        $this->mustExec(['/usr/bin/systemctl', 'daemon-reload'], 'daemon-reload');
        $this->note('wrote ' . self::UNIT_FILE);
    }

    private function cutOver(array $plan): void
    {
        if (!$plan['own_master_before']) {
            // apt: the panel pool leaves the distro master. Its socket must be
            // released before our master binds the same path.
            $this->backupFile($plan['pool']);
            $this->runtime->deleteFile($plan['pool']);
            $this->note('removed ' . $plan['pool'] . ' from ' . $plan['distro_unit']);

            $dropin = '/etc/systemd/system/' . $plan['distro_unit'] . '.service.d/azerioid-panel.conf';
            if ($this->runtime->fileExists($dropin)) {
                $this->backupFile($dropin);
                $this->runtime->deleteFile($dropin);
                $this->mustExec(['/usr/bin/systemctl', 'daemon-reload'], 'daemon-reload');
                $this->note('removed ' . $dropin . ' (panel paths no longer served by the distro master)');
            }

            $this->mustExec([$this->fpmBinary(false), '-t'], 'php-fpm -t (distro master)');
            Systemd::control($this->runtime, 'reload', $plan['distro_unit']);
            $this->note('reloaded ' . $plan['distro_unit']);

            $this->journal[] = ['type' => 'unit-off', 'unit' => self::UNIT];
            $this->mustExec(['/usr/bin/systemctl', 'enable', self::UNIT . '.service'], 'enable ' . self::UNIT);
        }
        Systemd::control($this->runtime, 'restart', self::UNIT);
        $this->note('started ' . self::UNIT);
    }

    private function rewriteBrokerJson(): void
    {
        $path = $this->config->brokerConfigPath;
        if (!$this->runtime->fileExists($path)) {
            throw new BrokerException($path . ' is missing; refusing to migrate without it.', 3);
        }
        $this->backupFile($path);
        $data = json_decode($this->runtime->readFile($path), true);
        if (!is_array($data)) {
            throw new BrokerException('broker.json is not valid JSON; refusing to rewrite.', 1);
        }
        $data['panel_user'] = self::USER;
        $data['panel_runtime'] = (is_array($data['panel_runtime'] ?? null) ? $data['panel_runtime'] : []);
        $data['panel_runtime']['fpm_unit'] = self::UNIT;
        $this->runtime->writeFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        $this->note('broker.json panel_user → ' . self::USER . ', fpm_unit → ' . self::UNIT);
    }

    private function rewriteRuntimeJson(): void
    {
        $path = self::ETC . '/runtime.json';
        if (!$this->runtime->fileExists($path)) {
            return;
        }
        $data = json_decode($this->runtime->readFile($path), true);
        if (!is_array($data)) {
            return;
        }
        $this->backupFile($path);
        $data['fpm_unit'] = self::UNIT;
        $this->runtime->writeFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0640);
    }

    /**
     * fpm.sh removed proc_open/proc_get_status from the distro php.ini so the
     * shared master would let the panel spawn sudo, keeping a pristine copy at
     * <ini>.azerioid-panel.bak. The panel no longer needs that, so the site
     * pools get back the disable_functions line the operator had. Hosts whose
     * php.ini never disabled them have no backup and nothing changes.
     *
     * Only for an FPM-only ini (Debian/Ubuntu /etc/php/X/fpm/php.ini). On EL
     * and Remi the same file also serves the PHP CLI, which runs the queue
     * worker and the scheduler — both reach the broker through proc_open, so
     * restoring the line there would cut the panel off from its own broker.
     */
    private function restoreDistroIni(array $plan): void
    {
        $ini = $plan['distro_ini'];
        if ($ini === null || !$this->runtime->fileExists($ini . '.azerioid-panel.bak')) {
            return;
        }
        if (!self::isFpmOnlyIni($ini)) {
            $this->note("left {$ini} as it is: it is shared with the PHP CLI (queue worker, scheduler), "
                . 'which needs proc_open to reach the broker');

            return;
        }
        $pattern = '/^disable_functions\s*=.*$/m';
        if (preg_match($pattern, $this->runtime->readFile($ini . '.azerioid-panel.bak'), $orig) !== 1) {
            return;
        }
        $current = $this->runtime->readFile($ini);
        if (preg_match($pattern, $current, $now) === 1 && $now[0] === $orig[0]) {
            return;
        }
        $patched = preg_match($pattern, $current) === 1
            ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $orig[0]), $current, 1)
            : rtrim($current, "\n") . "\n" . $orig[0] . "\n";
        if (!is_string($patched)) {
            throw new BrokerException('Failed to restore disable_functions in ' . $ini, 1);
        }
        $this->backupFile($ini);
        $this->runtime->writeFile($ini, $patched, 0644);
        Systemd::control($this->runtime, 'reload', $plan['distro_unit']);
        $this->note("restored {$ini} disable_functions from the pre-install backup; reloaded {$plan['distro_unit']}");
    }

    public static function isFpmOnlyIni(string $path): bool
    {
        return preg_match('#^/etc/php/[0-9.]+/fpm/php\.ini$#', $path) === 1;
    }

    private function restartQueue(): void
    {
        Systemd::control($this->runtime, 'restart', $this->config->panelRuntimeQueueUnit);
        $this->note('queue worker restarted');
    }

    private function ensureDir(string $path, int $mode): void
    {
        if (!$this->runtime->isDir($path)) {
            $this->runtime->mkdir($path, $mode);
        }
    }

    // ----------------------------------------------------------------- verify

    private function verify(array $plan): void
    {
        $user = self::USER;

        $active = $this->runtime->exec(['/usr/bin/systemctl', 'is-active', self::UNIT . '.service'], null, 15);
        if (trim($active->stdout) !== 'active') {
            throw new BrokerException(self::UNIT . ' is not active after cut-over (' . trim($active->stdout) . ').', 1);
        }
        $this->note(self::UNIT . ' active');

        $expected = $user . ':' . $plan['server_group'];
        $owner = $this->socketOwner();
        if ($owner !== $expected) {
            throw new BrokerException('Panel socket ' . $this->config->panelFpmSocket . ' is ' . var_export($owner, true) . ', expected ' . $expected . '.', 1);
        }
        $this->note("socket owned by {$expected}");

        // The decisive check: can the new identity actually reach the broker?
        $broker = rtrim($this->config->panelRoot, '/') . '/broker';
        $call = $this->runtime->exec([$this->runuser(), '-u', $user, '--', '/usr/bin/sudo', '-n', $broker, 'version.all'], null, 60);
        $decoded = json_decode(trim($call->stdout), true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new BrokerException('Broker call as ' . $user . ' did not succeed: ' . substr(trim($call->stderr . ' ' . $call->stdout), 0, 400), 1);
        }
        $this->note("broker call as {$user} succeeded");

        // And can Caddy → socket → PHP → Laravel serve a page as that identity?
        $url = 'http://127.0.0.1:' . $this->config->panelPort . '/login';
        $code = '';
        for ($i = 0; $i < 20; $i++) {
            $r = $this->runtime->exec(['/usr/bin/curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '10', $url], null, 15);
            $code = trim($r->stdout);
            if (in_array($code, ['200', '302'], true)) {
                break;
            }
            usleep($this->pollMicros * 2);
        }
        if (!in_array($code, ['200', '302'], true)) {
            throw new BrokerException("GET {$url} returned " . ($code !== '' ? $code : 'no response') . ' after the cut-over.', 1);
        }
        $this->note("GET {$url} → {$code}");

        $queue = $this->runtime->exec(['/usr/bin/systemctl', 'is-active', $this->config->panelRuntimeQueueUnit], null, 15);
        if (trim($queue->stdout) !== 'active') {
            throw new BrokerException('Queue worker is not active as ' . $user . ' (' . trim($queue->stdout) . ').', 1);
        }
        $this->note('queue worker active');

        // The queue worker and scheduler are CLI processes; an active unit says
        // nothing about whether they can still spawn the broker.
        $cli = $this->runtime->exec([
            $this->runuser(), '-u', $user, '--', $this->phpCli(), '-r',
            'exit(function_exists("proc_open") && function_exists("proc_get_status") ? 0 : 3);',
        ], null, 30);
        if (!$cli->ok()) {
            throw new BrokerException('The PHP CLI cannot use proc_open as ' . $user
                . '; the queue worker and scheduler would lose the broker.', 1);
        }
        $this->note('PHP CLI can spawn processes as ' . $user);
    }

    private function socketOwner(): ?string
    {
        $socket = $this->config->panelFpmSocket;
        for ($i = 0; $i < 25; $i++) {
            if ($this->runtime->fileExists($socket)) {
                $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%U:%G', $socket], null, 10);
                if ($r->ok()) {
                    return trim($r->stdout);
                }
            }
            usleep($this->pollMicros);
        }

        return null;
    }

    private function phpCli(): string
    {
        $versioned = '/usr/bin/php' . $this->config->panelPhpVersion;

        return $this->runtime->fileExists($versioned) ? $versioned : '/usr/bin/php';
    }

    private function runuser(): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        return '/usr/sbin/runuser';
    }

    // --------------------------------------------------------------- rollback

    private function rollback(array $plan): int
    {
        $n = 0;
        $userdel = null;
        foreach (array_reverse($this->journal) as $entry) {
            try {
                switch ($entry['type']) {
                    case 'restore':
                        $mode = $entry['path'] === PanelSudoers::PATH ? 0440 : $entry['mode'];
                        $this->runtime->writeFile($entry['path'], $entry['body'], $mode);
                        $this->runtime->chmod($entry['path'], $mode);
                        $this->runtime->exec(['/bin/chown', $entry['owner'], $entry['path']], null, 30);
                        break;
                    case 'unlink':
                        if ($this->runtime->fileExists($entry['path'])) {
                            $this->runtime->deleteFile($entry['path']);
                        }
                        break;
                    case 'chown':
                        $this->runtime->exec(['/bin/chown', $entry['owner'], $entry['path']], null, 30);
                        break;
                    case 'chown-r':
                        $this->runtime->exec(['/bin/chown', '-R', $entry['owner'], $entry['path']], null, 180);
                        break;
                    case 'chmod':
                        $this->runtime->exec(['/bin/chmod', sprintf('%04o', $entry['mode']), $entry['path']], null, 30);
                        break;
                    case 'exec':
                        $this->runtime->exec($entry['command'], null, 60);
                        break;
                    case 'unit-off':
                        $this->runtime->exec(['/usr/bin/systemctl', 'disable', '--now', $entry['unit'] . '.service'], null, 60);
                        break;
                    case 'userdel':
                        // After the restarts below: userdel refuses while the
                        // queue worker still runs as this account.
                        $userdel = $entry['user'];
                        continue 2;
                    default:
                        continue 2;
                }
                $n++;
            } catch (\Throwable) {
                // Keep reverting the rest; the count says how far we got.
            }
        }

        $this->runtime->exec(['/usr/bin/systemctl', 'daemon-reload'], null, 30);
        // Bring back whichever master served the panel before, with its old pool.
        foreach (array_unique([$plan['distro_unit'], $plan['panel_unit_before']]) as $unit) {
            try {
                Systemd::control($this->runtime, 'restart', $unit);
            } catch (\Throwable $e) {
                $this->note('rollback: restart ' . $unit . ' failed: ' . $e->getMessage());
            }
        }
        try {
            Systemd::control($this->runtime, 'restart', $this->config->panelRuntimeQueueUnit);
        } catch (\Throwable $e) {
            $this->note('rollback: queue restart failed: ' . $e->getMessage());
        }
        if ($userdel !== null) {
            $del = $this->runtime->exec(['/usr/sbin/userdel', $userdel], null, 30);
            if ($del->ok()) {
                $n++;
            } else {
                $this->note('rollback: userdel ' . $userdel . ' failed (the account remains, without any sudo grant): ' . trim($del->stderr));
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ state

    /** @return array<string,mixed>|null */
    private function readState(): ?array
    {
        if (!$this->runtime->fileExists(self::STATE_FILE)) {
            return null;
        }
        $data = json_decode($this->runtime->readFile(self::STATE_FILE), true);

        return is_array($data) ? $data : null;
    }

    private function writeState(array $state): void
    {
        $this->ensureDir(self::ETC, 0750);
        $this->runtime->writeFile(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }
}
