<?php

namespace App\Console\Commands\Azerioid;

use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

class ProcessCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:process
        {action : list|create|start|stop|restart|del|logs|identity}
        {name? : Program name; with identity: status|apply}
        {--command= : Process command (create)}
        {--vhost= : Tie process to a vhost domain}
        {--freeform : Create as freeform (azerioid-supervised home)}
        {--directory= : Working directory}
        {--name= : Explicit program name on create}
        {--follow : Follow logs}
        {--lines=100 : Log lines}
        {--domain= : identity apply: only this site}
        {--confirm : Required for identity apply}
        {--json : JSON output (list, identity)}';

    protected $description = 'Manage Supervisor programs via broker supervisor.program.* actions';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listProcesses(),
            'create' => $this->createProcess(),
            'start', 'stop', 'restart' => $this->controlProcess((string) $this->argument('action')),
            'del', 'delete', 'rm' => $this->deleteProcess(),
            'logs' => $this->logsProcess(),
            'identity' => $this->identity(),
            default => $this->invalidAction(),
        };
    }

    /**
     * ADR A56: every site-bound program runs as its own site. The scheduler moves existing ones;
     * this is the status check and the operator retry for programs put back.
     */
    private function identity(): int
    {
        $op = strtolower((string) ($this->argument('name') ?: 'status'));
        try {
            if ($op === 'apply') {
                if (! $this->option('confirm')) {
                    $this->error('Refusing to move programs without --confirm (each moved program restarts as its site).');

                    return self::INVALID;
                }
                $domain = trim((string) $this->option('domain'));
                $data = $this->brokerData('program.identity.apply', [], array_filter([
                    'confirm' => Validator::ISOLATE_PROGRAMS_CONFIRM,
                    'domain' => $domain !== '' ? $domain : null,
                ]), 3600);
                if ($this->wantsJson()) {
                    return $this->emitData($data);
                }
                foreach ((array) ($data['programs'] ?? []) as $name => $row) {
                    $this->line('  '.$name.'  '.(string) ($row['result'] ?? '?').'  '.(string) ($row['before'] ?? '').' → '.(string) ($row['after'] ?? '')
                        .(isset($row['reason']) ? '  — '.(string) $row['reason'] : ''));
                }
                $ok = ($data['result'] ?? '') === 'ok';
                $ok ? $this->info('Done.') : $this->warn('Some programs were put back on the shared account; see above.');

                return $ok ? self::SUCCESS : self::FAILURE;
            }
            if (! in_array($op, ['status', 'check'], true)) {
                $this->error('Unknown identity op. Use: azerioid process identity status|apply');

                return self::INVALID;
            }
            $data = $this->brokerData('program.identity.status', [], [], 120, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        $migrated = ($data['migrated'] ?? false) === true;
        if ($this->wantsJson()) {
            $this->emitData($data);

            return $migrated ? self::SUCCESS : self::FAILURE;
        }
        foreach ((array) ($data['programs'] ?? []) as $row) {
            $this->line('  '.(string) ($row['name'] ?? '?').'  '.(string) ($row['domain'] ?? '').'  '.(string) ($row['state'] ?? '').'  as '.(string) ($row['user'] ?? '')
                .(isset($row['reason']) && $row['reason'] !== null ? '  — '.(string) $row['reason'] : ''));
        }
        $migrated ? $this->info((string) ($data['verdict'] ?? 'OK')) : $this->warn((string) ($data['verdict'] ?? 'PENDING'));

        return $migrated ? self::SUCCESS : self::FAILURE;
    }

    private function listProcesses(): int
    {
        try {
            $data = $this->brokerData('supervisor.program.list', [], [], null, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $programs = $data['programs'] ?? [];
        if ($this->wantsJson()) {
            return $this->emitData(['programs' => $programs]);
        }

        $rows = [];
        foreach ($programs as $p) {
            $rows[] = [
                (string) ($p['name'] ?? ''),
                (string) ($p['state'] ?? ''),
                (string) ($p['user'] ?? ''),
                (string) ($p['vhost_domain'] ?? ''),
                (string) ($p['command'] ?? ''),
            ];
        }

        return $this->emitTable(['name', 'state', 'user', 'vhost', 'command'], $rows);
    }

    private function createProcess(): int
    {
        try {
            $command = trim((string) $this->option('command'));
            if ($command === '') {
                throw new \RuntimeException('--command= is required.');
            }
            $vhost = trim((string) $this->option('vhost'));
            $freeform = (bool) $this->option('freeform');
            if ($vhost === '' && ! $freeform) {
                throw new \RuntimeException('Pass --vhost=<domain> or --freeform.');
            }
            if ($vhost !== '' && $freeform) {
                throw new \RuntimeException('Use either --vhost or --freeform, not both.');
            }

            $www = rtrim((string) config('azerioid.www_root', '/data/www'), '/');
            $nameOpt = trim((string) $this->option('name'));
            $directory = trim((string) $this->option('directory'));

            if ($vhost !== '') {
                $domain = Validator::domain($vhost);
                $name = $nameOpt !== '' ? $nameOpt : preg_replace('/[^a-z0-9]+/', '-', strtolower($domain));
                $directory = $directory !== '' ? $directory : ($www . '/' . $domain);
                $payload = [
                    'name' => Validator::supervisorProgramName((string) $name),
                    'command' => Validator::supervisorCommand($command),
                    'directory' => $directory,
                    'autostart' => true,
                    'autorestart' => true,
                    'vhost_domain' => $domain,
                ];
            } else {
                $name = $nameOpt !== '' ? $nameOpt : 'app-' . substr(bin2hex(random_bytes(4)), 0, 8);
                $directory = $directory !== '' ? $directory : SupervisedUser::APPS_DIR;
                $payload = [
                    'name' => Validator::supervisorProgramName((string) $name),
                    'command' => Validator::supervisorCommand($command),
                    'directory' => $directory,
                    'autostart' => true,
                    'autorestart' => true,
                ];
            }

            // Broker validates directory against www_root / supervised home — do not bypass.
            $res = $this->brokerCall('supervisor.program.create', [], $payload);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $this->info('Created process ' . $payload['name'] . '.');
            if (is_array($res->data)) {
                $user = $res->data['user'] ?? ($res->data['program']['user'] ?? null);
                if ($user) {
                    $this->line('  runs as user: ' . $user);
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function controlProcess(string $action): int
    {
        try {
            $name = Validator::supervisorProgramName((string) ($this->argument('name') ?: $this->option('name')));
            $res = $this->brokerCall('supervisor.program.' . $action, [$name]);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $this->info(ucfirst($action) . " issued for {$name}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function deleteProcess(): int
    {
        try {
            $name = Validator::supervisorProgramName((string) ($this->argument('name') ?: $this->option('name')));
            $res = $this->brokerCall('supervisor.program.delete', [$name], ['stop_first' => true]);
            if (! $res->ok) {
                $this->throwBrokerFailure($res);
            }
            $this->info("Deleted process {$name}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function logsProcess(): int
    {
        try {
            $name = Validator::supervisorProgramName((string) ($this->argument('name') ?: $this->option('name')));
            $lines = max(1, (int) $this->option('lines'));
            $follow = (bool) $this->option('follow');

            do {
                $data = $this->brokerData('supervisor.program.logs', [$name], ['lines' => $lines], null, false);
                if ($this->wantsJson() && ! $follow) {
                    return $this->emitData($data);
                }
                $this->line('--- stdout ---');
                $this->line((string) ($data['stdout'] ?? ''));
                $this->line('--- stderr ---');
                $this->line((string) ($data['stderr'] ?? ''));
                if (! $follow) {
                    return self::SUCCESS;
                }
                sleep(2);
            } while ($follow);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function invalidAction(): int
    {
        $this->error('Unknown process action. Use: list|create|start|stop|restart|del|logs');

        return self::INVALID;
    }
}
