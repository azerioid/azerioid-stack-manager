<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Supervisor;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Component\DockerRootlessSetup;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\OctaneManager;
use AzerioidPanel\Broker\Vhost\Pm2Manager;
use AzerioidPanel\Broker\Vhost\SiteDocker;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * Moves every site-bound Supervisor program from azerioid-supervised to its site's identity
 * (ADR A56), one program at a time.
 *
 * For each: note how it stands (the site's HTTP answer for Octane/PM2, the Supervisor state for
 * an operator's program), hand the site's files to its identity (what azerioid-supervised wrote
 * there is still its own), re-render the program as the site and restart it, and look again.
 * A program that worked before and does not now is put back on azerioid-supervised at once,
 * with the reason, and left for an operator; the others carry on.
 *
 * A Docker site moves to a daemon of its own (A56 part 2): the new daemon gets the image while
 * the old container serves, the files the old containers wrote in the site (owned by the shared
 * daemon's subordinate ids) are renumbered into the site's own range, and the program restarts
 * as the site. A compose project with named volumes is not moved automatically: their data is
 * inside the shared daemon and would be left behind.
 *
 * Started by the scheduler in a transient unit of its own, like A49 and A55.
 */
final class ProgramIdentityMigrator
{
    public const CONVERGE_UNIT = 'azerioid-program-identity';

    public const STATE_FILE = '/etc/azerioid-panel/program-identity-migration.json';

    public const INSTALLING_MARKER = '/run/azerioid-panel-installing';

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
        private readonly int $pollMicros = 1000000,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $programs = $this->programs();
        $pending = array_values(array_filter($programs, static fn (array $p): bool => $p['state'] === 'pending'));
        $shared = array_values(array_filter($programs, static fn (array $p): bool => $p['state'] === 'shared'));
        $running = $this->convergeRunning();
        $done = $pending === [];

        return [
            'migrated' => $done && $shared === [],
            'programs' => $programs,
            'pending' => array_column($pending, 'name'),
            'shared' => $shared,
            'last_attempt' => $this->readState(),
            'running' => $running,
            'auto_eligible' => !$done && !$running,
            'verdict' => match (true) {
                $done && $shared === [] => 'OK: every site-bound program runs as its own site; none can open another site\'s files.',
                $done => 'ATTENTION: ' . count($shared) . ' program(s) were put back on ' . SupervisedUser::USERNAME
                    . ' because they stopped working as their site (' . implode(', ', array_column($shared, 'name'))
                    . '). Fix them, then retry: azerioid process identity apply --confirm',
                default => 'PENDING: ' . count($pending) . ' program(s) to move to their site\'s account. '
                    . 'It is done automatically, or now with: azerioid process identity apply --confirm',
            },
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $confirm, ?string $only = null): array
    {
        Validator::typedConfirm($confirm, Validator::ISOLATE_PROGRAMS_CONFIRM);
        if ($this->convergeRunning()) {
            throw new BrokerException('An automatic program migration is running (' . self::CONVERGE_UNIT . '.service); wait for it, then check: azerioid process identity status', 3);
        }

        return $this->run('operator', true, $only);
    }

    /** @return array<string,mixed> */
    public function converge(bool $now): array
    {
        if ($this->runtime->fileExists(self::INSTALLING_MARKER)) {
            return ['started' => false, 'reason' => 'install.sh is running'];
        }
        if ($now) {
            return $this->run('automatic', false, null);
        }
        $status = $this->status();
        if (!$status['auto_eligible']) {
            return ['started' => false, 'reason' => $status['running'] ? 'a migration is already running' : 'nothing to do'];
        }
        if ($this->updateInProgress()) {
            return ['started' => false, 'reason' => 'a panel self-update is in progress'];
        }
        $start = $this->runtime->exec([
            '/usr/bin/systemd-run',
            '--unit=' . self::CONVERGE_UNIT,
            '--collect',
            '--description=AZERIOID site programs as their site (ADR A56)',
            rtrim($this->config->panelRoot, '/') . '/broker',
            'program.identity.converge',
            'now',
        ], null, 30);
        if (!$start->ok()) {
            throw new BrokerException('Could not start the program migration unit: ' . trim($start->stderr . ' ' . $start->stdout), 1);
        }

        return ['started' => true, 'unit' => self::CONVERGE_UNIT . '.service'];
    }

    /** @return array<string,mixed> */
    private function run(string $trigger, bool $retryShared, ?string $only): array
    {
        if ($this->updateInProgress()) {
            throw new BrokerException('A panel self-update is in progress; refusing to move programs underneath it.', 3);
        }
        $todo = array_values(array_filter(
            $this->programs(),
            static fn (array $p): bool => ($p['state'] === 'pending' || ($retryShared && $p['state'] === 'shared'))
                && ($only === null || $p['domain'] === $only),
        ));
        if ($only !== null && $todo === []) {
            throw new BrokerException("{$only} has no site-bound program to move.", 3);
        }

        $startedAt = $this->runtime->now();
        $this->writeState(['result' => 'running', 'trigger' => $trigger, 'started_at' => $startedAt]);
        $results = [];
        foreach ($todo as $program) {
            try {
                $results[$program['name']] = $this->move($program);
            } catch (\Throwable $e) {
                $results[$program['name']] = ['result' => 'error', 'reason' => $e->getMessage()];
            }
        }
        $failed = array_filter($results, static fn (array $r): bool => $r['result'] !== 'isolated');
        $detached = $only === null ? $this->detachSharedAccount() : [];
        $state = [
            'result' => $failed === [] ? 'ok' : 'partial',
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => $this->runtime->now(),
            'programs' => $results,
            'detached_from' => $detached,
        ];
        $this->writeState($state);

        return ['changed' => $results !== []] + $state;
    }

    /**
     * @param  array{name:string, domain:string, kind:string, state:string, directory:string}  $p
     * @return array<string,mixed>
     */
    private function move(array $p): array
    {
        $domain = $p['domain'];
        if ($p['state'] === 'shared') {
            ProgramIdentity::clear($this->runtime, $domain);
        }
        if ($p['kind'] === 'docker') {
            return $this->moveDocker($p);
        }
        $before = $this->health($p);
        $identity = VhostUser::username($domain);
        $this->handOver($p['directory'], $identity, VhostUser::docrootGroup($this->runtime, $domain));
        $error = null;
        try {
            $this->rerender($p);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $after = $this->health($p);
        for ($i = 0; $i < 5 && $error === null && $this->broke($before, $after); $i++) {
            $this->pause();
            $after = $this->health($p);
        }
        if ($error === null && !$this->broke($before, $after)) {
            if ($p['kind'] === 'pm2') {
                $old = SupervisedUser::HOME . '/pm2/' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)), '-');
                if ($this->runtime->isDir($old)) {
                    $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $old], null, 30);
                }
            }

            return ['result' => 'isolated', 'user' => $identity, 'before' => $before, 'after' => $after];
        }

        $reason = $error ?? "{$before} before the move, {$after} after";
        ProgramIdentity::putBack($this->runtime, $domain, $reason);
        try {
            $this->rerender($p);
        } catch (\Throwable $e) {
            $reason .= '; restoring it as ' . SupervisedUser::USERNAME . ' also failed: ' . $e->getMessage();
        }

        return ['result' => 'shared', 'before' => $before, 'after' => $this->health($p), 'reason' => $reason];
    }

    /**
     * Part 3: once no site program runs as azerioid-supervised, it leaves every site's group —
     * the last way from a shared process into a site. A site put back later lets it into that
     * one site's group only (ProgramIdentity::putBack).
     *
     * @return list<string> groups it left
     */
    private function detachSharedAccount(): array
    {
        if ($this->runtime->getuid() !== 0) {
            return [];
        }
        foreach ($this->programs() as $p) {
            if ($p['state'] !== 'isolated') {
                return [];
            }
        }
        $left = [];
        $keep = array_map(static fn (string $d): string => VhostUser::docrootGroup($this->runtime, $d), array_keys(ProgramIdentity::shared($this->runtime)));
        $groups = $this->runtime->exec(['/usr/bin/id', '-nG', SupervisedUser::USERNAME], null, 10);
        foreach (preg_split('/\s+/', trim($groups->stdout)) ?: [] as $group) {
            if (str_starts_with($group, VhostUser::PREFIX) && !in_array($group, $keep, true)) {
                $this->runtime->exec(['/usr/bin/gpasswd', '-d', SupervisedUser::USERNAME, $group], null, 30);
                $left[] = $group;
            }
        }
        $this->runtime->mkdir(dirname(ProgramIdentity::DETACHED_MARKER), 0750);
        $this->runtime->writeFile(ProgramIdentity::DETACHED_MARKER, $this->runtime->now() . "\n", 0600);

        return $left;
    }

    /** @return array<string,mixed> */
    private function moveDocker(array $p): array
    {
        $domain = $p['domain'];
        $before = $this->health($p);
        $volumes = $this->namedVolumes($domain);
        if ($volumes !== []) {
            $reason = 'compose named volumes (' . implode(', ', $volumes) . ') live in the shared daemon and would be left behind; '
                . 'move their data into bind-mounted directories of the app, then retry';
            ProgramIdentity::putBack($this->runtime, $domain, $reason);

            return ['result' => 'shared', 'before' => $before, 'after' => $before, 'reason' => $reason];
        }
        $siteDocker = new SiteDocker($this->config, $this->runtime);
        $docker = new DockerManager($this->config, $this->runtime);
        $error = null;
        $remapped = false;
        try {
            $siteDocker->ensure($domain);
            $this->remap($p['directory'], $this->sharedRange(), $siteDocker->subIdStart(VhostUser::username($domain)),
                SupervisedUser::USERNAME, VhostUser::username($domain));
            $remapped = true;
            if (!$docker->moveToSiteDaemon($domain)) {
                $error = 'the container did not listen again on its own daemon';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $after = $this->health($p);
        for ($i = 0; $i < 5 && $error === null && $this->broke($before, $after); $i++) {
            $this->pause();
            $after = $this->health($p);
        }
        if ($error === null && !$this->broke($before, $after)) {
            return ['result' => 'isolated', 'user' => VhostUser::username($domain), 'before' => $before, 'after' => $after];
        }

        $reason = $error ?? "{$before} before the move, {$after} after";
        ProgramIdentity::putBack($this->runtime, $domain, $reason);
        try {
            if ($remapped) {
                $this->remap($p['directory'], $siteDocker->subIdStart(VhostUser::username($domain)), $this->sharedRange(),
                    VhostUser::username($domain), SupervisedUser::USERNAME);
            }
            $docker->moveToSharedDaemon($domain);
            $siteDocker->remove($domain);
        } catch (\Throwable $e) {
            $reason .= '; restoring it on the shared daemon also failed: ' . $e->getMessage();
        }

        return ['result' => 'shared', 'before' => $before, 'after' => $this->health($p), 'reason' => $reason];
    }

    /** Named volumes of the site's compose project in the shared daemon. */
    private function namedVolumes(string $domain): array
    {
        $host = (new DockerRootlessSetup($this->config, $this->runtime))->dockerHost();
        $r = $this->runtime->exec([
            '/usr/sbin/runuser', '-u', SupervisedUser::USERNAME, '--', '/usr/bin/env', 'DOCKER_HOST=' . $host,
            $this->runtime->fileExists('/usr/local/bin/docker') && !$this->runtime->fileExists('/usr/bin/docker') ? '/usr/local/bin/docker' : '/usr/bin/docker',
            'volume', 'ls', '-q', '--filter', 'label=com.docker.compose.project=' . DockerManager::composeProject($domain),
        ], null, 30);
        if (!$r->ok()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $r->stdout)), static fn (string $v): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $v) === 1));
    }

    private function sharedRange(): ?int
    {
        return (new SiteDocker($this->config, $this->runtime))->subIdStart(SupervisedUser::USERNAME);
    }

    /**
     * Renumber what containers wrote in the site: the old daemon owner (a container's root)
     * becomes the new one, and ids of the old subordinate range move to the same offset in the
     * new range, so a container's non-root user still owns its files.
     */
    private function remap(string $directory, ?int $fromStart, ?int $toStart, string $fromOwner, string $toOwner): void
    {
        $top = $this->topOf($directory);
        if ($top === null || !$this->runtime->isDir($top)) {
            return;
        }
        $fromUid = $this->idOf('-u', $fromOwner);
        $toUid = $this->idOf('-u', $toOwner);
        $fromGid = $this->idOf('-g', $fromOwner);
        $toGid = $this->idOf('-g', $toOwner);
        $list = $this->runtime->exec(['/usr/bin/find', $top, '-xdev', '-printf', '%U %G\n'], null, 600);
        $uids = [];
        $gids = [];
        foreach (explode("\n", $list->stdout) as $line) {
            if (preg_match('/^(\d+) (\d+)$/', trim($line), $m) === 1) {
                $uids[(int) $m[1]] = true;
                $gids[(int) $m[2]] = true;
            }
        }
        $map = static function (int $id, ?int $from, ?int $to, ?int $ownerFrom, ?int $ownerTo): ?int {
            if ($ownerFrom !== null && $ownerTo !== null && $id === $ownerFrom) {
                return $ownerTo;
            }
            if ($from !== null && $to !== null && $id >= $from && $id < $from + 65536) {
                return $to + ($id - $from);
            }

            return null;
        };
        foreach (array_keys($uids) as $uid) {
            $new = $map($uid, $fromStart, $toStart, $fromUid, $toUid);
            if ($new !== null) {
                $this->runtime->exec(['/usr/bin/find', $top, '-xdev', '-uid', (string) $uid, '-exec', '/usr/bin/chown', '-h', (string) $new, '{}', '+'], null, 600);
            }
        }
        foreach (array_keys($gids) as $gid) {
            $new = $map($gid, $fromStart, $toStart, $fromGid, $toGid);
            if ($new !== null) {
                $this->runtime->exec(['/usr/bin/find', $top, '-xdev', '-gid', (string) $gid, '-exec', '/usr/bin/chgrp', '-h', (string) $new, '{}', '+'], null, 600);
            }
        }
    }

    private function idOf(string $flag, string $user): ?int
    {
        $r = $this->runtime->exec(['/usr/bin/id', $flag, $user], null, 10);

        return $r->ok() && preg_match('/^\d+$/', trim($r->stdout)) ? (int) trim($r->stdout) : null;
    }

    /** Re-render the program for the account ProgramIdentity now names, and restart it. */
    private function rerender(array $p): void
    {
        if ($p['kind'] === 'pm2') {
            if (!(new Pm2Manager($this->config, $this->runtime))->reapply($p['domain'])) {
                throw new BrokerException('the PM2 app did not listen again after the restart', 1);
            }

            return;
        }
        $supervisor = new SupervisorManager($this->config, $this->runtime);
        $supervisor->update($p['name'], []);
        $supervisor->control($p['name'], 'restart');
    }

    /**
     * The site's files, with what azerioid-supervised left in them, become the site's; group
     * write stays so a put-back program still works.
     */
    private function handOver(string $directory, string $user, string $group): void
    {
        $top = $this->topOf($directory);
        if ($top === null || !$this->runtime->isDir($top)) {
            return;
        }
        $this->runtime->exec(['/usr/bin/chown', '-R', '-h', $user . ':' . $group, $top], null, 600);
        $this->runtime->exec(['/usr/bin/chmod', '-R', 'g+rwX', $top], null, 600);
    }

    /** "HTTP 200" for a site-serving program, the Supervisor state for an operator's. */
    private function health(array $p): string
    {
        if (in_array($p['kind'], ['octane', 'pm2', 'docker'], true)) {
            return 'HTTP ' . $this->httpCode($p['domain']);
        }
        $state = (new SupervisorManager($this->config, $this->runtime))->status($p['name'])['status']['state'] ?? 'unknown';

        return strtoupper((string) $state);
    }

    private function broke(string $before, string $after): bool
    {
        if (str_starts_with($before, 'HTTP ')) {
            $b = (int) substr($before, 5);
            $a = (int) substr($after, 5);

            return $b > 0 && $b < 500 && ($a === 0 || $a >= 500);
        }

        return $before === 'RUNNING' && $after !== 'RUNNING';
    }

    /**
     * Site-bound programs and where they stand: isolated (runs as its site), pending, or shared
     * (put back; see reason). Programs bound to no site are not listed.
     *
     * @return list<array{name:string, domain:string, kind:string, user:string, state:string, directory:string, reason:?string}>
     */
    private function programs(): array
    {
        $supervisor = new SupervisorManager($this->config, $this->runtime);
        try {
            $rows = $supervisor->listPrograms()['programs'];
        } catch (BrokerException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $domain = $row['vhost_domain'] ?? null;
            if (!is_string($domain) || $domain === '') {
                continue;
            }
            $isDocker = $name === DockerManager::programName($domain);
            $user = (string) ($row['user'] ?? SupervisedUser::USERNAME);
            $reason = ProgramIdentity::sharedReason($this->runtime, $domain);
            // A Docker program's target is the site even before its daemon exists: the move makes one.
            $want = $isDocker && $reason === null && ProgramIdentity::userFor($this->runtime, $domain, '') !== SupervisedUser::USERNAME
                ? VhostUser::username($domain)
                : ProgramIdentity::userFor($this->runtime, $domain, $name);
            $state = $reason !== null ? 'shared' : ($user === $want ? ($want === SupervisedUser::USERNAME ? 'legacy' : 'isolated') : 'pending');
            if ($state === 'legacy') {
                // Identity still on the pre-A49 group: A49 comes first.
                continue;
            }
            $out[] = [
                'name' => $name,
                'domain' => $domain,
                'kind' => $isDocker ? 'docker' : (str_starts_with($name, 'octane-') && $name === OctaneManager::programName($domain) ? 'octane'
                    : (str_starts_with($name, 'pm2-') && $name === Pm2Manager::programName($domain) ? 'pm2' : 'custom')),
                'user' => $user,
                'state' => $state,
                'directory' => (string) ($row['directory'] ?? ''),
                'reason' => $reason,
            ];
        }

        return $out;
    }

    private function topOf(string $dir): ?string
    {
        $www = rtrim($this->config->wwwRoot, '/') . '/';
        $dir = rtrim($dir, '/');
        if (!str_starts_with($dir . '/', $www) || $dir . '/' === $www) {
            return null;
        }

        return $www . explode('/', substr($dir, strlen($www)))[0];
    }

    private function httpCode(string $domain): int
    {
        foreach ([['https', 443], ['http', 80]] as [$scheme, $port]) {
            $r = $this->runtime->exec([
                '/usr/bin/curl', '-sk', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15',
                '--resolve', "{$domain}:{$port}:127.0.0.1", "{$scheme}://{$domain}/",
            ], null, 25);
            $code = (int) trim($r->stdout);
            if ($code > 0) {
                return $code;
            }
        }

        return 0;
    }

    private function pause(): void
    {
        if ($this->pollMicros > 0) {
            usleep($this->pollMicros);
        }
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        if (!$this->runtime->fileExists(self::STATE_FILE)) {
            return [];
        }
        $d = json_decode($this->runtime->readFile(self::STATE_FILE), true);

        return is_array($d) ? $d : [];
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

        return in_array(trim($r->stdout), ['active', 'activating'], true);
    }

    private function updateInProgress(): bool
    {
        $r = $this->runtime->exec(['/usr/bin/pgrep', '-f', 'panel\.update\.apply'], null, 10);

        return $r->ok() && trim($r->stdout) !== '';
    }
}
