<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

class UpdatesCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:updates
        {action : check|apply}
        {--security : Apply security updates only (apply)}
        {--confirm : Required for apply (maps to APPLY-SECURITY / APPLY-ALL)}
        {--json : JSON output}';

    protected $description = 'OS package updates via broker updates.* (not panel self-update)';

    public function handle(): int
    {
        return match (strtolower((string) $this->argument('action'))) {
            'check', 'list', 'status' => $this->check(),
            'apply' => $this->apply(),
            default => $this->badAction(),
        };
    }

    private function badAction(): int
    {
        $this->error('Usage: azerioid updates check|apply [--security] [--confirm] [--json]');

        return self::INVALID;
    }

    private function check(): int
    {
        try {
            $data = $this->brokerData('updates.list', [], [], null, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->line('OS package updates (not panel self-update)');
        $this->line('Package manager: '.($data['pkg_mgr'] ?? '—').' · source: '.($data['source'] ?? '—').' · distro: '.($data['distro'] ?? '—'));
        $this->line('Pending: '.(string) ($data['total'] ?? 0));
        $this->line('Security: '.(string) ($data['security'] ?? 0));
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : [];
        if ($packages !== []) {
            $rows = [];
            foreach (array_slice($packages, 0, 50) as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $rows[] = [
                    (string) ($p['name'] ?? ''),
                    ! empty($p['security']) ? 'security' : 'updates',
                ];
            }
            $this->table(['package', 'kind'], $rows);
            if (count($packages) > 50) {
                $this->line('… '.(count($packages) - 50).' more (use --json for full list)');
            }
        }

        return self::SUCCESS;
    }

    private function apply(): int
    {
        if (! $this->option('confirm')) {
            $this->error(
                $this->option('security')
                    ? 'Refusing to apply OS security updates without --confirm (broker confirm APPLY-SECURITY).'
                    : 'Refusing to apply OS package updates without --confirm (broker confirm APPLY-ALL).'
            );

            return self::INVALID;
        }

        $security = (bool) $this->option('security');
        $action = $security ? 'updates.apply.security' : 'updates.apply.all';
        $stdin = [
            'confirm' => $security ? 'APPLY-SECURITY' : 'APPLY-ALL',
        ];

        try {
            $data = $this->brokerData($action, [], $stdin, 900);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->info($security ? 'Security OS updates applied.' : 'All pending OS package updates applied.');
        $out = trim((string) ($data['output'] ?? ''));
        if ($out !== '') {
            $this->line($out);
        }

        return self::SUCCESS;
    }
}
