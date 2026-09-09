<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

class PanelCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:panel
        {action : domain}
        {op? : show|set|clear}
        {--domain= : Panel hostname (set)}
        {--tls= : auto|internal|dns01}
        {--tls-mode= : Alias of --tls=}
        {--dns-provider= : cloudflare|digitalocean (dns01)}
        {--staging : Let\'s Encrypt staging}
        {--json : JSON output}';

    protected $description = 'Panel access: custom domain (white-label) plus IP/tunnel fallback';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));
        if ($action !== 'domain') {
            $this->error('Unknown panel action. Use: azerioid panel domain show|set|clear');

            return self::INVALID;
        }
        $op = strtolower((string) ($this->argument('op') ?: 'show'));

        return match ($op) {
            'show' => $this->show(),
            'set' => $this->set(),
            'clear' => $this->clear(),
            default => $this->badOp(),
        };
    }

    private function badOp(): int
    {
        $this->error('Use: azerioid panel domain show|set|clear');

        return self::INVALID;
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
