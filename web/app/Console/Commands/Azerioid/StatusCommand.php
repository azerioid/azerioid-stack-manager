<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

class StatusCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:status {--json : Machine-readable JSON output}';

    protected $description = 'Show panel services and component overview (broker status.all + component.list)';

    public function handle(): int
    {
        try {
            $status = $this->brokerData('status.all', [], [], null, false);
            $components = $this->brokerData('component.list', [], [], null, false);
            $runtime = $this->brokerData('panel.runtime', [], [], null, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $payload = [
            'panel_runtime' => $runtime,
            'controlled' => $status['controlled'] ?? [],
            'observed' => $status['observed'] ?? [],
            'warnings' => $status['warnings'] ?? [],
            'components' => $components['components'] ?? $components,
        ];

        if ($this->wantsJson()) {
            return $this->emitData($payload);
        }

        $this->info('Panel runtime');
        $this->line(sprintf(
            '  php=%s  fpm=%s  queue=%s',
            $runtime['php_version'] ?? '?',
            $runtime['fpm_socket'] ?? '?',
            $runtime['queue_unit'] ?? '?'
        ));

        $rows = [];
        foreach ($payload['controlled'] as $svc) {
            $rows[] = [
                (string) ($svc['unit'] ?? $svc['id'] ?? ''),
                (string) ($svc['active_state'] ?? ''),
                (string) ($svc['sub_state'] ?? ''),
                'controlled',
            ];
        }
        foreach ($payload['observed'] as $svc) {
            $rows[] = [
                (string) ($svc['unit'] ?? $svc['bind_hint'] ?? ''),
                (string) ($svc['active_state'] ?? ($svc['running'] ?? false ? 'active' : 'inactive')),
                '',
                'observed',
            ];
        }
        $this->newLine();
        $this->info('Services');
        $this->table(['unit', 'state', 'sub', 'kind'], $rows);

        $compRows = [];
        foreach ((array) $payload['components'] as $c) {
            if (! is_array($c)) {
                continue;
            }
            $compRows[] = [
                (string) ($c['id'] ?? ''),
                (string) ($c['display_name'] ?? ''),
                (string) ($c['status'] ?? ''),
                ! empty($c['system']) ? 'system' : (! empty($c['managed']) || ($c['kind'] ?? '') === 'managed' ? 'managed' : 'other'),
            ];
        }
        $this->newLine();
        $this->info('Components');
        $this->table(['id', 'name', 'status', 'kind'], $compRows);

        $warnings = $payload['warnings'] ?? [];
        if (is_array($warnings) && $warnings !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($warnings as $w) {
                $this->line('  - ' . (is_string($w) ? $w : json_encode($w)));
            }
        }

        return self::SUCCESS;
    }
}
