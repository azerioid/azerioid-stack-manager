<?php

namespace App\Console\Commands\Azerioid;

use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Validator;
use Illuminate\Console\Command;

class ProcessCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:process
        {action : list|create|start|stop|restart|del|logs}
        {name? : Program name}
        {--command= : Process command (create)}
        {--vhost= : Tie process to a vhost domain}
        {--freeform : Create as freeform (azerioid-supervised home)}
        {--directory= : Working directory}
        {--name= : Explicit program name on create}
        {--follow : Follow logs}
        {--lines=100 : Log lines}
        {--json : JSON output (list)}';

    protected $description = 'Manage Supervisor programs via broker supervisor.program.* actions';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listProcesses(),
            'create' => $this->createProcess(),
            'start', 'stop', 'restart' => $this->controlProcess((string) $this->argument('action')),
            'del', 'delete', 'rm' => $this->deleteProcess(),
            'logs' => $this->logsProcess(),
            default => $this->invalidAction(),
        };
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
                throw new \RuntimeException((string) $res->error);
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
                throw new \RuntimeException((string) $res->error);
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
                throw new \RuntimeException((string) $res->error);
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
