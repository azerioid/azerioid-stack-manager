<?php

namespace App\Livewire;

use App\Services\Broker\BrokerClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Scheduled jobs (B2 / request #4).
 *
 * Replaces the root-crontab textarea on the Security page as the place to schedule
 * work. The difference the operator sees is that a job belongs to a site and runs as
 * that site; the difference they do not see is that saving no longer rewrites the whole
 * file, so two people working at once cannot erase each other's jobs.
 */
#[Layout('layouts.app')]
#[Title('Scheduled jobs · AZERIOID Stack Manager')]
class CronPage extends Component
{
    /** @var array<string,mixed> */
    public array $state = [];

    /** @var list<string> */
    public array $domains = [];

    public string $owner = '';

    public string $schedule = '*/5 * * * *';

    public string $command = '';

    public string $note = '';

    public string $confirm = '';

    public ?string $openLog = null;

    /** @var list<string> */
    public array $logLines = [];

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(BrokerClient $broker): void
    {
        $this->reload($broker);
    }

    public function reload(BrokerClient $broker): void
    {
        $res = $broker->call('cron.jobs', [], [], 30, false);
        $this->state = $res->ok && is_array($res->data) ? $res->data : [];
        if (! $res->ok && $this->error === null) {
            $this->error = $res->error;
        }

        $vhosts = $broker->call('vhost.list', [], [], 30, false);
        $this->domains = $vhosts->ok
            ? array_values(array_map(
                static fn (array $v): string => (string) ($v['domain'] ?? ''),
                array_filter((array) ($vhosts->data['vhosts'] ?? $vhosts->data ?? []), 'is_array')
            ))
            : [];
    }

    /** @return list<array<string,mixed>> */
    public function jobs(): array
    {
        $jobs = $this->state['jobs'] ?? [];

        return is_array($jobs) ? array_values($jobs) : [];
    }

    /** @return list<string> */
    public function unmanaged(): array
    {
        $lines = $this->state['unmanaged_root_lines'] ?? [];

        return is_array($lines) ? array_values($lines) : [];
    }

    public function add(BrokerClient $broker): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('cron.job.add', [], [
            'owner' => trim($this->owner),
            'schedule' => trim($this->schedule),
            'command' => trim($this->command),
            'note' => trim($this->note),
            'confirm' => trim($this->confirm),
        ], 60);
        if (! $res->ok) {
            $this->error = $res->error;
            $this->reload($broker);

            return;
        }
        $runsAs = (string) ($res->data['job']['runs_as'] ?? '');
        $this->flash = 'Job added; it will run as ' . $runsAs . '.';
        $this->command = '';
        $this->note = '';
        $this->confirm = '';
        $this->reload($broker);
    }

    public function toggle(BrokerClient $broker, string $id, bool $enable): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call(
            $enable ? 'cron.job.enable' : 'cron.job.disable',
            [$id],
            ['confirm' => trim($this->confirm)],
            60
        );
        $this->flash = $res->ok ? ($enable ? 'Job enabled.' : 'Job disabled.') : null;
        if (! $res->ok) {
            $this->error = $res->error;
        }
        $this->confirm = '';
        $this->reload($broker);
    }

    public function delete(BrokerClient $broker, string $id): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('cron.job.del', [$id], [], 60);
        $this->flash = $res->ok ? 'Job removed.' : null;
        if (! $res->ok) {
            $this->error = $res->error;
        }
        $this->reload($broker);
    }

    /**
     * Running a job now goes through the same wrapper and the same identity as the
     * schedule would, so "it works when I run it" and "it works at 3am" mean the same
     * thing.
     */
    public function runNow(BrokerClient $broker, string $id): void
    {
        $this->error = null;
        $this->flash = null;
        $res = $broker->call('cron.job.run', [$id], [], 300);
        if (! $res->ok) {
            $this->error = $res->error;

            return;
        }
        $code = (int) ($res->data['exit_code'] ?? 0);
        $this->flash = $code === 0
            ? 'Ran as ' . (string) ($res->data['ran_as'] ?? '') . ' and exited 0.'
            : null;
        if ($code !== 0) {
            $this->error = 'Exited ' . $code . ': ' . trim((string) ($res->data['output'] ?? ''));
        }
        $this->showLog($broker, $id);
    }

    public function showLog(BrokerClient $broker, string $id): void
    {
        if ($this->openLog === $id) {
            $this->openLog = null;
            $this->logLines = [];

            return;
        }
        $res = $broker->call('cron.job.log', [$id], ['lines' => 200], 30, false);
        $this->openLog = $id;
        $this->logLines = $res->ok ? array_values((array) ($res->data['lines'] ?? [])) : [];
    }

    public function render()
    {
        return view('livewire.cron')->layoutData([
            'heading' => 'Scheduled jobs',
            'sub' => 'per-site cron running as the site · output and exit codes',
        ]);
    }
}
