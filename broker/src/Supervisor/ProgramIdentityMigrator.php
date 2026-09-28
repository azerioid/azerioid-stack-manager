<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Supervisor;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\OctaneManager;
use AzerioidPanel\Broker\Vhost\Pm2Manager;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * Moves every site-bound Supervisor program from azerioid-supervised to its site's identity
 * (ADR A56), one program at a time.
 *
 * For each: note how it stands (the site's HTTP answer for Octane/PM2, the Supervisor state for
 * an operator's program), hand the site's files to its identity (what azerioid-supervised wrote
 * there is still its own), re-render the program as the site and restart it, and look again.
 * A program that worked before and does not now is put back on azerioid-supervised at once,
 * with the reason, and left for an operator; the others carry on. Docker programs wait for
 * their own daemons (A56 part 2).
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
        $state = [
            'result' => $failed === [] ? 'ok' : 'partial',
            'trigger' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => $this->runtime->now(),
            'programs' => $results,
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
        if ($p['kind'] === 'octane' || $p['kind'] === 'pm2') {
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
     * (put back; see reason). Docker programs and programs bound to no site are not listed.
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
            if (!is_string($domain) || $domain === '' || str_starts_with($name, \AzerioidPanel\Broker\Vhost\DockerManager::PROGRAM_PREFIX)) {
                continue;
            }
            $user = (string) ($row['user'] ?? SupervisedUser::USERNAME);
            $reason = ProgramIdentity::sharedReason($this->runtime, $domain);
            $want = ProgramIdentity::userFor($this->runtime, $domain, $name);
            $state = $reason !== null ? 'shared' : ($user === $want ? ($want === SupervisedUser::USERNAME ? 'legacy' : 'isolated') : 'pending');
            if ($state === 'legacy') {
                // Identity still on the pre-A49 group: A49 comes first.
                continue;
            }
            $out[] = [
                'name' => $name,
                'domain' => $domain,
                'kind' => str_starts_with($name, 'octane-') && $name === OctaneManager::programName($domain) ? 'octane'
                    : (str_starts_with($name, 'pm2-') && $name === Pm2Manager::programName($domain) ? 'pm2' : 'custom'),
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
