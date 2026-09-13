<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;

final class ComponentDetector
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly OsRelease $os,
        private readonly ManagedManifest $managed,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public function inspect(array $definition): array
    {
        $id = (string) $definition['id'];
        $distros = $definition['distros'] ?? [];
        $distroBlock = is_array($distros[$this->os->distroKey] ?? null)
            ? $distros[$this->os->distroKey]
            : null;

        $base = [
            'id' => $id,
            'display_name' => (string) ($definition['display_name'] ?? $id),
            'category' => (string) ($definition['category'] ?? 'other'),
            'description' => (string) ($definition['description'] ?? ''),
            'managed' => (bool) ($definition['managed'] ?? true),
            'system' => (bool) ($definition['system'] ?? false),
            'conflicts' => is_array($definition['conflicts'] ?? null) ? $definition['conflicts'] : [],
            'ports' => is_array($definition['ports'] ?? null) ? $definition['ports'] : [],
            'install_options' => is_array($definition['install_options'] ?? null) ? $definition['install_options'] : [],
            'unit_name' => is_string($distroBlock['unit_name'] ?? null) ? $distroBlock['unit_name'] : '',
            'removable' => !((bool) ($definition['system'] ?? false)),
            'installable' => (bool) ($definition['installable'] ?? false) && !((bool) ($definition['system'] ?? false)),
        ];

        if ($distroBlock === null) {
            return $base + [
                'kind' => ($definition['system'] ?? false) ? 'system' : 'managed',
                'status' => 'unsupported',
                'status_detail' => 'No registry data for this OS.',
                'adoptable' => false,
            ];
        }

        $detect = is_array($distroBlock['detect'] ?? null) ? $distroBlock['detect'] : [];
        $packages = is_array($detect['packages'] ?? null) ? array_map('strval', $detect['packages']) : [];
        $unit = trim((string) ($detect['unit'] ?? $distroBlock['unit_name'] ?? ''));
        $command = trim((string) ($detect['command'] ?? ''));
        $packageRegex = trim((string) ($detect['package_regex'] ?? ''));

        $packagesPresent = PackageQuery::anyInstalled($this->runtime, $packages, $this->os->pkgMgr);
        if (!$packagesPresent && $packageRegex !== '') {
            $packagesPresent = PackageQuery::anyNameMatching($this->runtime, $packageRegex, $this->os->pkgMgr);
        }
        $unitInfo = $unit !== '' ? Systemd::show($this->runtime, $unit) : null;
        $unitLoaded = $unit !== '' && $this->unitLoaded($unit);

        $status = 'not_installed';
        $statusDetail = 'Package not detected on this host.';
        if ($packagesPresent) {
            if ($unit !== '' && !$unitLoaded) {
                $status = 'broken';
                $statusDetail = 'Package is installed but the systemd unit is missing or not loaded.';
            } elseif ($unitInfo !== null && ($unitInfo['active_state'] ?? '') === 'failed') {
                $status = 'broken';
                $statusDetail = 'Service unit is in a failed state.';
            } else {
                $status = 'installed';
                $statusDetail = $unitInfo !== null
                    ? 'Unit '.$unit.' is '.($unitInfo['active_state'] ?? 'unknown').'.'
                    : 'Runtime package detected.';
            }
        } elseif ($command !== '' && $packages === [] && $packageRegex === '') {
            // Command probe only when no package criteria exist (avoids client-only false positives).
            $parts = preg_split('/\s+/', $command, 2);
            if (is_array($parts) && ($parts[0] ?? '') !== '') {
                $probe = $this->runtime->exec([$parts[0], ...array_slice($parts, 1)]);
                if ($probe->ok()) {
                    $status = 'installed';
                    $statusDetail = trim($probe->stdout) !== '' ? trim(explode("\n", $probe->stdout)[0]) : 'Command probe succeeded.';
                }
            }
        }

        $kind = $this->resolveKind($definition, $status);
        if ($kind === 'system' && $status === 'installed' && $unitInfo !== null && ($unitInfo['running'] ?? false)) {
            $status = 'active';
        }

        return $base + [
            'kind' => $kind,
            'status' => $status,
            'status_detail' => $statusDetail,
            'unit' => $unit !== '' ? $unit : null,
            'unit_state' => $unitInfo,
            'packages_detected' => $packagesPresent,
            'adoptable' => $kind === 'observed' && in_array($status, ['installed', 'active', 'broken'], true),
        ];
    }

    /** @param array<string, mixed> $definition */
    private function resolveKind(array $definition, string $status): string
    {
        if ((bool) ($definition['system'] ?? false)) {
            return 'system';
        }
        $id = (string) $definition['id'];
        if ($this->managed->has($id)) {
            return 'managed';
        }
        if ($status === 'installed' || $status === 'active' || $status === 'broken') {
            return 'observed';
        }
        return 'managed';
    }

    private function unitLoaded(string $unit): bool
    {
        $result = $this->runtime->exec([
            '/usr/bin/systemctl',
            'show',
            $unit,
            '--property=LoadState',
            '--no-pager',
        ]);
        return str_contains($result->stdout, 'LoadState=loaded');
    }
}
