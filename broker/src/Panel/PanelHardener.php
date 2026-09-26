<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;

/**
 * R1 in-place remediation (2026-09-26).
 *
 * On Debian/Ubuntu, install.sh resolved WEB_USER before the Caddy package
 * existed, latching the pre-existing `www-data` — which is also the distro
 * `www` site-pool user. The broker sudo grant therefore landed on the identity
 * that runs every hosted site's PHP, making any code execution in any hosted
 * PHP site a direct path to root.
 *
 * This migrates an already-installed host onto a panel identity that is NOT a
 * site-pool user. It is idempotent and transactional: every mutation is
 * journalled and reverted as a group if verification fails, so the host is
 * either fully migrated or fully reverted — never half-migrated with a dead
 * panel.
 *
 * Scope note: this is the Part-B mitigation. The structural fix (a dedicated
 * panel php-fpm master on every family, so site pools can keep `proc_open`
 * disabled) is ADR A39 and is NOT done here. See residualRisk().
 */
final class PanelHardener
{
    /** Ordered rollback journal: each entry restores one mutation. */
    private array $journal = [];

    private array $log = [];

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /**
     * Read-only assessment. Safe to call at any time.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $panelPool = $this->panelPoolPath();
        $poolUser = $panelPool !== null ? $this->poolUser($panelPool) : null;
        $sitePools = $this->sitePools();
        $siteUsers = array_values(array_unique(array_filter(array_values($sitePools))));
        $sudoUsers = $this->sudoersUsers();

        $collision = array_values(array_intersect($sudoUsers, $siteUsers));

        return [
            'panel_pool_path' => $panelPool,
            'panel_pool_user' => $poolUser,
            'broker_json_web_user' => $this->config->webUser,
            'sudoers_path' => $this->sudoersPath(),
            'sudoers_users' => $sudoUsers,
            'site_pools' => $sitePools,
            'site_pool_users' => $siteUsers,
            'target_user' => $this->resolveTarget($siteUsers, false),
            'vulnerable' => $collision !== [],
            'colliding_users' => $collision,
            'site_pools_hardened' => $this->sitePoolsHardened(),
            'verdict' => $collision !== []
                ? 'VULNERABLE: broker sudo is granted to a site PHP-FPM pool user ('
                    . implode(', ', $collision) . '). Any hosted site code execution reaches root.'
                : 'OK: no sudoers grant is shared with a site PHP-FPM pool user.',
            'residual_risk' => $this->residualRisk(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(string $confirm, bool $lockdownSitePools = false, bool $dryRun = false): array
    {
        Validator::typedConfirm($confirm, Validator::HARDEN_PANEL_CONFIRM);

        $before = $this->status();
        $panelPool = $before['panel_pool_path'];
        if (!is_string($panelPool) || $panelPool === '') {
            throw new BrokerException('Could not locate the panel PHP-FPM pool config; refusing to harden.', 3);
        }

        $siteUsers = $before['site_pool_users'];
        $target = $this->resolveTarget($siteUsers, true);
        $current = (string) ($before['panel_pool_user'] ?? '');

        if ($current === $target && $before['vulnerable'] === false) {
            return [
                'changed' => false,
                'already_hardened' => true,
                'panel_user' => $target,
                'before' => $before,
                'after' => $before,
                'log' => ['Panel already runs as ' . $target . ' and no sudoers grant collides with a site pool.'],
                'residual_risk' => $this->residualRisk(),
            ];
        }

        if ($dryRun) {
            return [
                'changed' => false,
                'dry_run' => true,
                'would_migrate_from' => $current,
                'would_migrate_to' => $target,
                'would_lockdown_site_pools' => $lockdownSitePools,
                'plan' => $this->plan($current, $target, $lockdownSitePools),
                'before' => $before,
                'residual_risk' => $this->residualRisk(),
            ];
        }

        $this->journal = [];
        $this->log = [];

        try {
            $this->note("Migrating panel identity: {$current} → {$target}");

            // 1. ADDITIVE sudoers first: both identities are granted, so the
            //    panel keeps a working broker path while the pool switches.
            $this->writeSudoers(array_values(array_unique(array_filter([$current, $target]))), 'additive');

            // 2. Ownership of everything the pool must read/write.
            $this->migrateOwnership($target);

            // 3. Pool identity.
            $this->rewritePoolUser($panelPool, $target);

            // 4. Queue worker unit.
            $this->rewriteQueueUnit($target);

            // 5. broker.json + tmpfiles + scheduler cron line.
            $this->rewriteBrokerJson($target);
            $this->rewriteTmpfiles($target);
            $this->rewriteSchedulerCron($target);

            // 6. Reload the FPM master so the pool picks up the new identity.
            $this->reloadFpm($target);

            // 7. VERIFY the whole chain before dropping the old grant.
            $this->verify($target);

            // 8. Only now remove the old identity's grant.
            $this->writeSudoers([$target], 'final');
            $this->note("Sudoers now grants the broker to {$target} only.");

            // 9. Optional defence-in-depth (behaviour-changing → opt-in).
            if ($lockdownSitePools) {
                $this->lockdownSitePools();
            }

            $this->restartQueue();
        } catch (\Throwable $e) {
            $this->note('FAILED: ' . $e->getMessage());
            $reverted = $this->rollback();

            throw new BrokerException(
                'Panel hardening failed and was rolled back (' . $reverted . ' step(s) reverted). '
                . 'Host left in its previous working state. Error: ' . $e->getMessage()
                . ' | Log: ' . implode(' ; ', $this->log),
                1
            );
        }

        $after = $this->status();

        return [
            'changed' => true,
            'panel_user' => $target,
            'previous_user' => $current,
            'site_pools_locked_down' => $lockdownSitePools,
            'before' => $before,
            'after' => $after,
            'log' => $this->log,
            'residual_risk' => $this->residualRisk(),
        ];
    }

    // ---------------------------------------------------------------- planning

    /** @return list<string> */
    private function plan(string $current, string $target, bool $lockdown): array
    {
        $plan = [
            "write additive sudoers granting both {$current} and {$target}",
            "chown panel web tree, state dir, panel.sqlite, logs to {$target}",
            "rewrite panel FPM pool user/group/listen.owner/listen.group to {$target}",
            "rewrite queue unit User=/Group= to {$target}",
            'rewrite broker.json web_user, tmpfiles /run/php owner, scheduler cron user',
            'reload panel FPM master',
            'verify: unit active + socket owner + broker call as ' . $target,
            "rewrite sudoers to grant {$target} only",
            'restart queue worker',
        ];
        if ($lockdown) {
            $plan[] = 'append disable_functions (incl. proc_open) to SITE pools only';
        }

        return $plan;
    }

    private function residualRisk(): string
    {
        return 'Closed by this change: hosted-site PHP (site pool user) no longer holds any sudo '
            . 'grant to the broker, so site code execution is not a path to root. '
            . 'NOT closed here: on apt hosts the panel pool still shares one php-fpm master (and '
            . 'therefore one php.ini) with the site www pool, and deploy/lib/fpm.sh removes '
            . 'proc_open/proc_get_status from that shared php.ini so the panel can spawn sudo. '
            . 'Site pools can therefore still spawn processes unless --lockdown-site-pools is used '
            . '(per-pool disable_functions can only append to the global list, never remove from it). '
            . 'The structural fix is a dedicated panel php-fpm master on every OS family — ADR A39, '
            . 'next major. Also: the target identity (caddy) now holds the broker grant, so a Caddy '
            . 'compromise reaches the broker; a fully dedicated panel account is part of A39.';
    }

    // ------------------------------------------------------------- discovery

    private function panelPoolPath(): ?string
    {
        $candidates = [
            '/etc/azerioid-panel/php-fpm.d/azerioid-panel.conf',
            '/etc/php/' . $this->config->panelPhpVersion . '/fpm/pool.d/azerioid-panel.conf',
            '/etc/php-fpm.d/azerioid-panel.conf',
        ];
        foreach ($this->runtime->glob('/etc/php/*/fpm/pool.d/azerioid-panel.conf') as $g) {
            $candidates[] = $g;
        }
        foreach ($this->runtime->glob('/etc/opt/remi/php*/php-fpm.d/azerioid-panel.conf') as $g) {
            $candidates[] = $g;
        }
        foreach ($candidates as $path) {
            if ($this->runtime->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return array<string,string> path => user */
    private function sitePools(): array
    {
        $out = [];
        $globs = [
            '/etc/php/*/fpm/pool.d/*.conf',
            '/etc/php-fpm.d/*.conf',
            '/etc/opt/remi/php*/php-fpm.d/*.conf',
        ];
        foreach ($globs as $glob) {
            foreach ($this->runtime->glob($glob) as $path) {
                if (basename($path) === 'azerioid-panel.conf') {
                    continue;
                }
                if (str_contains($path, '/azerioid-panel/')) {
                    continue;
                }
                $user = $this->poolUser($path);
                if ($user !== null) {
                    $out[$path] = $user;
                }
            }
        }

        return $out;
    }

    private function poolUser(string $path): ?string
    {
        try {
            $body = $this->runtime->readFile($path);
        } catch (BrokerException) {
            return null;
        }
        if (preg_match('/^\s*user\s*=\s*(\S+)\s*$/m', $body, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function sudoersPath(): string
    {
        return '/etc/sudoers.d/azerioid-panel';
    }

    /** @return list<string> */
    private function sudoersUsers(): array
    {
        $path = $this->sudoersPath();
        if (!$this->runtime->fileExists($path)) {
            return [];
        }
        try {
            $body = $this->runtime->readFile($path);
        } catch (BrokerException) {
            return [];
        }
        $users = [];
        $broker = preg_quote(rtrim($this->config->panelRoot, '/') . '/broker', '/');
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(\S+)\s+ALL=\(root\)\s+NOPASSWD:\s*' . $broker . '\s*$/', $line, $m) === 1) {
                $users[] = $m[1];
            }
        }

        return array_values(array_unique($users));
    }

    /** @param list<string> $siteUsers */
    private function resolveTarget(array $siteUsers, bool $strict): string
    {
        // Prefer `caddy`: it is what detect_web_user() intends, what EL hosts
        // already run, and what the fixed installer produces — so hardened apt
        // hosts converge on the same state instead of forming a third variant.
        $preferred = ['caddy', 'www-data', 'apache'];
        foreach ($preferred as $candidate) {
            if (!$this->userExists($candidate)) {
                continue;
            }
            if (in_array($candidate, $siteUsers, true)) {
                continue;
            }

            return $candidate;
        }

        if ($strict) {
            throw new BrokerException(
                'No suitable panel identity found. Every candidate user either does not exist or is '
                . 'a site PHP-FPM pool user (' . implode(', ', $siteUsers) . '). '
                . 'Install Caddy (which creates the caddy user) or create a dedicated account first.',
                3
            );
        }

        return '';
    }

    private function userExists(string $user): bool
    {
        if ($user === '' || preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) !== 1) {
            return false;
        }

        return $this->runtime->exec(['/usr/bin/id', '-u', $user], null, 10)->ok();
    }

    private function sitePoolsHardened(): bool
    {
        foreach ($this->sitePools() as $path => $_user) {
            try {
                $body = $this->runtime->readFile($path);
            } catch (BrokerException) {
                return false;
            }
            if (!preg_match('/^\s*php_admin_value\[disable_functions\]\s*=.*\bproc_open\b/m', $body)) {
                return false;
            }
        }

        return true;
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
        $body = $this->runtime->readFile($path);
        $this->journal[] = ['type' => 'restore', 'path' => $path, 'body' => $body];
    }

    /** @param list<string> $users */
    private function writeSudoers(array $users, string $stage): void
    {
        if ($users === []) {
            throw new BrokerException('Refusing to write an empty sudoers grant.', 3);
        }
        $path = $this->sudoersPath();
        $this->backupFile($path);

        $broker = rtrim($this->config->panelRoot, '/') . '/broker';
        $body = "# AZERIOID Stack Manager — sudoers (panel hardening, R1)\n";
        foreach ($users as $u) {
            if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $u) !== 1) {
                throw new BrokerException('Refusing to write an invalid user into sudoers: ' . $u, 2);
            }
            $body .= "Defaults:{$u} !requiretty\n";
            $body .= "Defaults:{$u} umask=0022\n";
        }
        foreach ($users as $u) {
            $body .= "{$u} ALL=(root) NOPASSWD: {$broker}\n";
        }

        // Validate BEFORE replacing the live file.
        $tmp = rtrim($this->config->stagingDir, '/') . '/sudoers.azerioid-panel.check';
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        $this->runtime->writeFile($tmp, $body, 0440);
        $check = $this->runtime->exec(['/usr/sbin/visudo', '-c', '-f', $tmp], null, 15);
        if (!$check->ok()) {
            $this->runtime->deleteFile($tmp);
            throw new BrokerException('Generated sudoers failed visudo validation: ' . trim($check->stderr . ' ' . $check->stdout), 1);
        }
        $this->runtime->deleteFile($tmp);

        $this->runtime->writeFile($path, $body, 0440);
        $this->runtime->chmod($path, 0440);

        $post = $this->runtime->exec(['/usr/sbin/visudo', '-c'], null, 15);
        if (!$post->ok()) {
            throw new BrokerException('System sudoers invalid after write: ' . trim($post->stderr . ' ' . $post->stdout), 1);
        }
        $this->note("sudoers ({$stage}): " . implode(', ', $users));
    }

    private function migrateOwnership(string $target): void
    {
        $prefix = rtrim($this->config->panelRoot, '/');
        $recursive = [
            $prefix . '/web',
        ];
        $single = [
            '/var/lib/azerioid-panel',
            '/var/lib/azerioid-panel/panel.sqlite',
            '/var/lib/azerioid-panel/panel.sqlite-wal',
            '/var/lib/azerioid-panel/panel.sqlite-shm',
            $prefix . '/web/.env',
            '/var/log/azerioid-panel/php-fpm.log',
            '/var/log/azerioid-panel/auth-fail.log',
        ];

        foreach ($recursive as $path) {
            if (!$this->runtime->isDir($path)) {
                continue;
            }
            $prev = $this->ownerOf($path);
            $this->journal[] = ['type' => 'chown-r', 'path' => $path, 'owner' => $prev];
            $r = $this->runtime->exec(['/bin/chown', '-R', $target . ':' . $target, $path], null, 120);
            if (!$r->ok()) {
                throw new BrokerException('chown -R failed for ' . $path . ': ' . trim($r->stderr), 1);
            }
            $this->note("chown -R {$target} {$path}");
        }

        foreach ($single as $path) {
            if (!$this->runtime->fileExists($path) && !$this->runtime->isDir($path)) {
                continue;
            }
            $prev = $this->ownerOf($path);
            $this->journal[] = ['type' => 'chown', 'path' => $path, 'owner' => $prev];
            $r = $this->runtime->exec(['/bin/chown', $target . ':' . $target, $path], null, 30);
            if (!$r->ok()) {
                throw new BrokerException('chown failed for ' . $path . ': ' . trim($r->stderr), 1);
            }
        }
        $this->note('ownership migrated for panel state, env and logs');

        // The log DIR stays root-owned with the panel group (install parity).
        if ($this->runtime->isDir('/var/log/azerioid-panel')) {
            $prev = $this->ownerOf('/var/log/azerioid-panel');
            $this->journal[] = ['type' => 'chown', 'path' => '/var/log/azerioid-panel', 'owner' => $prev];
            $this->runtime->exec(['/bin/chown', 'root:' . $target, '/var/log/azerioid-panel'], null, 30);
        }
    }

    private function ownerOf(string $path): string
    {
        $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%U:%G', $path], null, 10);

        return $r->ok() ? trim($r->stdout) : 'root:root';
    }

    private function rewritePoolUser(string $path, string $target): void
    {
        $this->backupFile($path);
        $body = $this->runtime->readFile($path);
        $patched = preg_replace(
            [
                '/^(\s*user\s*=\s*)\S+\s*$/m',
                '/^(\s*group\s*=\s*)\S+\s*$/m',
                '/^(\s*listen\.owner\s*=\s*)\S+\s*$/m',
                '/^(\s*listen\.group\s*=\s*)\S+\s*$/m',
            ],
            '${1}' . $target,
            $body
        );
        if (!is_string($patched) || $patched === '') {
            throw new BrokerException('Failed to rewrite the panel pool config.', 1);
        }
        if (!preg_match('/^\s*user\s*=\s*' . preg_quote($target, '/') . '\s*$/m', $patched)) {
            throw new BrokerException('Panel pool config rewrite did not take effect.', 1);
        }
        $this->runtime->writeFile($path, $patched, 0644);
        $this->note("panel pool {$path}: user/group/listen.* → {$target}");
    }

    private function rewriteQueueUnit(string $target): void
    {
        $path = '/etc/systemd/system/' . $this->config->panelRuntimeQueueUnit;
        if (!$this->runtime->fileExists($path)) {
            return;
        }
        $this->backupFile($path);
        $body = $this->runtime->readFile($path);
        $patched = preg_replace(
            ['/^(User=)\S+\s*$/m', '/^(Group=)\S+\s*$/m'],
            '${1}' . $target,
            $body
        );
        if (!is_string($patched)) {
            throw new BrokerException('Failed to rewrite the queue unit.', 1);
        }
        $this->runtime->writeFile($path, $patched, 0644);
        $this->runtime->exec(['/usr/bin/systemctl', 'daemon-reload'], null, 30);
        $this->note("queue unit User/Group → {$target}");
    }

    private function rewriteBrokerJson(string $target): void
    {
        $path = '/etc/azerioid-panel/broker.json';
        if (!$this->runtime->fileExists($path)) {
            return;
        }
        $this->backupFile($path);
        $raw = $this->runtime->readFile($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new BrokerException('broker.json is not valid JSON; refusing to rewrite.', 1);
        }
        $data['web_user'] = $target;
        $this->runtime->writeFile(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0640
        );
        $this->note("broker.json web_user → {$target}");
    }

    private function rewriteTmpfiles(string $target): void
    {
        $path = '/etc/tmpfiles.d/azerioid-panel.conf';
        if (!$this->runtime->fileExists($path)) {
            return;
        }
        $this->backupFile($path);
        $this->runtime->writeFile($path, "d /run/php 0755 {$target} {$target} -\n", 0644);
        $this->runtime->exec(['/usr/bin/systemd-tmpfiles', '--create', $path], null, 30);
        $this->note("tmpfiles /run/php owner → {$target}");
    }

    private function rewriteSchedulerCron(string $target): void
    {
        $path = $this->config->cronDPath;
        if (!$this->runtime->fileExists($path)) {
            return;
        }
        $this->backupFile($path);
        $body = $this->runtime->readFile($path);
        $patched = preg_replace(
            '/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+)\S+(\s+)/m',
            '${1}' . $target . '${2}',
            $body
        );
        if (!is_string($patched)) {
            throw new BrokerException('Failed to rewrite the scheduler cron line.', 1);
        }
        $this->runtime->writeFile($path, $patched, 0644);
        $this->note("scheduler cron user → {$target}");
    }

    private function reloadFpm(string $target): void
    {
        $unit = $this->config->panelFpmUnit;
        $reload = Systemd::control($this->runtime, 'reload', $unit);
        $this->note("reload {$unit}: " . json_encode($reload['ok'] ?? null));
        if ($this->socketOwner() === $target) {
            return;
        }
        // Graceful reload did not re-own the socket — fall back to restart.
        $this->note("socket still owned by " . (string) $this->socketOwner() . "; restarting {$unit}");
        Systemd::control($this->runtime, 'restart', $unit);
    }

    private function socketOwner(): ?string
    {
        $socket = $this->config->panelFpmSocket;
        for ($i = 0; $i < 25; $i++) {
            if ($this->runtime->fileExists($socket)) {
                $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%U', $socket], null, 10);
                if ($r->ok()) {
                    return trim($r->stdout);
                }
            }
            usleep(200000);
        }

        return null;
    }

    private function verify(string $target): void
    {
        $unit = $this->config->panelFpmUnit;
        $state = Systemd::loadState($this->runtime, $unit);
        $active = $this->runtime->exec(['/usr/bin/systemctl', 'is-active', $unit], null, 15);
        if (trim($active->stdout) !== 'active') {
            throw new BrokerException("Panel FPM unit {$unit} is not active after reload (state={$state}).", 1);
        }
        $this->note("{$unit} active");

        $owner = $this->socketOwner();
        if ($owner !== $target) {
            throw new BrokerException(
                'Panel FPM socket ' . $this->config->panelFpmSocket . ' is owned by '
                . var_export($owner, true) . ', expected ' . $target . '.',
                1
            );
        }
        $this->note("socket {$this->config->panelFpmSocket} owned by {$target}");

        // The decisive check: can the new identity actually reach the broker?
        $broker = rtrim($this->config->panelRoot, '/') . '/broker';
        $call = $this->runtime->exec(
            ['/sbin/runuser', '-u', $target, '--', '/usr/bin/sudo', '-n', $broker, 'version.all'],
            null,
            60
        );
        if (!$call->ok()) {
            $call = $this->runtime->exec(
                ['/usr/sbin/runuser', '-u', $target, '--', '/usr/bin/sudo', '-n', $broker, 'version.all'],
                null,
                60
            );
        }
        $decoded = json_decode(trim($call->stdout), true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new BrokerException(
                'Broker call as ' . $target . ' did not succeed: '
                . substr(trim($call->stderr . ' ' . $call->stdout), 0, 400),
                1
            );
        }
        $this->note("broker call as {$target} succeeded");
    }

    private function restartQueue(): void
    {
        Systemd::control($this->runtime, 'restart', $this->config->panelRuntimeQueueUnit);
        $this->note('queue worker restarted');
    }

    /**
     * Defence in depth: disable process-spawning in SITE pools only.
     *
     * Per-pool disable_functions APPENDS to php.ini (it cannot remove entries),
     * so the panel pool keeps proc_open via the global list while site pools get
     * it disabled here. Behaviour-changing for hosted apps → opt-in.
     */
    private function lockdownSitePools(): void
    {
        $blocked = 'passthru,exec,shell_exec,system,proc_open,proc_get_status,popen,pcntl_exec,pcntl_fork,dl,chroot';
        foreach ($this->sitePools() as $path => $_user) {
            $body = $this->runtime->readFile($path);
            if (preg_match('/^\s*php_admin_value\[disable_functions\]\s*=.*\bproc_open\b/m', $body)) {
                continue;
            }
            $this->backupFile($path);
            $line = "php_admin_value[disable_functions] = {$blocked}\n";
            if (preg_match('/^\s*php_admin_value\[disable_functions\]\s*=.*$/m', $body)) {
                $patched = preg_replace('/^\s*php_admin_value\[disable_functions\]\s*=.*$/m', rtrim($line), $body, 1);
            } else {
                $patched = rtrim($body, "\n") . "\n" . $line;
            }
            if (!is_string($patched)) {
                throw new BrokerException('Failed to patch site pool ' . $path, 1);
            }
            $this->runtime->writeFile($path, $patched, 0644);
            $this->note("site pool hardened: {$path}");
        }
        Systemd::control($this->runtime, 'reload', $this->config->panelFpmUnit);
    }

    // --------------------------------------------------------------- rollback

    private function rollback(): int
    {
        $n = 0;
        foreach (array_reverse($this->journal) as $entry) {
            try {
                if ($entry['type'] === 'restore') {
                    $this->runtime->writeFile($entry['path'], $entry['body'], 0644);
                    if (str_contains($entry['path'], 'sudoers')) {
                        $this->runtime->chmod($entry['path'], 0440);
                    }
                    $n++;
                } elseif ($entry['type'] === 'unlink') {
                    if ($this->runtime->fileExists($entry['path'])) {
                        $this->runtime->deleteFile($entry['path']);
                    }
                    $n++;
                } elseif ($entry['type'] === 'chown') {
                    $this->runtime->exec(['/bin/chown', $entry['owner'], $entry['path']], null, 30);
                    $n++;
                } elseif ($entry['type'] === 'chown-r') {
                    $this->runtime->exec(['/bin/chown', '-R', $entry['owner'], $entry['path']], null, 120);
                    $n++;
                }
            } catch (\Throwable) {
                // Continue reverting the rest; report the count we managed.
            }
        }
        $this->runtime->exec(['/usr/bin/systemctl', 'daemon-reload'], null, 30);
        Systemd::control($this->runtime, 'restart', $this->config->panelFpmUnit);
        Systemd::control($this->runtime, 'restart', $this->config->panelRuntimeQueueUnit);

        return $n;
    }
}
