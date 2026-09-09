<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseProvisioner;
use AzerioidPanel\Broker\Php\SitePhpTimeouts;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\BackendEngineBind;
use AzerioidPanel\Broker\Web\VhostFrontRouter;

final class ComponentInstaller
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function install(string $componentId, string $operationId, array $options = []): array
    {
        $componentId = Validator::componentId($componentId);
        $definition = $this->definition($componentId);
        $this->assertInstallable($definition);

        $os = OsRelease::detect($this->runtime);
        $distro = $definition['distros'][$os->distroKey];
        $log = $this->logger($operationId);

        $preflight = (new ComponentPreflight($this->config, $this->runtime, $os))->check($definition);
        if (!$preflight['ok']) {
            throw new BrokerException('Preflight failed: ' . implode(' ', $preflight['issues']), 2);
        }

        $unit = trim((string) ($distro['unit_name'] ?? ''));
        $maskDuringInstall = in_array($componentId, ['apache', 'nginx'], true) && $unit !== '';

        $mutex = new PackageMutex($this->config->stagingDir . '/package.lock');
        $mutex->acquire(120);
        try {
            $log->info('Acquired package manager lock.');
            $this->repairDpkgIfNeeded($log, $os);
            (new ComponentRepoInstaller($this->runtime))->ensureForInstall($os, $componentId, $options, $log);
            if ($maskDuringInstall) {
                $log->info("Masking {$unit} during package install so postinst cannot bind :80/:443 (Caddy owns those ports).");
                $this->runtime->exec(['/usr/bin/systemctl', 'mask', $unit], null, 30);
            }
            $log->info('Installing packages: ' . implode(', ', $distro['packages']));
            $this->installPackages($os, $distro['packages'], $log);
            $this->runShellSteps($distro['post_install'] ?? [], $log, 'post_install');
            $this->runShellSteps($distro['secure'] ?? [], $log, 'secure');
            if (str_starts_with($componentId, 'php-')) {
                $patched = (new SitePhpTimeouts())->patchPools($this->runtime, $this->config);
                if ($patched !== []) {
                    $log->info('Applied FPM request_terminate_timeout on: ' . implode(', ', $patched));
                }
            }
            if ($maskDuringInstall) {
                $this->runtime->exec(['/usr/bin/systemctl', 'unmask', $unit], null, 30);
                $log->info("Binding {$componentId} to its loopback backend port (Caddy keeps :80/:443).");
                (new BackendEngineBind($this->runtime, $this->config))->ensure($componentId);
                (new VhostFrontRouter())->migrate($this->runtime, $this->config);
            }
            if ($componentId === 'supervisor') {
                SupervisedUser::ensure($this->runtime);
                if (!$this->runtime->isDir('/etc/supervisor/conf.d')) {
                    $this->runtime->mkdir('/etc/supervisor/conf.d', 0755);
                }
                $log->info('Ensured dedicated supervised user and conf.d directory.');
            }
            if ($unit !== '') {
                $log->info("Enabling unit {$unit}");
                $this->runtime->exec(['/usr/bin/systemctl', 'enable', '--now', $unit], null, 120);
                Systemd::control($this->runtime, 'restart', $unit);
            }
            if (in_array($componentId, ['mariadb', 'postgresql'], true)) {
                (new DatabaseProvisioner($this->config, $this->runtime))->provision($componentId, $log);
            }
            if ($componentId === 'mongodb') {
                (new MongoProvisioner($this->config, $this->runtime))->provision($log);
            }
            ManagedManifest::record($this->runtime, $this->config->managedComponentsPath, $componentId, [
                'unit' => $unit,
                'packages' => $distro['packages'],
                'installed_at' => $this->runtime->now(),
                'options' => $options,
            ]);
            $log->info('Install completed successfully.');
        } finally {
            if ($maskDuringInstall) {
                $this->runtime->exec(['/usr/bin/systemctl', 'unmask', $unit], null, 30);
            }
            $mutex->release();
        }

        $status = (new ComponentCatalog($this->config, $this->runtime))->status($componentId);

        return [
            'component_id' => $componentId,
            'operation_id' => $operationId,
            'log_path' => $log->path(),
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function uninstall(string $componentId, string $operationId): array
    {
        $componentId = Validator::componentId($componentId);
        $definition = $this->definition($componentId);
        $managed = ManagedManifest::load($this->runtime, $this->config->managedComponentsPath);
        if (!$managed->has($componentId)) {
            throw new BrokerException('Component was not installed by the panel.', 3);
        }
        if ((bool) ($definition['system'] ?? false)) {
            throw new BrokerException('Refusing to remove a system component.', 3);
        }
        if ($componentId === 'php-8.4' || $componentId === 'php-' . $this->config->panelPhpVersion) {
            throw new BrokerException('Refusing to remove the panel PHP runtime.', 3);
        }

        $os = OsRelease::detect($this->runtime);
        $distro = $definition['distros'][$os->distroKey];
        $log = $this->logger($operationId);
        $unit = trim((string) ($distro['unit_name'] ?? ''));

        $mutex = new PackageMutex($this->config->stagingDir . '/package.lock');
        $mutex->acquire(120);
        try {
            if ($unit !== '') {
                $log->info("Stopping unit {$unit}");
                $this->runtime->exec(['/usr/bin/systemctl', 'stop', $unit], null, 60);
                $this->runtime->exec(['/usr/bin/systemctl', 'disable', $unit], null, 60);
            }
            $log->info('Removing packages: ' . implode(', ', $distro['packages']));
            $this->removePackages($os, $distro['packages'], $log);
            ManagedManifest::remove($this->runtime, $this->config->managedComponentsPath, $componentId);
            $log->info('Uninstall completed.');
        } finally {
            $mutex->release();
        }

        return [
            'component_id' => $componentId,
            'operation_id' => $operationId,
            'log_path' => $log->path(),
        ];
    }

    /** @return array<string, mixed> */
    public function operationLog(string $operationId): array
    {
        $path = $this->logPath($operationId);
        if (!$this->runtime->fileExists($path)) {
            return ['operation_id' => $operationId, 'path' => $path, 'lines' => [], 'missing' => true];
        }
        $lines = array_values(array_filter(explode("\n", trim($this->runtime->readFile($path)))));
        return [
            'operation_id' => $operationId,
            'path' => $path,
            'lines' => $lines,
            'missing' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function definition(string $componentId): array
    {
        $registry = new ComponentRegistry($this->config->registryComponentsPath, $this->runtime);
        return $registry->get($componentId);
    }

    /** @param array<string, mixed> $definition */
    private function assertInstallable(array $definition): void
    {
        if ((bool) ($definition['system'] ?? false)) {
            throw new BrokerException('System components cannot be installed from the catalog.', 3);
        }
        if (!(bool) ($definition['installable'] ?? false)) {
            throw new BrokerException('Component is not installable from the panel (not in registry allowlist).', 3);
        }
    }

    private function logger(string $operationId): OperationLogger
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $operationId) ?? '';
        if ($safe === '') {
            throw new BrokerException('Invalid operation id.', 2);
        }
        $dir = rtrim($this->config->stagingDir, '/') . '/operations';
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0750);
        }
        return new OperationLogger($this->runtime, $dir . '/' . $safe . '.log');
    }

    private function logPath(string $operationId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $operationId) ?? '';
        return rtrim($this->config->stagingDir, '/') . '/operations/' . $safe . '.log';
    }

    private function repairDpkgIfNeeded(OperationLogger $log, OsRelease $os): void
    {
        if ($os->pkgMgr !== 'apt') {
            return;
        }
        $audit = $this->runtime->exec(['/usr/bin/dpkg', '--audit']);
        if (trim($audit->stdout . $audit->stderr) === '') {
            return;
        }
        $log->warn('dpkg audit reported broken packages; running dpkg --configure -a');
        $fix = $this->runtime->exec(
            ['/bin/sh', '-c', 'DEBIAN_FRONTEND=noninteractive dpkg --configure -a'],
            null,
            600
        );
        if (!$fix->ok()) {
            throw new BrokerException('dpkg --configure -a failed; fix package state manually.', 1);
        }
    }

    /** @param list<string> $packages */
    private function installPackages(OsRelease $os, array $packages, OperationLogger $log): void
    {
        if ($packages === []) {
            return;
        }
        if ($os->pkgMgr === 'apt') {
            $cmd = array_merge(
                ['/usr/bin/apt-get', '-o', 'DPkg::Lock::Timeout=120', '-y', 'install'],
                $packages
            );
        } else {
            $cmd = array_merge(['/usr/bin/dnf', '-y', 'install'], $packages);
        }
        $result = $this->runtime->exec($cmd, null, 900);
        if (!$result->ok()) {
            $log->warn(trim($result->stderr . "\n" . $result->stdout));
            throw new BrokerException('Package installation failed.', 1);
        }
    }

    /** @param list<string> $packages */
    private function removePackages(OsRelease $os, array $packages, OperationLogger $log): void
    {
        if ($packages === []) {
            return;
        }
        if ($os->pkgMgr === 'apt') {
            $cmd = array_merge(
                ['/usr/bin/apt-get', '-o', 'DPkg::Lock::Timeout=120', '-y', 'remove', '--purge'],
                $packages
            );
        } else {
            $cmd = array_merge(['/usr/bin/dnf', '-y', 'remove'], $packages);
        }
        $result = $this->runtime->exec($cmd, null, 600);
        if (!$result->ok()) {
            $log->warn(trim($result->stderr . "\n" . $result->stdout));
            throw new BrokerException('Package removal failed.', 1);
        }
    }

    /** @param list<string> $steps */
    private function runShellSteps(array $steps, OperationLogger $log, string $label): void
    {
        foreach ($steps as $step) {
            $step = trim((string) $step);
            if ($step === '') {
                continue;
            }
            $log->info("{$label}: {$step}");
            $result = $this->runtime->exec(['/bin/sh', '-c', $step], null, 120);
            if (!$result->ok()) {
                throw new BrokerException("{$label} step failed.", 1);
            }
        }
    }
}
