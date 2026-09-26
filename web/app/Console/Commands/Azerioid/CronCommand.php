<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

/**
 * `azerioid cron …` (B2 / request #4).
 *
 * Full parity with the page, including the root confirmation — a scheduled job is the
 * kind of thing operators script, and a CLI that quietly skipped the confirmation would
 * make the guard on the page decorative.
 */
class CronCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:cron
        {action : list|add|del|enable|disable|run|log}
        {id? : job id for del/enable/disable/run/log}
        {--owner= : vhost domain, or root}
        {--schedule= : five fields or an @ shorthand (not @reboot)}
        {--command= : command to run}
        {--note= : short label}
        {--confirm= : RUN-AS-ROOT, required for a root job}
        {--lines=200 : log lines to show}
        {--json : JSON output}';

    protected $description = 'Scheduled jobs that run as the vhost identity (or root, with confirmation)';

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        try {
            return match ($action) {
                'list' => $this->list(),
                'add' => $this->add(),
                'del', 'delete', 'rm' => $this->simple('cron.job.del', 'Job removed.'),
                'enable' => $this->simple('cron.job.enable', 'Job enabled.'),
                'disable' => $this->simple('cron.job.disable', 'Job disabled.'),
                'run' => $this->run_(),
                'log' => $this->log(),
                default => $this->usage(),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function usage(): int
    {
        $this->error('Usage: azerioid cron list|add|del|enable|disable|run|log [id] '
            . '[--owner= --schedule= --command= --note= --confirm=RUN-AS-ROOT]');

        return self::INVALID;
    }

    private function list(): int
    {
        $data = $this->brokerData('cron.jobs', [], ['owner' => (string) ($this->option('owner') ?? '')], 30, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        foreach ((array) ($data['jobs'] ?? []) as $job) {
            $this->line(sprintf(
                '%-16s %-22s %-16s %s%s',
                (string) ($job['id'] ?? ''),
                (string) ($job['runs_as'] ?? ''),
                (string) ($job['schedule'] ?? ''),
                ($job['enabled'] ?? true) ? '' : '[disabled] ',
                (string) ($job['command'] ?? '')
            ));
        }
        $unmanaged = (array) ($data['unmanaged_root_lines'] ?? []);
        if ($unmanaged !== []) {
            $this->line('');
            $this->line('Root crontab lines the panel does not manage (left untouched):');
            foreach ($unmanaged as $line) {
                $this->line('  ' . (string) $line);
            }
        }

        return self::SUCCESS;
    }

    private function add(): int
    {
        $res = $this->brokerCall('cron.job.add', [], [
            'owner' => (string) ($this->option('owner') ?? ''),
            'schedule' => (string) ($this->option('schedule') ?? ''),
            'command' => (string) ($this->option('command') ?? ''),
            'note' => (string) ($this->option('note') ?? ''),
            'confirm' => (string) ($this->option('confirm') ?? ''),
        ], 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }
        $job = (array) ($res->data['job'] ?? []);
        $this->info('Added ' . (string) ($job['id'] ?? '') . '; runs as ' . (string) ($job['runs_as'] ?? '') . '.');

        return self::SUCCESS;
    }

    private function simple(string $action, string $message): int
    {
        $id = $this->id();
        if ($id === null) {
            return $this->usage();
        }
        $res = $this->brokerCall($action, [$id], ['confirm' => (string) ($this->option('confirm') ?? '')], 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }
        $this->info($message);

        return self::SUCCESS;
    }

    /** Named with a trailing underscore because `run` is Command's own. */
    private function run_(): int
    {
        $id = $this->id();
        if ($id === null) {
            return $this->usage();
        }
        $data = $this->brokerData('cron.job.run', [$id], [], 300);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $this->line('ran as: ' . (string) ($data['ran_as'] ?? ''));
        $output = trim((string) ($data['output'] ?? ''));
        if ($output !== '') {
            $this->line($output);
        }
        $code = (int) ($data['exit_code'] ?? 0);
        if ($code !== 0) {
            // The command's own exit code, so a script calling this can react to it.
            $this->error('Job exited ' . $code . '. Full output: ' . (string) ($data['log'] ?? ''));

            return self::FAILURE;
        }
        $this->info('Exited 0.');

        return self::SUCCESS;
    }

    private function log(): int
    {
        $id = $this->id();
        if ($id === null) {
            return $this->usage();
        }
        $data = $this->brokerData('cron.job.log', [$id], ['lines' => (int) $this->option('lines')], 30, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        if ($data['missing'] ?? false) {
            $this->line('No output recorded yet: ' . (string) ($data['path'] ?? ''));

            return self::SUCCESS;
        }
        foreach ((array) ($data['lines'] ?? []) as $line) {
            $this->line((string) $line);
        }

        return self::SUCCESS;
    }

    private function id(): ?string
    {
        $id = trim((string) ($this->argument('id') ?? ''));

        return $id === '' ? null : $id;
    }
}
