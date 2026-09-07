<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

class VersionCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:version {--json : Machine-readable JSON output}';

    protected $description = 'Show panel and installed stack component versions';

    public function handle(): int
    {
        try {
            $versions = $this->brokerData('version.all', [], [], null, false);
            $runtime = $this->brokerData('panel.runtime', [], [], null, false);
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }

        $panelVersion = $this->panelVersion();
        $payload = [
            'panel' => $panelVersion,
            'panel_runtime' => $runtime,
            'stack' => $versions,
        ];

        if ($this->wantsJson()) {
            return $this->emitData($payload);
        }

        $this->line('AZERIOID Stack Manager ' . $panelVersion);
        $this->line('  web:    ' . ($versions['web']['version'] ?? $versions['caddy']['version'] ?? '?'));
        $this->line('  php:    ' . ($versions['php']['version'] ?? ($runtime['php_version'] ?? '?')));
        $installed = $versions['php']['installed'] ?? [];
        if (is_array($installed) && $installed !== []) {
            $this->line('  php*:   ' . implode(', ', $installed));
        }
        $this->line('  mariadb:' . ($versions['mariadb']['version'] ?? 'not installed'));

        return self::SUCCESS;
    }

    private function panelVersion(): string
    {
        $candidates = [
            base_path('../VERSION'),
            '/usr/local/lib/azerioid-panel/VERSION',
            base_path('VERSION'),
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                $v = trim((string) file_get_contents($path));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return 'dev';
    }
}
