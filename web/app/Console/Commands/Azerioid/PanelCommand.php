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
        {action : domain|update|harden|default-site}
        {op? : show|set|clear|check|apply|status}
        {--domain= : Panel hostname (set)}
        {--tls= : auto|internal|dns01}
        {--tls-mode= : Alias of --tls=}
        {--dns-provider= : cloudflare|digitalocean (dns01)}
        {--staging : Let\'s Encrypt staging}
        {--v= : Target release tag (e.g. v0.2.2); omit to use latest semver tag}
        {--confirm : Required for panel update apply / harden apply}
        {--lockdown-site-pools : (harden apply) also disable process spawning in SITE FPM pools}
        {--dry-run : (harden apply) show the plan without changing anything}
        {--mode= : (default-site set) page|404|421}
        {--json : JSON output}';

    protected $description = 'Panel access (custom domain), panel self-update, and R1 identity hardening';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'domain' => $this->handleDomain(),
            'update' => $this->handleUpdate(),
            'harden' => $this->handleHarden(),
            'default-site', 'defaultsite' => $this->handleDefaultSite(),
            default => $this->badAction(),
        };
    }

    private function badAction(): int
    {
        $this->error('Unknown panel action. Use: azerioid panel domain … | azerioid panel update check|apply | azerioid panel harden status|apply | azerioid panel default-site show|set|clear');

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

    /**
     * Catch-all for hostnames no vhost claims (B1 / request #14).
     *
     * Without it, an unknown Host on :80 gets a 308 to HTTPS and the HTTPS
     * connection then fails the TLS handshake, because no site block matches the
     * SNI — so a visitor whose DNS points here sees a broken connection instead of
     * an answer.
     */
    private function handleDefaultSite(): int
    {
        $op = strtolower((string) ($this->argument('op') ?: 'show'));

        return match ($op) {
            'show', 'status' => $this->defaultSiteShow(),
            'set', 'enable' => $this->defaultSiteSet(),
            'clear', 'disable' => $this->defaultSiteClear(),
            default => $this->badDefaultSiteOp(),
        };
    }

    private function badDefaultSiteOp(): int
    {
        $this->error('Usage: azerioid panel default-site show|set --mode=page|404|421|clear');

        return self::INVALID;
    }

    private function defaultSiteShow(): int
    {
        try {
            $data = $this->brokerData('panel.default-site.show', [], [], 60, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if (($data['enabled'] ?? false) !== true) {
            $this->warn('No default site. Hostnames that match no vhost currently fail the TLS handshake.');
            $this->line('Enable with: azerioid panel default-site set --mode=page');

            return self::SUCCESS;
        }
        $this->info('Default site enabled (mode: ' . (string) ($data['mode'] ?? '?') . ')');
        $this->line('snippet: ' . (string) ($data['snippet'] ?? ''));
        $this->line('root   : ' . (string) ($data['root'] ?? ''));

        return self::SUCCESS;
    }

    private function defaultSiteSet(): int
    {
        $mode = strtolower(trim((string) ($this->option('mode') ?: 'page')));
        try {
            $data = $this->brokerData('panel.default-site.set', [$mode], [], 120);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info('Default site set to ' . (string) ($data['mode'] ?? $mode) . '.');
        $this->line('Named vhosts are unaffected — Caddy matches them ahead of this block.');

        return self::SUCCESS;
    }

    private function defaultSiteClear(): int
    {
        try {
            $this->brokerData('panel.default-site.clear', [], [], 120);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
        $this->info('Default site removed.');
        $this->warn('Hostnames matching no vhost will fail the TLS handshake again.');

        return self::SUCCESS;
    }

    private function handleHarden(): int
    {
        $op = strtolower((string) ($this->argument('op') ?: 'status'));

        return match ($op) {
            'status', 'check' => $this->hardenStatus(),
            'apply' => $this->hardenApply(),
            default => $this->badHardenOp(),
        };
    }

    private function badHardenOp(): int
    {
        $this->error('Unknown harden op. Use: azerioid panel harden status|apply');

        return self::INVALID;
    }

    private function hardenStatus(): int
    {
        try {
            $data = $this->brokerData('panel.harden.status', [], [], 60, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $vulnerable = (bool) ($data['vulnerable'] ?? false);
        $this->line('Panel pool user   : ' . (string) ($data['panel_pool_user'] ?? '(unknown)'));
        $this->line('Panel pool config : ' . (string) ($data['panel_pool_path'] ?? '(not found)'));
        $this->line('Sudoers grants to : ' . implode(', ', (array) ($data['sudoers_users'] ?? [])));
        $this->line('Site pool users   : ' . implode(', ', (array) ($data['site_pool_users'] ?? [])));
        $this->line('Target identity   : ' . (string) ($data['target_user'] ?? '(none)'));
        $this->newLine();
        if ($vulnerable) {
            $this->error((string) ($data['verdict'] ?? 'VULNERABLE'));
            $this->warn('Remediate with: azerioid panel harden apply --confirm');
        } else {
            $this->info((string) ($data['verdict'] ?? 'OK'));
        }
        $this->newLine();
        $this->line('Residual risk: ' . (string) ($data['residual_risk'] ?? ''));

        // Non-zero when the host is vulnerable, so this is usable as a CI/fleet gate.
        return $vulnerable ? self::FAILURE : self::SUCCESS;
    }

    private function hardenApply(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $this->option('confirm')) {
            $this->error('Refusing to harden without --confirm (this changes the panel service identity).');

            return self::INVALID;
        }

        try {
            $data = $this->brokerData('panel.harden.apply', [], [
                'confirm' => $dryRun ? 'HARDEN-PANEL' : 'HARDEN-PANEL',
                'lockdown_site_pools' => (bool) $this->option('lockdown-site-pools'),
                'dry_run' => $dryRun,
            ], 900);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Dry run — nothing changed.');
            $this->line('Would migrate: ' . (string) ($data['would_migrate_from'] ?? '?') . ' → ' . (string) ($data['would_migrate_to'] ?? '?'));
            foreach ((array) ($data['plan'] ?? []) as $i => $step) {
                $this->line('  ' . ($i + 1) . '. ' . (string) $step);
            }

            return self::SUCCESS;
        }

        if (($data['already_hardened'] ?? false) === true) {
            $this->info('Already hardened — panel runs as ' . (string) ($data['panel_user'] ?? '?') . '.');

            return self::SUCCESS;
        }

        $this->info('Panel identity migrated: ' . (string) ($data['previous_user'] ?? '?') . ' → ' . (string) ($data['panel_user'] ?? '?'));
        foreach ((array) ($data['log'] ?? []) as $line) {
            $this->line('  · ' . (string) $line);
        }
        $this->newLine();
        $this->line('Residual risk: ' . (string) ($data['residual_risk'] ?? ''));

        return self::SUCCESS;
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
