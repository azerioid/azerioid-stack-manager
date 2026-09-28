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
        $pending = $this->plan();
        $state = $this->readState();
        $running = $this->convergeRunning();
        $result = $state['result'] ?? null;
        $interrupted = $result === 'running' && !$running;
        $blocked = $result === 'failed' || $interrupted;
        $migrated = $pending === [];

        return [
            'migrated' => $migrated,
            'needs_migration' => !$migrated,
            'pending' => array_map(static fn (array $p): array => [
                'user' => $p['user'],
                'root' => $p['root'],
                'reason' => $p['reason'],
            ], $pending),
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
                    : 'PENDING: ' . count($pending) . ' vhost identit' . (count($pending) === 1 ? 'y shares' : 'ies share')
                        . ' the azerioid-vhosts group and can open other sites\' files. It migrates automatically, or now with: azerioid vhost isolation apply --confirm'),
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
        if (($status['last_attempt']['result'] ?? null) === 'failed') {
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
        if ($plan === []) {
            return ['changed' => false, 'already_migrated' => true, 'status' => $this->status()];
        }
        foreach ($plan as $item) {
            $this->assertSafeRoot($item['root']);
        }
        $readers = VhostUser::readerUsers($this->runtime, $this->config);
        if ($dryRun) {
            return [
                'changed' => false,
                'dry_run' => true,
                'readers' => $readers,
                'plan' => $plan,
            ];
        }

        $this->journal = [];
        $this->log = [];
        $startedAt = $this->runtime->now();
        $this->writeState(['result' => 'running', 'trigger' => $trigger, 'started_at' => $startedAt]);

        try {
            $this->note('Isolating ' . count($plan) . ' vhost identit' . (count($plan) === 1 ? 'y' : 'ies') . " ({$trigger}); readers: " . implode(', ', $readers));
            $before = $this->probeSites();

            // Phase 1 is additive: new groups, readers in them, readers restarted. Readers now
            // hold both the legacy group and every new one, so no site loses its web server,
            // PHP pool or runtime while phase 2 moves files, however long that takes.
            foreach ($plan as $item) {
                $this->prepareGroup($item, $readers);
            }
            $this->refresh();
            foreach ($plan as $item) {
                $this->regroup($item);
            }
            $this->verify($before, $readers);

            // Committed. A process that was already running still carries the old group
            // list — a Terminal shell, an SFTP session, a running cron job — and keeps the
            // old reach until it exits, so end them.
            foreach ($plan as $item) {
                $this->runtime->exec(['/usr/bin/pkill', '-TERM', '-u', $item['user']], null, 15);
            }
            $this->note('Ended processes started before the migration for: ' . implode(', ', array_column($plan, 'user')));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->note('FAILED: ' . $error . ' — rolling back');
            $rollbackErrors = $this->rollback();
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
            'identities' => array_column($plan, 'user'),
            'log' => $this->log,
        ]);

        return ['changed' => true, 'identities' => array_column($plan, 'user'), 'log' => $this->log];
    }

    /**
     * Identities still on the legacy model, and why.
     *
     * @return list<array{user:string, root:string, group:string, primary_legacy:bool, supplementary_legacy:bool, residue:bool, reason:string}>
     */
    public function plan(): array
    {
        $legacy = $this->runtime->exec(['/usr/bin/getent', 'group', VhostUser::LEGACY_GROUP], null, 10);
        if (!$legacy->ok() || trim($legacy->stdout) === '') {
            return [];
        }
        $fields = explode(':', trim($legacy->stdout));
        $legacyGid = (string) ($fields[2] ?? '');
        $members = array_filter(explode(',', (string) ($fields[3] ?? '')));

        $passwd = $this->runtime->exec(['/usr/bin/getent', 'passwd'], null, 10);
        $plan = [];
        foreach (explode("\n", $passwd->stdout) as $line) {
            $row = explode(':', trim($line));
            if (count($row) < 7 || !str_starts_with($row[0], VhostUser::PREFIX)) {
                continue;
            }
            [$user, , , $gid, , $home] = $row;
            if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $user)) {
                continue;
            }
            $primary = $gid === $legacyGid;
            $supplementary = in_array($user, $members, true);
            $residue = !$primary && !$supplementary && $this->hasLegacyFiles($home);
            if (!$primary && !$supplementary && !$residue) {
                continue;
            }
            $reasons = array_filter([
                $primary ? 'primary group is ' . VhostUser::LEGACY_GROUP : null,
                $supplementary ? 'member of ' . VhostUser::LEGACY_GROUP : null,
                ($primary || $residue) && $this->hasLegacyFiles($home) ? 'docroot files owned by group ' . VhostUser::LEGACY_GROUP : null,
            ]);
            $plan[] = [
                'user' => $user,
                'root' => $home,
                'group' => $user,
                'primary_legacy' => $primary,
                'supplementary_legacy' => $supplementary,
                'residue' => $residue,
                'reason' => implode('; ', $reasons),
            ];
        }

        return $plan;
    }

    /**
     * @param  array{user:string, root:string, group:string}  $item
     * @param  list<string>  $readers
     */
    private function prepareGroup(array $item, array $readers): void
    {
        $group = $item['group'];
        if (!VhostUser::groupExists($this->runtime, $group)) {
            $this->must(['/usr/sbin/groupadd', '--system', $group], "create group {$group}");
            $this->journal[] = ['cmd' => ['/usr/sbin/groupdel', $group], 'what' => "delete group {$group}"];
        }
        foreach ($readers as $reader) {
            if (VhostUser::userInGroup($this->runtime, $reader, $group)) {
                continue;
            }
            $this->must(['/usr/bin/gpasswd', '-a', $reader, $group], "add {$reader} to {$group}");
            $this->journal[] = ['cmd' => ['/usr/bin/gpasswd', '-d', $reader, $group], 'what' => "remove {$reader} from {$group}"];
        }
    }

    /**
     * @param  array{user:string, root:string, group:string, primary_legacy:bool, supplementary_legacy:bool}  $item
     */
    private function regroup(array $item): void
    {
        $user = $item['user'];
        $group = $item['group'];
        $root = $item['root'];
        $legacy = VhostUser::LEGACY_GROUP;

        // Files before the primary group: usermod -g would otherwise chgrp the home tree
        // on its own terms. find -h never follows a symlink a site planted to escape its
        // tree, and -xdev never walks into another filesystem mounted under it.
        if ($this->runtime->isDir($root)) {
            $this->must($this->chgrpCommand($root, $legacy, $group), "regroup {$root} {$legacy} → {$group}");
            $this->journal[] = ['cmd' => $this->chgrpCommand($root, $group, $legacy), 'what' => "regroup {$root} back to {$legacy}"];
        }
        if ($item['primary_legacy']) {
            $this->must(['/usr/sbin/usermod', '-g', $group, $user], "primary group of {$user} → {$group}");
            $this->journal[] = ['cmd' => ['/usr/sbin/usermod', '-g', $legacy, $user], 'what' => "primary group of {$user} back to {$legacy}"];
        }
        if ($item['supplementary_legacy']) {
            $this->must(['/usr/bin/gpasswd', '-d', $user, $legacy], "remove {$user} from {$legacy}");
            $this->journal[] = ['cmd' => ['/usr/bin/gpasswd', '-a', $user, $legacy], 'what' => "add {$user} back to {$legacy}"];
        }
        $this->note("Isolated {$user} ({$root})");
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
    private function verify(array $before, array $readers): void
    {
        $identities = $this->identities();

        // 1. The point of the migration: one identity can no longer open another's docroot.
        $checked = 0;
        foreach ($identities as $x) {
            foreach ($identities as $y) {
                if ($x['user'] === $y['user'] || $checked >= 200 || $this->overlaps($x['root'], $y['root'])) {
                    continue;
                }
                if (!$this->runtime->isDir($y['root']) || $this->worldReadable($y['root'])) {
                    continue;
                }
                $checked++;
                $probe = $this->runtime->exec(['/usr/sbin/runuser', '-u', $x['user'], '--', '/usr/bin/test', '-r', $y['root']], null, 15);
                if ($probe->ok()) {
                    throw new BrokerException("{$x['user']} can still read {$y['root']}.", 1);
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

    /** @return list<array{user:string, root:string}> */
    private function identities(): array
    {
        $passwd = $this->runtime->exec(['/usr/bin/getent', 'passwd'], null, 10);
        $out = [];
        foreach (explode("\n", $passwd->stdout) as $line) {
            $row = explode(':', trim($line));
            if (count($row) >= 7 && str_starts_with($row[0], VhostUser::PREFIX)) {
                $out[] = ['user' => $row[0], 'root' => $row[5]];
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
    private function rollback(): array
    {
        $errors = [];
        foreach (array_reverse($this->journal) as $entry) {
            $r = $this->runtime->exec($entry['cmd'], null, 600);
            if (!$r->ok()) {
                $errors[] = $entry['what'] . ': ' . trim($r->stderr . ' ' . $r->stdout);
            }
        }
        $this->journal = [];
        try {
            VhostUser::refreshReaders($this->runtime, $this->config, false);
            $this->restartSupervised();
        } catch (\Throwable $e) {
            $errors[] = 'refresh after rollback: ' . $e->getMessage();
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
        $this->runtime->mkdir(dirname(self::STATE_FILE), 0750);
        $this->runtime->writeFile(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
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
