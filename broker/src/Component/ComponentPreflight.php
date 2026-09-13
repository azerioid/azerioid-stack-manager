<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

final class ComponentPreflight
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
        private readonly OsRelease $os,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function check(array $definition): array
    {
        $id = (string) $definition['id'];
        $preflight = is_array($definition['preflight'] ?? null) ? $definition['preflight'] : [];
        $minDiskGb = (float) ($preflight['min_disk_gb'] ?? 0);
        $minRamMb = (int) ($preflight['min_ram_mb'] ?? 0);
        $minOs = is_array($definition['min_os'] ?? null) ? $definition['min_os'] : [];

        $issues = [];
        $disk = $this->diskAvailGb('/var');
        if ($minDiskGb > 0 && $disk < $minDiskGb) {
            $issues[] = "Need at least {$minDiskGb} GB free on /var (found {$disk} GB).";
        }
        $ramMb = $this->memAvailableMb();
        if ($minRamMb > 0 && $ramMb < $minRamMb) {
            $issues[] = "Need at least {$minRamMb} MB memory available including free swap (found {$ramMb} MB).";
        }
        $required = (string) ($minOs[$this->os->distroKey] ?? '');
        if ($required !== '' && version_compare($this->os->versionId, $required, '<')) {
            $issues[] = "Requires {$this->os->distroKey} {$required}+ (this host: {$this->os->versionId}).";
        }
        if (!is_array($definition['distros'][$this->os->distroKey] ?? null)) {
            $issues[] = 'Component is not supported on this OS.';
        }

        foreach ($this->conflicts($definition) as $conflictId) {
            if ((new PortOwnership($this->config, $this->runtime, $this->os))->conflictPresent($conflictId)) {
                $issues[] = $this->conflictMessage($id, $conflictId);
            }
        }

        return [
            'component_id' => $id,
            'ok' => $issues === [],
            'issues' => $issues,
            'remediations' => [],
            'disk_gb_var' => $disk,
            'ram_mb_available' => $ramMb,
            'distro_key' => $this->os->distroKey,
        ];
    }

    private function conflictMessage(string $componentId, string $conflictId): string
    {
        return "Conflicts with {$conflictId}, which is already present on this host.";
    }

    /** @param array<string, mixed> $definition @return list<string> */
    private function conflicts(array $definition): array
    {
        if (!is_array($definition['conflicts'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $definition['conflicts'])));
    }

    private function diskAvailGb(string $mount): float
    {
        $result = $this->runtime->exec(['/bin/df', '-B1', '-P', $mount]);
        if (!$result->ok()) {
            return 0.0;
        }
        foreach (explode("\n", trim($result->stdout)) as $i => $line) {
            if ($i === 0 || trim($line) === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (!is_array($parts) || count($parts) < 4) {
                continue;
            }
            return round(((int) $parts[3]) / 1_073_741_824, 2);
        }
        return 0.0;
    }

    /**
     * Effective install headroom: MemAvailable + SwapFree (kB → MB).
     * Small droplets (512 MB) are expected to install with swap; counting only
     * physical MemAvailable falsely fails every component install.
     */
    private function memAvailableMb(): int
    {
        if (!$this->runtime->fileExists('/proc/meminfo')) {
            return 0;
        }
        $memAvailable = 0;
        $swapFree = 0;
        foreach (explode("\n", $this->runtime->readFile('/proc/meminfo')) as $line) {
            if (str_starts_with($line, 'MemAvailable:')) {
                $memAvailable = (int) preg_replace('/\D/', '', $line);
            } elseif (str_starts_with($line, 'SwapFree:')) {
                $swapFree = (int) preg_replace('/\D/', '', $line);
            }
        }
        return (int) round(($memAvailable + $swapFree) / 1024);
    }
}
