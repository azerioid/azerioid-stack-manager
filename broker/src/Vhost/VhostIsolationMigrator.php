<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Supervisor\SupervisorManager;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * ADR A49: move every vhost identity off the shared primary group.
 *
 * Before A49 each `az-vh-*` identity had `azerioid-vhosts` as its primary group and every
 * docroot was `2770 az-vh-X:azerioid-vhosts`, so one site's identity was a group member on
 * every other site's tree: its Terminal, SFTP login and cron jobs could read and write
 * every other site. End state, per identity:
 *
 *  - a group of its own (`az-vh-X`), which is its primary group;
 *  - every file of its docroot that was group `azerioid-vhosts` is group `az-vh-X`;
 *  - the readers (web server, site PHP pool users, azerioid-supervised) are members of
 *    `az-vh-X`; no other identity is.
 *
 * Migration model — the A39 standard: every mutation is journalled; the result is proven
 * for real (a cross-identity read that must now fail, every reader still able to open every
 * docroot, and no site that answered before answering with an error after); any failure
 * reverts all of it and records the failure, and automatic retries stop until an operator
 * retries. It runs in a transient unit of its own because it restarts the web server the
 * panel is served through.
 */
final class VhostIsolationMigrator
{
    public const CONVERGE_UNIT = 'azerioid-vhost-isolation';

    /** Root-only: the panel user must not be able to clear a failure. */
    public const STATE_FILE = '/etc/azerioid-panel/vhost-isolation.json';

    /** Present while install.sh runs. */
    public const INSTALLING_MARKER = '/run/azerioid-panel-installing';

    /** Directories a docroot may never be (a corrupt home in passwd must not chgrp the system). */
    private const FORBIDDEN_ROOTS = [
        '/', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib64', '/opt', '/proc', '/root',
        '/run', '/sbin', '/srv', '/sys', '/tmp', '/usr', '/var', '/var/lib', '/var/www',
    ];

    /** Ordered rollback journal: each entry undoes one mutation. */
    private array $journal = [];

    private array $log = [];

    /**
     * @param int $pollMicros pause between readiness polls (tests pass 0)
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
        private readonly int $pollMicros = 2000000,
    ) {
    }

    // ---------------------------------------------------------------- status

    /** @return array<string,mixed> */
    public function status(): array
    {
        $pending = $this->pendingRows($this->plan());
        $state = $this->readState();
        $running = $this->convergeRunning();
        $result = $state['result'] ?? null;
        $interrupted = $result === 'running' && !$running;
        $blocked = ($result === 'failed' && !$this->failedOnOlderRelease($state)) || $interrupted;
        $migrated = $pending === [];

        return [
            'migrated' => $migrated,
            'needs_migration' => !$migrated,
            'pending' => $pending,
            'last_attempt' => $state,
            'running' => $running,
            'interrupted' => $interrupted,
            'auto_eligible' => !$migrated && !$running && !$blocked,
            'verdict' => $migrated
                ? 'OK: every vhost identity has a group of its own; no site can open another site\'s files.'
                : ($blocked
                    ? ($interrupted
                        ? 'INTERRUPTED: an isolation migration stopped before finishing or rolling back. Check the host, then run: azerioid vhost isolation apply --confirm'
                        : 'FAILED: the last isolation migration was rolled back (' . ($state['error'] ?? 'unknown error')
                            . '). Retry with: azerioid vhost isolation apply --confirm')
                    : 'PENDING: ' . count($pending) . ' site director' . (count($pending) === 1 ? 'y is' : 'ies are')
                        . ' reachable from other sites (listed above). It is fixed automatically, or now with: azerioid vhost isolation apply --confirm'),
        ];
    }

    // ------------------------------------------------------------- triggers

    /** @return array<string,mixed> */
    public function apply(string $confirm, bool $dryRun = false): array
    {
        Validator::typedConfirm($confirm, Validator::ISOLATE_VHOSTS_CONFIRM);

        return $this->run($dryRun, 'operator');
    }

    /**
     * Automatic path. Without $now it only decides and hands off to a transient unit.
     *
     * @return array<string,mixed>
     */
    public function converge(bool $now): array
    {
        if ($this->runtime->fileExists(self::INSTALLING_MARKER)) {
            return ['started' => false, 'reason' => 'install.sh is running'];
        }
        $status = $this->status();
        if ($status['migrated']) {
            return ['started' => false, 'reason' => 'already migrated'];
        }
        if (($status['last_attempt']['result'] ?? null) === 'failed' && !$this->failedOnOlderRelease($status['last_attempt'])) {
            return ['started' => false, 'reason' => 'the last attempt failed and was rolled back; automatic retries are off until an operator runs: azerioid vhost isolation apply --confirm'];
        }
        if ($status['interrupted'] && !$now) {
            return ['started' => false, 'reason' => 'the last attempt was interrupted; an operator must run: azerioid vhost isolation apply --confirm'];
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
            '--description=AZERIOID vhost isolation migration (ADR A49)',
            $broker,
            'vhost.isolation.converge',
            'now',
        ], null, 30);
        if (!$start->ok()) {
            throw new BrokerException('Could not start the isolation migration unit: ' . trim($start->stderr . ' ' . $start->stdout), 1);
        }

        return ['started' => true, 'unit' => self::CONVERGE_UNIT . '.service'];
    }

    // -------------------------------------------------------------- migration

    /** @return array<string,mixed> */
    private function run(bool $dryRun, string $trigger): array
    {
        if ($this->updateInProgress()) {
            throw new BrokerException('A panel self-update is in progress; refusing to migrate vhost identities underneath it.', 3);
        }
        if ($trigger === 'operator' && $this->convergeRunning()) {
            throw new BrokerException('An automatic isolation migration is running right now (' . self::CONVERGE_UNIT . '.service); wait for it, then check: azerioid vhost isolation status', 3);
        }

        $plan = $this->plan();
        if ($plan['identities'] === [] && $plan['orphans'] === []) {
            return ['changed' => false, 'already_migrated' => true, 'status' => $this->status()];
        }
        foreach ($plan['identities'] as $item) {
            $this->assertSafeRoot($item['root']);
            $this->assertSafeRoot($item['top']);
        }
        foreach ($plan['orphans'] as $orphan) {
            $this->assertSafeRoot($orphan['path']);
        }
        $readers = VhostUser::readerUsers($this->runtime, $this->config);
        if ($dryRun) {
            return [
                'changed' => false,
                'dry_run' => true,
                'readers' => $readers,
                'plan' => $this->pendingRows($plan),
            ];
        }

        $this->journal = [];
        $this->log = [];
        $startedAt = $this->runtime->now();
        $this->writeState(['result' => 'running', 'trigger' => $trigger, 'started_at' => $startedAt]);
        $identities = $plan['identities'];
        $regrouped = array_values(array_filter($identities, static fn (array $i): bool => $i['primary_legacy'] || $i['supplementary_legacy']));

        try {
            $this->note('Converging ' . count($identities) . ' site(s) and ' . count($plan['orphans'])
                . " orphaned director" . (count($plan['orphans']) === 1 ? 'y' : 'ies') . " ({$trigger}); readers: " . implode(', ', $readers));
            $before = $this->probeSites();

            // Phase 1 is additive: new groups, readers in them, readers restarted. Readers now
            // hold both the legacy group and every new one, so no site loses its web server,
            // PHP pool or runtime while phase 2 moves files, however long that takes. A run
            // that only tightens directories adds nobody to anything and restarts nothing.
            $joined = false;
            foreach ($identities as $item) {
                $joined = $this->prepareGroup($item, $readers) || $joined;
            }
            if ($joined) {
                $this->refresh();
            }
            foreach ($identities as $item) {
                $this->regroup($item);
            }
            foreach ($plan['orphans'] as $orphan) {
                $this->quarantine($orphan);
            }
            $this->verify($before, $readers, $plan);

            // Committed. A process that was already running still carries the old group
            // list — a Terminal shell, an SFTP session, a running cron job — and keeps the
            // old reach until it exits, so end them. Only identities whose groups changed.
            foreach ($regrouped as $item) {
                $this->runtime->exec(['/usr/bin/pkill', '-TERM', '-u', $item['user']], null, 15);
            }
            if ($regrouped !== []) {
                $this->note('Ended processes started before the migration for: ' . implode(', ', array_column($regrouped, 'user')));
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->note('FAILED: ' . $error . ' — rolling back');
            $rollbackErrors = $this->rollback($joined ?? false);
            $this->writeState([
                'result' => 'failed',
                'trigger' => $trigger,
                'started_at' => $startedAt,
                'finished_at' => $this->runtime->now(),
                'error' => $error,
                'rollback_errors' => $rollbackErrors,
                'log' => $this->log,
            ]);

            throw new BrokerException(
                'Vhost isolation migration failed and was rolled back: ' . $error
                . ($rollbackErrors !== [] ? ' Rollback problems: ' . implode('; ', $rollbackErrors) : ''),
                $e instanceof BrokerException ? $e->errorCode : 1
            );
        }

        $this->writeState([
            'result' => 'migrated',
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => $this->runtime->now(),
            'identities' => array_column($identities, 'user'),
            'quarantined' => array_column($plan['orphans'], 'path'),
            'log' => $this->log,
        ]);

        return [
            'changed' => true,
            'identities' => array_column($identities, 'user'),
            'quarantined' => array_column($plan['orphans'], 'path'),
            'log' => $this->log,
        ];
    }

    /**
     * What is still reachable from another site, and why.
     *
     *  - identities: an identity still on the shared group, or whose site directory still
     *    carries it, or whose top directory under www_root is open to everyone. The top
     *    directory matters because a Laravel site's docroot is `<app>/public`: the app —
     *    `.env`, storage, the database — sits above the identity's home, where neither the
     *    A25 model nor A49's first pass looked.
     *  - orphans: a directory under www_root, or a cron log directory, that belongs to no
     *    vhost any more. Deleting a vhost keeps its files; the uid and gid they carry are
     *    handed to the next account created, which then owns another site's data.
     *
     * @return array{identities: list<array<string,mixed>>, orphans: list<array<string,mixed>>}
     */
    public function plan(): array
    {
        $legacyGid = '';
        $members = [];
        $legacy = $this->runtime->exec(['/usr/bin/getent', 'group', VhostUser::LEGACY_GROUP], null, 10);
        if ($legacy->ok() && trim($legacy->stdout) !== '') {
            $fields = explode(':', trim($legacy->stdout));
            $legacyGid = (string) ($fields[2] ?? '');
            $members = array_filter(explode(',', (string) ($fields[3] ?? '')));
        }

        $accounts = $this->identities();
        $tops = [];
        foreach ($accounts as $a) {
            $tops[$this->siteTop($a['root'])][] = $a['user'];
        }

        $identities = [];
        foreach ($accounts as $a) {
            $user = $a['user'];
            $home = $a['root'];
            if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $user)) {
                continue;
            }
            $top = $this->siteTop($home);
            // Two sites under one directory (x/public and x/admin) cannot both own it.
            if (count($tops[$top] ?? []) > 1) {
                $top = $home;
            }
            $primary = $legacyGid !== '' && $a['gid'] === $legacyGid;
            $supplementary = in_array($user, $members, true);
            $legacyFiles = $legacyGid !== '' && $this->hasLegacyFiles($top);
            [$topGroup, $topMode] = $this->statGroupMode($top);
            $topOpen = $topMode !== null && ((int) substr($topMode, -1)) !== 0;
            // A top directory with some other group (www-data after an edit, say) cannot be
            // closed to everyone without first giving the identity its way in.
            $topForeign = $topGroup !== null && $topGroup !== $user && $topGroup !== VhostUser::LEGACY_GROUP
                && ($topOpen || $top !== $home);
            if (!$primary && !$supplementary && !$legacyFiles && !$topOpen && !$topForeign) {
                continue;
            }
            $identities[] = [
                'user' => $user,
                'root' => $home,
                'top' => $top,
                'group' => $user,
                'primary_legacy' => $primary,
                'supplementary_legacy' => $supplementary,
                'legacy_files' => $legacyFiles,
                'top_open' => $topOpen,
                'top_mode' => $topMode,
                'top_group' => $topGroup,
                'reason' => implode('; ', array_filter([
                    $primary ? 'primary group is ' . VhostUser::LEGACY_GROUP : null,
                    $supplementary ? 'member of ' . VhostUser::LEGACY_GROUP : null,
                    $legacyFiles ? "files under {$top} owned by group " . VhostUser::LEGACY_GROUP : null,
                    $topOpen ? "{$top} is open to everyone (mode {$topMode})" : null,
                    $topForeign && !$topOpen ? "{$top} belongs to group {$topGroup}" : null,
                ])),
            ];
        }

        return ['identities' => $identities, 'orphans' => $this->orphans($accounts)];
    }

    /**
     * @param  list<array{user:string, root:string, gid:string}>  $accounts
     * @return list<array{path:string, uid:string, gid:string, mode:string, owner:string, reason:string}>
     */
    private function orphans(array $accounts): array
    {
        $inUse = $this->inUsePaths($accounts);
        $out = [];
        $www = rtrim($this->config->wwwRoot, '/');
        foreach ($this->listDirs($www) as $d) {
            if ($d['uid'] === '0' || $this->pathInUse($d['path'], $inUse)) {
                continue;
            }
            $out[] = $d + ['reason' => 'belongs to no vhost; owned by ' . $d['owner'] . ':' . $d['group'] . ' mode ' . $d['mode']];
        }
        $names = array_column($accounts, 'user');
        foreach ($this->listDirs(self::CRON_LOG_DIR) as $d) {
            $name = basename($d['path']);
            if (!str_starts_with($name, VhostUser::PREFIX) || in_array($name, $names, true)) {
                continue;
            }
            if ($d['uid'] === '0' && $d['mode'] === '700') {
                continue;
            }
            $out[] = $d + ['reason' => 'cron logs of an identity that no longer exists'];
        }

        return $out;
    }

    /**
     * 0700 with the setgid bit cleared. GNU chmod keeps a directory's setuid/setgid bits on a
     * numeric mode ("chmod 0700" on a 2770 directory leaves 2700), so it is said explicitly.
     */
    private const QUARANTINE_MODE = 'u=rwx,go=,ug-s';

    /** Where cron job output lives, one directory per identity (A47). */
    private const CRON_LOG_DIR = '/var/log/azerioid-cron';

    /** @return list<array{path:string, uid:string, gid:string, mode:string, owner:string, group:string}> */
    private function listDirs(string $base): array
    {
        if (!$this->runtime->isDir($base)) {
            return [];
        }
        $r = $this->runtime->exec([
            '/usr/bin/find', $base, '-mindepth', '1', '-maxdepth', '1', '-type', 'd',
            '-printf', '%U\t%G\t%m\t%u\t%g\t%p\n',
        ], null, 60);
        $out = [];
        foreach (explode("\n", $r->stdout) as $line) {
            $f = explode("\t", $line);
            if (count($f) !== 6 || !str_starts_with($f[5], $base . '/')) {
                continue;
            }
            $out[] = ['uid' => $f[0], 'gid' => $f[1], 'mode' => $f[2], 'owner' => $f[3], 'group' => $f[4], 'path' => $f[5]];
        }

        return $out;
    }

    /**
     * Everything a live site or program needs: vhost roots, identity homes, Supervisor
     * program directories.
     *
     * @param  list<array{user:string, root:string}>  $accounts
     * @return list<string>
     */
    private function inUsePaths(array $accounts): array
    {
        $paths = array_column($accounts, 'root');
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            $root = (string) ($vhost['root'] ?? '');
            if ($root !== '') {
                $paths[] = $root;
            }
        }
        try {
            foreach ((new SupervisorManager($this->config, $this->runtime))->listPrograms()['programs'] as $row) {
                $dir = (string) ($row['directory'] ?? '');
                if ($dir !== '') {
                    $paths[] = $dir;
                }
            }
        } catch (BrokerException) {
            // No Supervisor, no programs.
        }
        // Programs, units and sites the panel did not create still count: a hand-written
        // Supervisor program working in /data/www/x must not lose x to a quarantine.
        $www = rtrim($this->config->wwwRoot, '/');
        foreach (self::REFERENCE_GLOBS as $pattern) {
            foreach ($this->runtime->glob($pattern) as $file) {
                try {
                    $body = $this->runtime->readFile($file);
                } catch (\Throwable) {
                    continue;
                }
                if (preg_match_all('#' . preg_quote($www, '#') . '/[^\s"\';{}(),]+#', $body, $m) > 0) {
                    array_push($paths, ...$m[0]);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** Configuration that can name a directory under www_root without the panel knowing. */
    private const REFERENCE_GLOBS = [
        '/etc/supervisor/conf.d/*.conf',
        '/etc/supervisord.d/*.ini',
        '/etc/supervisord.d/*.conf',
        '/etc/systemd/system/*.service',
        '/etc/caddy/Caddyfile',
        '/etc/caddy/conf.d/*',
        '/etc/apache2/sites-enabled/*',
        '/etc/httpd/conf.d/*.conf',
        '/etc/nginx/sites-enabled/*',
        '/etc/nginx/conf.d/*.conf',
        '/etc/cron.d/*',
        '/etc/crontab',
        '/var/spool/cron/crontabs/*',
        '/var/spool/cron/*',
    ];

    /** @param list<string> $inUse */
    private function pathInUse(string $dir, array $inUse): bool
    {
        $dir = rtrim($dir, '/') . '/';
        foreach ($inUse as $p) {
            if (str_starts_with(rtrim($p, '/') . '/', $dir)) {
                return true;
            }
        }

        return false;
    }

    /** The directory directly under www_root that holds a home, or the home itself elsewhere. */
    private function siteTop(string $home): string
    {
        $www = rtrim($this->config->wwwRoot, '/') . '/';
        if (!str_starts_with($home, $www)) {
            return $home;
        }
        $first = explode('/', substr($home, strlen($www)))[0];

        return $first === '' ? $home : $www . $first;
    }

    /** @return array{0:?string, 1:?string} group name and octal mode */
    private function statGroupMode(string $path): array
    {
        if (!$this->runtime->isDir($path)) {
            return [null, null];
        }
        $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%G %a', $path], null, 10);
        $f = preg_split('/\s+/', trim($r->stdout)) ?: [];
        if (!$r->ok() || count($f) !== 2 || !preg_match('/^[0-7]{3,4}$/', $f[1])) {
            return [null, null];
        }

        return [$f[0], $f[1]];
    }

    /**
     * @param  array{identities: list<array<string,mixed>>, orphans: list<array<string,mixed>>}  $plan
     * @return list<array{user:string, root:string, reason:string}>
     */
    private function pendingRows(array $plan): array
    {
        $rows = [];
        foreach ($plan['identities'] as $i) {
            $rows[] = ['user' => $i['user'], 'root' => $i['top'], 'reason' => $i['reason']];
        }
        foreach ($plan['orphans'] as $o) {
            $rows[] = ['user' => $o['owner'], 'root' => $o['path'], 'reason' => $o['reason']];
        }

        return $rows;
    }

    /**
     * @param  array{user:string, root:string, group:string}  $item
     * @param  list<string>  $readers
     * @return bool whether anything was added
     */
    private function prepareGroup(array $item, array $readers): bool
    {
        $changed = false;
        $group = $item['group'];
        if (!VhostUser::groupExists($this->runtime, $group)) {
            $this->must(['/usr/sbin/groupadd', '--system', $group], "create group {$group}");
            $this->journal[] = ['cmd' => ['/usr/sbin/groupdel', $group], 'what' => "delete group {$group}"];
            $changed = true;
        }
        foreach ($readers as $reader) {
            if (VhostUser::userInGroup($this->runtime, $reader, $group)) {
                continue;
            }
            $this->must(['/usr/bin/gpasswd', '-a', $reader, $group], "add {$reader} to {$group}");
            $this->journal[] = ['cmd' => ['/usr/bin/gpasswd', '-d', $reader, $group], 'what' => "remove {$reader} from {$group}"];
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function regroup(array $item): void
    {
        $user = $item['user'];
        $group = $item['group'];
        $top = $item['top'];
        $legacy = VhostUser::LEGACY_GROUP;

        // Files before the primary group: usermod -g would otherwise chgrp the home tree
        // on its own terms. find -h never follows a symlink a site planted to escape its
        // tree, and -xdev never walks into another filesystem mounted under it.
        if ($item['legacy_files'] && $this->runtime->isDir($top)) {
            $this->must($this->chgrpCommand($top, $legacy, $group), "regroup {$top} {$legacy} → {$group}");
            $this->journal[] = ['cmd' => $this->chgrpCommand($top, $group, $legacy), 'what' => "regroup {$top} back to {$legacy}"];
        }
        // The top directory is the gate: once it is the site's group and closed to everyone
        // else, nothing below it is reachable by another site, whatever mode PHP gave a file.
        if ($this->runtime->isDir($top)) {
            [$nowGroup, $nowMode] = $this->statGroupMode($top);
            if ($nowGroup !== null && $nowGroup !== $group) {
                $this->must(['/usr/bin/chgrp', '-h', $group, $top], "give {$top} to {$group}");
                $this->journal[] = ['cmd' => ['/usr/bin/chgrp', '-h', $nowGroup, $top], 'what' => "give {$top} back to {$nowGroup}"];
            }
            if ($nowMode !== null && ((int) substr($nowMode, -1)) !== 0) {
                $this->must(['/usr/bin/chmod', 'o-rwx', $top], "close {$top} to everyone else");
                $this->journal[] = ['cmd' => ['/usr/bin/chmod', $nowMode, $top], 'what' => "reopen {$top} ({$nowMode})"];
            }
        }
        if ($item['primary_legacy']) {
            $this->must(['/usr/sbin/usermod', '-g', $group, $user], "primary group of {$user} → {$group}");
            $this->journal[] = ['cmd' => ['/usr/sbin/usermod', '-g', $legacy, $user], 'what' => "primary group of {$user} back to {$legacy}"];
        }
        if ($item['supplementary_legacy']) {
            $this->must(['/usr/bin/gpasswd', '-d', $user, $legacy], "remove {$user} from {$legacy}");
            $this->journal[] = ['cmd' => ['/usr/bin/gpasswd', '-a', $user, $legacy], 'what' => "add {$user} back to {$legacy}"];
        }
        $this->note("Isolated {$user} ({$top})");
    }

    /**
     * Root-owned and closed: the uid and gid the files carry can be reassigned to any new
     * account without handing it the data. Only the top directory changes, so an operator
     * can still recover the contents exactly as they were.
     *
     * @param  array{path:string, uid:string, gid:string, mode:string}  $orphan
     */
    private function quarantine(array $orphan): void
    {
        $path = $orphan['path'];
        $this->must(['/usr/bin/chown', '-h', '0:0', $path], "quarantine {$path}");
        $this->journal[] = ['cmd' => ['/usr/bin/chown', '-h', $orphan['uid'] . ':' . $orphan['gid'], $path], 'what' => "hand {$path} back to {$orphan['uid']}:{$orphan['gid']}"];
        $this->must(['/usr/bin/chmod', self::QUARANTINE_MODE, $path], "close {$path}");
        $this->journal[] = ['cmd' => ['/usr/bin/chmod', $orphan['mode'], $path], 'what' => "reopen {$path} ({$orphan['mode']})"];
        $this->note("Quarantined {$path} (was {$orphan['owner']}:{$orphan['group']} {$orphan['mode']})");
    }

    /**
     * After a vhost is deleted: quarantine its site directory (unless another site or program
     * still uses it) and its cron logs. Its identity is gone, so the uid and gid those carry
     * are free for the next account.
     *
     * @return list<string> what was quarantined
     */
    public function quarantineRemains(string $root, string $username): array
    {
        $done = [];
        if ($root !== '' && !$this->isForbiddenRoot($root)) {
            $top = $this->siteTop($root);
            $www = rtrim($this->config->wwwRoot, '/');
            $inUse = $this->inUsePaths($this->identities());
            if ($top !== $www && !$this->isForbiddenRoot($top) && !$this->pathInUse($top, $inUse)
                && self::quarantineDirectory($this->runtime, $top)) {
                $done[] = $top;
            }
        }
        if (str_starts_with($username, VhostUser::PREFIX) && !str_contains($username, '/')) {
            $logs = self::CRON_LOG_DIR . '/' . $username;
            if (self::quarantineDirectory($this->runtime, $logs)) {
                $done[] = $logs;
            }
        }

        return $done;
    }

    /**
     * The same, outside a migration: used when a vhost is deleted and its files are kept.
     * Best effort — the next converge run finds anything this could not close.
     */
    public static function quarantineDirectory(Runtime $runtime, string $path): bool
    {
        if (!$runtime->isDir($path) || $runtime->getuid() !== 0) {
            return false;
        }
        $a = $runtime->exec(['/usr/bin/chown', '-h', '0:0', $path], null, 30);
        $b = $runtime->exec(['/usr/bin/chmod', self::QUARANTINE_MODE, $path], null, 30);

        return $a->ok() && $b->ok();
    }

    /**
     * Readers pick up their new groups; the web server restarts now (this runs in its own unit).
     * Supervised programs (Octane, PM2, Docker, custom) run as azerioid-supervised with the
     * group list they started with, and would lose their own site's files — restart them.
     */
    private function refresh(): void
    {
        VhostUser::refreshReaders($this->runtime, $this->config, false);
        $this->restartSupervised();
        $this->note('Readers joined the new groups: reloaded web/PHP services, restarted running supervised programs');
    }

    private function restartSupervised(): void
    {
        try {
            $supervisor = new SupervisorManager($this->config, $this->runtime);
            $programs = $supervisor->listPrograms()['programs'];
        } catch (BrokerException) {
            return;
        }
        foreach ($programs as $row) {
            $status = $row['status'] ?? '';
            $state = is_array($status) ? (string) ($status['state'] ?? '') : (string) $status;
            if (stripos($state, 'RUNNING') === false) {
                continue;
            }
            try {
                $supervisor->control((string) $row['name'], 'restart');
            } catch (BrokerException $e) {
                $this->note('Could not restart ' . $row['name'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Prove the end state for real.
     *
     * @param  array<string,int>  $before  HTTP status per domain, captured before any change
     * @param  list<string>  $readers
     */
    private function verify(array $before, array $readers, array $plan): void
    {
        $identities = $this->identities();

        // 0. Every site identity can still open its own site, top directory included.
        foreach ($plan['identities'] as $item) {
            foreach (array_unique([$item['top'], $item['root']]) as $dir) {
                if (!$this->runtime->isDir($dir)) {
                    continue;
                }
                $probe = $this->runtime->exec(['/usr/sbin/runuser', '-u', $item['user'], '--', '/usr/bin/test', '-r', $dir, '-a', '-x', $dir], null, 15);
                if (!$probe->ok()) {
                    throw new BrokerException("{$item['user']} can no longer open its own {$dir}.", 1);
                }
            }
        }
        foreach ($plan['orphans'] as $orphan) {
            $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%u:%g %a', $orphan['path']], null, 10);
            if (trim($r->stdout) !== '0:0 700') {
                throw new BrokerException("{$orphan['path']} is not quarantined (" . trim($r->stdout) . ').', 1);
            }
        }

        // 1. The point of the migration: one identity can no longer open another's docroot.
        $checked = 0;
        foreach ($identities as $x) {
            foreach ($identities as $y) {
                if ($x['user'] === $y['user'] || $checked >= 200 || $this->overlaps($x['root'], $y['root'])) {
                    continue;
                }
                $target = $this->siteTop($y['root']);
                if ($this->overlaps($x['root'], $target)) {
                    $target = $y['root'];
                }
                if (!$this->runtime->isDir($target) || $this->worldReadable($target)) {
                    continue;
                }
                $checked++;
                $probe = $this->runtime->exec(['/usr/sbin/runuser', '-u', $x['user'], '--', '/usr/bin/test', '-r', $target], null, 15);
                if ($probe->ok()) {
                    throw new BrokerException("{$x['user']} can still read {$target}.", 1);
                }
            }
        }
        $this->note("Verified {$checked} cross-identity read(s) are refused");

        // 2. Every reader can still open every docroot, with the group list a new process gets.
        foreach ($identities as $id) {
            if (!$this->runtime->isDir($id['root'])) {
                continue;
            }
            foreach ($readers as $reader) {
                $probe = $this->runtime->exec(['/usr/sbin/runuser', '-u', $reader, '--', '/usr/bin/test', '-r', $id['root'], '-a', '-x', $id['root']], null, 15);
                if (!$probe->ok()) {
                    throw new BrokerException("{$reader} can no longer open {$id['root']}.", 1);
                }
            }
        }
        $this->note('Verified ' . count($readers) . ' reader(s) can open every docroot');

        // 3. No site that answered before answers with an error now.
        $after = [];
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $after = $this->probeSites(array_keys($before));
            $broken = $this->degraded($before, $after);
            if ($broken === []) {
                break;
            }
            $this->pause();
        }
        $broken = $this->degraded($before, $after);
        if ($broken !== []) {
            throw new BrokerException('Sites answer with an error after the change: ' . implode(', ', $broken) . '.', 1);
        }
        $this->note('Verified ' . count($before) . ' site(s) still answer as before');
    }

    /**
     * @param  array<string,int>  $before
     * @param  array<string,int>  $after
     * @return list<string>
     */
    private function degraded(array $before, array $after): array
    {
        $broken = [];
        foreach ($before as $domain => $code) {
            $now = $after[$domain] ?? 0;
            if ($code >= 200 && $code < 400 && ($now === 0 || $now >= 400)) {
                $broken[] = "{$domain} {$code}→{$now}";
            }
        }

        return $broken;
    }

    /**
     * @param  list<string>|null  $only
     * @return array<string,int>
     */
    private function probeSites(?array $only = null): array
    {
        $domains = $only;
        if ($domains === null) {
            $domains = [];
            try {
                foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
                    $domain = (string) ($vhost['domain'] ?? '');
                    if ($domain !== '' && empty($vhost['readonly']) && preg_match('/^[a-z0-9.-]+$/i', $domain)) {
                        $domains[] = $domain;
                    }
                }
            } catch (\Throwable) {
                return [];
            }
        }
        $codes = [];
        foreach (array_slice(array_values(array_unique($domains)), 0, 100) as $domain) {
            $code = $this->httpCode('https', $domain, 443);
            if ($code === 0) {
                $code = $this->httpCode('http', $domain, 80);
            }
            $codes[$domain] = $code;
        }

        return $codes;
    }

    private function httpCode(string $scheme, string $domain, int $port): int
    {
        $r = $this->runtime->exec([
            '/usr/bin/curl', '-sk', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '10',
            '--resolve', "{$domain}:{$port}:127.0.0.1", "{$scheme}://{$domain}/",
        ], null, 20);

        return (int) trim($r->stdout);
    }

    /** @return list<array{user:string, root:string, gid:string}> */
    private function identities(): array
    {
        $passwd = $this->runtime->exec(['/usr/bin/getent', 'passwd'], null, 10);
        $out = [];
        foreach (explode("\n", $passwd->stdout) as $line) {
            $row = explode(':', trim($line));
            if (count($row) >= 7 && str_starts_with($row[0], VhostUser::PREFIX)) {
                $out[] = ['user' => $row[0], 'root' => $row[5], 'gid' => $row[3]];
            }
        }

        return $out;
    }

    /** A docroot open to everyone is not something the group model grants or can take away. */
    private function worldReadable(string $root): bool
    {
        $r = $this->runtime->exec(['/usr/bin/stat', '-c', '%a', $root], null, 10);
        $mode = trim($r->stdout);
        if (!$r->ok() || !preg_match('/^[0-7]{3,4}$/', $mode)) {
            return false;
        }
        $readable = ((int) substr($mode, -1) & 4) === 4;
        if ($readable) {
            $this->note("{$root} is readable by everyone (mode {$mode}); not a group grant, left as it is");
        }

        return $readable;
    }

    private function overlaps(string $a, string $b): bool
    {
        $a = rtrim($a, '/') . '/';
        $b = rtrim($b, '/') . '/';

        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    private function hasLegacyFiles(string $root): bool
    {
        if ($root === '' || !$this->runtime->isDir($root) || $this->isForbiddenRoot($root)) {
            return false;
        }
        $r = $this->runtime->exec([
            '/usr/bin/find', $root, '-xdev', '-group', VhostUser::LEGACY_GROUP, '-print', '-quit',
        ], null, 60);

        return $r->ok() && trim($r->stdout) !== '';
    }

    /** @return list<string> */
    private function chgrpCommand(string $root, string $from, string $to): array
    {
        return ['/usr/bin/find', $root, '-xdev', '-group', $from, '-exec', '/usr/bin/chgrp', '-h', $to, '{}', '+'];
    }

    private function assertSafeRoot(string $root): void
    {
        if ($this->isForbiddenRoot($root)) {
            throw new BrokerException("Refusing to regroup {$root}: it is not a site directory.", 3);
        }
    }

    private function isForbiddenRoot(string $root): bool
    {
        $norm = '/' . trim($root, '/');

        return !str_starts_with($root, '/') || str_contains($root, '..') || in_array($norm, self::FORBIDDEN_ROOTS, true);
    }

    /** @param list<string> $cmd */
    private function must(array $cmd, string $what): void
    {
        $r = $this->runtime->exec($cmd, null, 600);
        if (!$r->ok()) {
            throw new BrokerException("Could not {$what}: " . trim($r->stderr . ' ' . $r->stdout), 1);
        }
    }

    /** @return list<string> */
    private function rollback(bool $refresh): array
    {
        $errors = [];
        foreach (array_reverse($this->journal) as $entry) {
            $r = $this->runtime->exec($entry['cmd'], null, 600);
            if (!$r->ok()) {
                $errors[] = $entry['what'] . ': ' . trim($r->stderr . ' ' . $r->stdout);
            }
        }
        $this->journal = [];
        if ($refresh) {
            try {
                VhostUser::refreshReaders($this->runtime, $this->config, false);
                $this->restartSupervised();
            } catch (\Throwable $e) {
                $errors[] = 'refresh after rollback: ' . $e->getMessage();
            }
        }
        $this->note($errors === [] ? 'Rolled back cleanly' : 'Rolled back with ' . count($errors) . ' problem(s)');

        return $errors;
    }

    private function pause(): void
    {
        if ($this->pollMicros > 0) {
            usleep($this->pollMicros);
        }
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        if (!$this->runtime->fileExists(self::STATE_FILE)) {
            return [];
        }
        $decoded = json_decode($this->runtime->readFile(self::STATE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $state['version'] = $this->panelVersion();
        $this->runtime->mkdir(dirname(self::STATE_FILE), 0750);
        $this->runtime->writeFile(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }

    /**
     * A failure recorded by an older release does not stop this one from trying once on
     * its own: a new release is new code, and a host should not wait for an operator to
     * find out whether it fixed what failed. A failure of this release still stops it.
     *
     * @param  array<string,mixed>  $attempt
     */
    private function failedOnOlderRelease(array $attempt): bool
    {
        $current = $this->panelVersion();

        return $current !== '' && ($attempt['version'] ?? '') !== $current;
    }

    private function panelVersion(): string
    {
        $path = rtrim($this->config->panelRoot, '/') . '/VERSION';
        try {
            return $this->runtime->fileExists($path) ? trim($this->runtime->readFile($path)) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function convergeRunning(): bool
    {
        $r = $this->runtime->exec(['/usr/bin/systemctl', 'is-active', self::CONVERGE_UNIT . '.service'], null, 10);

        return trim($r->stdout) === 'active' || trim($r->stdout) === 'activating';
    }

    private function updateInProgress(): bool
    {
        $r = $this->runtime->exec(['/usr/bin/pgrep', '-f', 'panel\.update\.apply'], null, 10);

        return $r->ok() && trim($r->stdout) !== '';
    }
}
