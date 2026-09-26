<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Cron;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * Cron management (B2 / request #4).
 *
 * The security win of this phase is in one line: a site's scheduled job runs as that
 * site's own identity (`az-vh-*`, A25), not as root. Until now the panel's only cron
 * feature was the root crontab, so "run my site's queue worker every minute" meant
 * giving that site root — for a job whose command the site's own code could often
 * influence.
 *
 * Root jobs are still possible, because host maintenance legitimately needs them, but
 * they require a typed confirmation every time: the asymmetry is deliberate.
 */
final class CronManager
{
    public const ROOT_CONFIRM = 'RUN-AS-ROOT';

    private const CRONTAB = '/usr/bin/crontab';

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    private function store(): CronStore
    {
        return new CronStore($this->runtime);
    }

    private function wrapperPath(): string
    {
        return rtrim($this->config->panelRoot, '/') . '/azerioid-cron-run';
    }

    /** @return array<string,mixed> */
    public function list(?string $owner = null): array
    {
        $jobs = $this->store()->all();
        if ($owner !== null && $owner !== '') {
            $owner = strtolower(trim($owner));
            $jobs = array_values(array_filter($jobs, static fn (CronJob $j): bool => $j->owner === $owner));
        }

        return [
            'jobs' => array_map(static fn (CronJob $j): array => $j->toArray(), $jobs),
            'log_dir' => CronRenderer::LOG_DIR,
            // What the host had before the panel touched it, so an operator can see
            // the panel is not hiding anything it chose not to manage.
            'unmanaged_root_lines' => $this->unmanagedRootLines(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function add(array $input): array
    {
        $job = CronJob::fromInput($input, $this->runtime->now());
        $this->assertOwnerUsable($job, $input);

        $jobs = $this->store()->all();
        $jobs[] = $job;
        $this->store()->save($jobs);
        $this->apply($job->owner);

        return ['job' => $job->toArray()];
    }

    /** @return array<string,mixed> */
    public function delete(string $id): array
    {
        $job = $this->store()->find($id);
        $remaining = array_values(array_filter(
            $this->store()->all(),
            static fn (CronJob $j): bool => $j->id !== $id
        ));
        $this->store()->save($remaining);
        $this->apply($job->owner);

        return ['deleted' => $job->toArray()];
    }

    /** @return array<string,mixed> */
    public function setEnabled(string $id, bool $enabled, array $input): array
    {
        $job = $this->store()->find($id);
        if ($enabled && $job->runsAsRoot()) {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), self::ROOT_CONFIRM);
        }
        $updated = $job->withEnabled($enabled);
        $jobs = array_map(
            static fn (CronJob $j): CronJob => $j->id === $id ? $updated : $j,
            $this->store()->all()
        );
        $this->store()->save($jobs);
        $this->apply($job->owner);

        return ['job' => $updated->toArray()];
    }

    /**
     * Run a job now, as the identity it would normally run as, through the same
     * wrapper — so "it works when I run it" and "it works on schedule" are the same
     * statement.
     *
     * @return array<string,mixed>
     */
    public function runNow(string $id): array
    {
        $job = $this->store()->find($id);
        $this->installWrapper();

        $this->prepareLogDir($job->runsAs());
        $log = CronRenderer::logPathFor($job->runsAs(), $job->id);
        $command = $job->runsAsRoot()
            ? ['/bin/sh', $this->wrapperPath(), $job->id, $log, $job->command]
            : [$this->runuser(), '-u', $job->runsAs(), '--', '/bin/sh', $this->wrapperPath(), $job->id, $log, $job->command];

        $result = $this->runtime->exec($command, null, 300);

        return [
            'id' => $job->id,
            'ran_as' => $job->runsAs(),
            'exit_code' => $result->exitCode,
            'ok' => $result->ok(),
            'output' => substr(trim($result->stdout . "\n" . $result->stderr), 0, 20000),
            'log' => $log,
        ];
    }

    /** @return array<string,mixed> */
    public function log(string $id, int $lines = 200): array
    {
        $job = $this->store()->find($id);
        $path = CronRenderer::logPathFor($job->runsAs(), $job->id);
        if (!$this->runtime->fileExists($path)) {
            return ['id' => $job->id, 'path' => $path, 'missing' => true, 'lines' => []];
        }
        $body = explode("\n", rtrim($this->runtime->readFile($path), "\n"));

        return [
            'id' => $job->id,
            'path' => $path,
            'missing' => false,
            'lines' => array_slice($body, -max(1, min($lines, 2000))),
        ];
    }

    /**
     * Root is not refused — host maintenance needs it — but it is never the quiet
     * default. A vhost owner must also actually exist, or the rendered crontab would
     * be installed for a user cron will refuse to run as.
     *
     * @param  array<string,mixed>  $input
     */
    private function assertOwnerUsable(CronJob $job, array $input): void
    {
        if ($job->runsAsRoot()) {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), self::ROOT_CONFIRM);

            return;
        }
        $user = $job->runsAs();
        if (!$this->userExists($user)) {
            throw new BrokerException(
                'There is no system identity for ' . $job->owner . ' (expected ' . $user . '). '
                . 'Run `azerioid vhost reconcile --repair`, or create the vhost first.',
                3
            );
        }
    }

    private function userExists(string $user): bool
    {
        foreach (explode("\n", $this->runtime->fileExists('/etc/passwd') ? $this->runtime->readFile('/etc/passwd') : '') as $line) {
            if (str_starts_with($line, $user . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Install the crontab for one identity from state. Only that identity's file is
     * touched, so a failure cannot take out another site's jobs.
     */
    private function apply(string $owner): void
    {
        $user = $owner === CronJob::RUN_AS_ROOT ? 'root' : VhostUser::username($owner);
        $jobs = array_values(array_filter(
            $this->store()->all(),
            static fn (CronJob $j): bool => $j->owner === $owner
        ));

        $this->installWrapper();
        $this->prepareLogDir($user);

        $renderer = new CronRenderer();
        $existing = $this->runtime->exec([self::CRONTAB, '-u', $user, '-l'], null, 15);
        // A user with no crontab yet makes `crontab -l` fail; that is not an error.
        $body = $renderer->render(
            $existing->ok() ? $existing->stdout : '',
            $jobs,
            $this->wrapperPath()
        );

        $staging = rtrim($this->config->stagingDir, '/') . '/crontab.' . $user;
        $this->runtime->mkdir($this->config->stagingDir, 0750);
        $this->runtime->writeFile($staging, $body, 0600);
        try {
            $install = $this->runtime->exec([self::CRONTAB, '-u', $user, $staging], null, 15);
        } finally {
            $this->runtime->deleteFile($staging);
        }
        if (!$install->ok()) {
            throw new BrokerException(
                'cron refused the generated crontab for ' . $user . ': '
                . trim($install->stderr !== '' ? $install->stderr : $install->stdout),
                1
            );
        }
    }

    /**
     * The directory a job writes into must be owned by the identity that runs it, or
     * the wrapper's redirection fails and the command never runs.
     */
    private function prepareLogDir(string $runsAs): void
    {
        $this->runtime->mkdir(CronRenderer::LOG_DIR, 0751);
        $dir = CronRenderer::LOG_DIR . '/' . $runsAs;
        $this->runtime->mkdir($dir, 0750);
        if ($runsAs !== 'root') {
            $this->runtime->chown($dir, $runsAs, $runsAs);
        }
    }

    private function installWrapper(): void
    {
        $path = $this->wrapperPath();
        $script = (new CronRenderer())->wrapperScript();
        if (!$this->runtime->fileExists($path) || $this->runtime->readFile($path) !== $script) {
            // 0755: every vhost identity has to be able to execute it, none to write it.
            $this->runtime->writeFile($path, $script, 0755);
            $this->runtime->chmod($path, 0755);
        }
    }

    private function runuser(): string
    {
        foreach (['/sbin/runuser', '/usr/sbin/runuser'] as $candidate) {
            if ($this->runtime->fileExists($candidate)) {
                return $candidate;
            }
        }

        throw new BrokerException('runuser is not available; cannot run a job as a vhost identity.', 3);
    }

    /**
     * Root crontab lines that were there before the panel, reported so an operator can
     * see them without leaving the panel — and so it is obvious the panel is not
     * silently in charge of them.
     *
     * @return list<string>
     */
    private function unmanagedRootLines(): array
    {
        $existing = $this->runtime->exec([self::CRONTAB, '-u', 'root', '-l'], null, 15);
        if (!$existing->ok()) {
            return [];
        }
        $preserved = (new CronRenderer())->withoutManagedBlock($existing->stdout);

        return array_values(array_filter(
            array_map('trim', explode("\n", $preserved)),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));
    }
}
