<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

/**
 * Git deploy (B8, ADR A41/A53).
 */
class DeployCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:deploy
        {action : config|set|run|rollback|key|remove|list}
        {domain? : site domain}
        {--repository= : git@host:owner/repo.git, ssh://… or a public https:// URL (set)}
        {--branch=main : branch to deploy (set)}
        {--preset=none : none|composer|laravel|npm|custom (set)}
        {--command= : custom post-deploy command, runs as the site (set --preset=custom)}
        {--confirm= : RUN-AS-SITE for a custom command (set)}
        {--schedule=off : off|hourly|daily@<hour> (set)}
        {--json : JSON output}';

    protected $description = 'Git deploy per site: fetch with a root-only key, check out and run as the site, reload';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));
        try {
            if ($action === 'list') {
                return $this->out($this->brokerData('deploy.list', [], [], 60, false));
            }
            $domain = (string) $this->argument('domain');
            $data = match ($action) {
                'config' => $this->brokerData('deploy.config', [$domain], [], 30, false),
                'set' => $this->brokerData('deploy.config.set', [$domain], [
                    'repository' => (string) $this->option('repository'),
                    'branch' => (string) $this->option('branch'),
                    'preset' => (string) $this->option('preset'),
                    'command' => (string) $this->option('command'),
                    'confirm' => (string) $this->option('confirm'),
                    'schedule' => (string) $this->option('schedule'),
                ], 60),
                'run' => $this->brokerData('deploy.run', [$domain], ['trigger' => 'manual'], 1800),
                'rollback' => $this->brokerData('deploy.rollback', [$domain], [], 1800),
                'key' => $this->brokerData('deploy.key.rotate', [$domain], [], 60),
                'remove' => $this->brokerData('deploy.remove', [$domain], [], 60),
                default => throw new \InvalidArgumentException('Use: azerioid deploy config|set|run|rollback|key|remove|list'),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        return $this->out($data);
    }

    /** @param array<string,mixed> $data */
    private function out(array $data): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if (isset($data['sites'])) {
            foreach ($data['sites'] as $s) {
                $this->line($s['domain'].'  schedule '.$s['schedule'].'  at '.substr((string) ($s['current'] ?? '-'), 0, 12));
            }

            return self::SUCCESS;
        }
        if (isset($data['deployed'])) {
            foreach ((array) ($data['log'] ?? []) as $line) {
                $this->line((string) $line);
            }
            $this->info($data['deployed'] ? 'Deployed '.substr((string) $data['commit'], 0, 12).'.' : 'Nothing to do: '.($data['reason'] ?? ''));

            return self::SUCCESS;
        }
        foreach (['repository', 'branch', 'preset', 'command', 'schedule'] as $k) {
            if (($data[$k] ?? null) !== null) {
                $this->line(str_pad($k, 11).': '.$data[$k]);
            }
        }
        if (($data['public_key'] ?? null) !== null) {
            $this->line('deploy key : '.$data['public_key']);
        }
        if (isset($data['state']['current'])) {
            $this->line('deployed   : '.substr((string) $data['state']['current'], 0, 12));
        }

        return self::SUCCESS;
    }
}
