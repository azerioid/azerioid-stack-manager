<?php

namespace App\Console\Commands\Azerioid;

use App\Jobs\RunPanelUpdateJob;
use App\Models\PanelUpdateOperation;
use AzerioidPanel\Broker\Panel\PanelUpdater;
use Illuminate\Console\Command;

class PanelCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:panel
        {action : domain|update}
        {op? : show|set|clear|check|apply}
        {--domain= : Panel hostname (set)}
        {--tls= : auto|internal|dns01}
        {--tls-mode= : Alias of --tls=}
        {--dns-provider= : cloudflare|digitalocean (dns01)}
        {--staging : Let\'s Encrypt staging}
        {--v= : Target release tag (e.g. v0.2.2); omit to use latest semver tag}
        {--confirm : Required for panel update apply}
        {--json : JSON output}';

    protected $description = 'Panel access (custom domain) and panel self-update (origin/main)';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'domain' => $this->handleDomain(),
            'update' => $this->handleUpdate(),
            default => $this->badAction(),
        };
    }

    private function badAction(): int
    {
        $this->error('Unknown panel action. Use: azerioid panel domain … | azerioid panel update check|apply');

        return self::INVALID;
    }

    private function handleDomain(): int
    {
        $op = strtolower((string) ($this->argument('op') ?: 'show'));

        return match ($op) {
            'show' => $this->show(),
            'set' => $this->set(),
            'clear' => $this->clear(),
            default => $this->badDomainOp(),
        };
    }

    private function handleUpdate(): int
    {
        $op = strtolower((string) ($this->argument('op') ?: 'check'));

        return match ($op) {
            'check', 'status' => $this->updateCheck(),
            'apply' => $this->updateApply(),
            default => $this->badUpdateOp(),
        };
    }

    private function badDomainOp(): int
    {
        $this->error('Use: azerioid panel domain show|set|clear');

        return self::INVALID;
    }

    private function badUpdateOp(): int
    {
        $this->error('Use: azerioid panel update check|apply [--v=<tag>] [--confirm] [--json]');

        return self::INVALID;
    }

    private function updateCheck(): int
    {
        try {
            $data = $this->brokerData('panel.update.check', [], [], 120, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->line('Channel: semver tags');
        $depTag = $data['deployed_tag'] ?? null;
        $this->line(
            'Deployed: '.($depTag ?: 'untagged')
            .' · '.($data['deployed_commit_short'] ?? '—')
            .(isset($data['version']) && $data['version'] !== '' ? ' (VERSION '.$data['version'].')' : '')
        );
        $this->line('Latest:   '.($data['latest_tag'] ?? '—').' · '.($data['latest_commit_short'] ?? '—'));
        if (! empty($data['tags']) && is_array($data['tags'])) {
            $shown = array_slice($data['tags'], 0, 12);
            $this->line('Tags:     '.implode(', ', $shown).( ! empty($data['tags_truncated']) ? ' …' : ''));
        }
        if (! empty($data['dirty'])) {
            $this->warn('Source working tree is dirty — apply will be refused.');
            foreach (array_slice($data['dirty_entries'] ?? [], 0, 12) as $line) {
                $this->line('  '.$line);
            }
        } elseif (! empty($data['up_to_date'])) {
            $this->info('Up to date.');
        } elseif (! empty($data['update_available'])) {
            $this->warn('Update available → '.($data['latest_tag'] ?? ''));
            foreach ($data['log_summary'] ?? [] as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }

    private function updateApply(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing panel update without --confirm (maps to '.PanelUpdater::CONFIRM.').');

            return self::INVALID;
        }

        if (PanelUpdateOperation::query()->whereIn('status', ['queued', 'running'])->exists()) {
            $this->error('A panel update is already queued or running.');

            return self::FAILURE;
        }

        try {
            $check = $this->brokerData('panel.update.check', [], [], 120, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if (! empty($check['dirty'])) {
            $this->error('Refusing panel update: source working tree is dirty.');

            return self::FAILURE;
        }

        $explicitTag = trim((string) ($this->option('v') ?: ''));
        $targetTag = $explicitTag;
        if ($targetTag === '' && empty($check['update_available'])) {
            $this->info('Already up to date — nothing to apply.');

            return self::SUCCESS;
        }
        if ($targetTag === '') {
            $targetTag = (string) ($check['latest_tag'] ?? '');
        }
        $operation = PanelUpdateOperation::query()->create([
            'user_id' => null,
            'status' => 'queued',
            'from_commit' => $check['deployed_commit'] ?? null,
            'to_commit' => $check['latest_commit'] ?? ($check['remote_commit'] ?? null),
            // Only record an explicit --v= as target_tag for the job; otherwise let the broker
            // resolve "latest" so untagged ahead-of-tag tips are refused there too.
            'target_tag' => $explicitTag !== '' ? $explicitTag : null,
            'from_tag' => $check['deployed_tag'] ?? null,
            'to_tag' => $targetTag !== '' ? $targetTag : null,
        ]);
        RunPanelUpdateJob::dispatch($operation->id);
        $this->info('Queued panel self-update job #'.$operation->id.($targetTag !== '' ? ' → '.$targetTag : '').'.');
        $this->line('Poll with: azerioid panel update check');
        $this->line('Broker log key: panel-up-'.$operation->id);

        // Best-effort wait/poll for CLI operators who expect progress.
        $deadline = time() + 900;
        while (time() < $deadline) {
            sleep(2);
            $row = PanelUpdateOperation::query()->find($operation->id);
            if ($row === null) {
                break;
            }
            if ($row->isActive()) {
                try {
                    $log = $this->brokerData('panel.update.operation.log', ['panel-up-'.$operation->id], [], 15, false);
                    $lines = $log['lines'] ?? [];
                    if (is_array($lines) && $lines !== []) {
                        $this->line(end($lines));
                        $row->update(['log' => implode("\n", $lines)]);
                    }
                } catch (\Throwable) {
                }
                continue;
            }
            if ($row->status === 'completed') {
                $this->info('Panel update completed → '.($row->to_tag ?: substr((string) ($row->to_commit ?? ''), 0, 7)));

                return self::SUCCESS;
            }
            $this->error($row->error ?: 'Panel update failed.');
            if ($row->rolled_back) {
                $this->warn('Rolled back to previous commit.');
            }

            return self::FAILURE;
        }

        $this->warn('Timed out waiting for job; it may still be running. Check the Updates page or queue logs.');

        return self::FAILURE;
    }

    private function show(): int
    {
        try {
            $data = $this->brokerData('panel.domain.show');
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $domain = $data['domain'] ?? null;
        $this->line($domain ? "Panel domain: {$domain}" : 'Panel domain: not set (IP/tunnel)');
        $this->line('APP_URL: '.($data['app_url'] ?? '—'));
        $this->line('TLS: '.($data['tls_mode'] ?? '—'));
        $urls = $data['fallback_urls'] ?? [];
        if (is_array($urls) && $urls !== []) {
            $this->line('Fallback: '.implode(', ', $urls));
        }
        $ts = $data['tls_status'] ?? null;
        if (is_array($ts)) {
            $this->line('Certificate: '.($ts['issuer_type'] ?? $ts['label'] ?? 'pending'));
        }

        return self::SUCCESS;
    }

    private function set(): int
    {
        $domain = trim((string) ($this->option('domain') ?: ''));
        if ($domain === '') {
            $this->error('Provide --domain=<hostname>.');

            return self::INVALID;
        }
        $tls = $this->option('tls-mode') ?: $this->option('tls') ?: 'auto';
        $stdin = [
            'domain' => $domain,
            'tls_mode' => $tls,
        ];
        if ($this->option('dns-provider')) {
            $stdin['dns_provider'] = (string) $this->option('dns-provider');
        }
        if ($this->option('staging')) {
            $stdin['staging'] = true;
        }
        try {
            $data = $this->brokerData('panel.domain.set', [], $stdin, 120);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $this->info('Panel domain set to '.($data['domain'] ?? $domain).'. IP/tunnel fallback unchanged.');

        return self::SUCCESS;
    }

    private function clear(): int
    {
        try {
            $data = $this->brokerData('panel.domain.set', [], ['clear' => true], 120);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }
        $this->info('Panel domain cleared. Access via IP/tunnel fallback.');

        return self::SUCCESS;
    }
}
