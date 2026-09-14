<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseProvisioner;
use AzerioidPanel\Broker\Mail\ForeignMta;
use AzerioidPanel\Broker\Mail\MailFirewall;
use AzerioidPanel\Broker\Mail\MailPaths;
use AzerioidPanel\Broker\Mail\MailProvisioner;
use AzerioidPanel\Broker\Mail\MailState;
use AzerioidPanel\Broker\Php\SitePhpTimeouts;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Systemd;
use AzerioidPanel\Broker\Tool\AdminerTool;
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
        foreach (is_array($preflight['warnings'] ?? null) ? $preflight['warnings'] : [] as $warning) {
            $log->warn((string) $warning);
        }
        // Mail's registry conflicts (exim4, sendmail) describe a replaceable MTA, not a
        // dead end: the operator resolves them by typing REPLACE-MTA rather than by
        // uninstalling packages by hand. Everything else still hard-fails on conflict.
        if (!$preflight['ok'] && !($componentId === 'mail' && $this->onlyMtaConflicts($preflight['issues']))) {
            throw new BrokerException('Preflight failed: ' . implode(' ', $preflight['issues']), 2);
        }

        $foreignMta = null;
        if ($componentId === 'mail') {
            $foreignMta = $this->assertMtaReplacementConfirmed($os, $options, $log);
        }

        $unit = trim((string) ($distro['unit_name'] ?? ''));
        $maskDuringInstall = in_array($componentId, ['apache', 'nginx'], true) && $unit !== '';

        $mutex = new PackageMutex($this->config->stagingDir . '/package.lock');
        $mutex->acquire(120);
        try {
            $log->info('Acquired package manager lock.');
            $this->repairDpkgIfNeeded($log, $os);
            if ($foreignMta !== null && $foreignMta['packages'] !== []) {
                $log->warn('Removing foreign MTA package(s) after REPLACE-MTA confirmation: ' . implode(', ', $foreignMta['packages']));
                $this->removePackages($os, $foreignMta['packages'], $log);
            }
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
            if ($componentId === 'adminer') {
                (new AdminerTool($this->config, $this->runtime))->install($definition, $log);
            }
            if ($componentId === 'mail') {
                $paths = MailPaths::for($this->runtime, $this->config, $os);
                (new MailProvisioner($this->config, $this->runtime, $paths))->provision($log);
                $firewall = (new MailFirewall($this->runtime))->open();
                $log->info('Firewall (' . $firewall['backend'] . '): ' . $firewall['detail']);
            }
            $meta = [
                'unit' => $unit,
                'packages' => $distro['packages'],
                'installed_at' => $this->runtime->now(),
                'options' => $this->redactInstallOptions($options),
            ];
            if ($componentId === 'mail') {
                // Adopt-vs-fresh is part of the managed record (A36 §2.2 step 3).
                $meta['adopted_mta'] = $foreignMta !== null && $foreignMta['present'];
                $meta['replaced_packages'] = $foreignMta['packages'] ?? [];
            }
            ManagedManifest::record($this->runtime, $this->config->managedComponentsPath, $componentId, $meta);
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
    public function uninstall(string $componentId, string $operationId, array $options = []): array
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
            if ($componentId === 'adminer') {
                (new AdminerTool($this->config, $this->runtime))->uninstall($log);
            }
            if ($componentId === 'mail') {
                $this->teardownMail($os, $options, $log);
            }
            if ($unit !== '') {
                $log->info("Stopping unit {$unit}");
                $this->runtime->exec(['/usr/bin/systemctl', 'stop', $unit], null, 60);
                $this->runtime->exec(['/usr/bin/systemctl', 'disable', $unit], null, 60);
            }
            $log->info('Removing packages: ' . implode(', ', $distro['packages']));
            $this->removePackages($os, $this->packagesForRemoval($componentId, $os, $distro['packages']), $log);
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

    /**
     * A36 §9.8: replacing any foreign MTA — including Debian's stock Exim — always
     * requires a typed REPLACE-MTA. There is no auto-replace convenience exception,
     * because silently uninstalling the host's mail transport is not recoverable
     * from the panel.
     *
     * @param  array<string,mixed>  $options
     * @return array{present:bool,packages:list<string>,reasons:list<string>}|null
     */
    private function assertMtaReplacementConfirmed(OsRelease $os, array $options, OperationLogger $log): ?array
    {
        $detector = new ForeignMta($this->config, $this->runtime, $os);
        $detected = $detector->detect();
        if (!$detected['present']) {
            return null;
        }

        try {
            Validator::typedConfirm((string) ($options['confirm'] ?? ''), Validator::REPLACE_MTA_CONFIRM);
        } catch (BrokerException) {
            // The generic confirmation error leaves the operator with nothing to act on;
            // say what was found and what to type.
            throw new BrokerException(
                implode(' ', $detected['reasons'])
                . ' Installing the mail component replaces it. Confirm with '
                . Validator::REPLACE_MTA_CONFIRM . ' to proceed.',
                3
            );
        }
        foreach ($detected['reasons'] as $reason) {
            $log->warn('Existing mail transport detected: ' . $reason);
        }

        return [
            'present' => true,
            'packages' => $detector->packagesToRemove(),
            'reasons' => $detected['reasons'],
        ];
    }

    /**
     * Mail removal closes the public ports and stops the auxiliary units, but keeps
     * /var/vmail unless the operator explicitly drops it — operator data survives an
     * uninstall here for the same reason /data/www does (A29).
     *
     * @param array<string,mixed> $options
     */
    private function teardownMail(OsRelease $os, array $options, OperationLogger $log): void
    {
        $firewall = (new MailFirewall($this->runtime))->close();
        $log->info('Firewall (' . $firewall['backend'] . '): ' . $firewall['detail']);

        foreach (['dovecot', 'opendkim'] as $unit) {
            $this->runtime->exec(['/usr/bin/systemctl', 'disable', '--now', $unit], null, 60);
        }

        $paths = MailPaths::for($this->runtime, $this->config, $os);
        $vmailRoot = $paths->vmailRoot();
        $dropMail = in_array(strtolower(trim((string) ($options['drop_mail'] ?? ''))), ['1', 'true', 'yes', 'on'], true)
            || ($options['drop_mail'] ?? false) === true;
        if (!$dropMail) {
            $log->info("Retaining mailbox data in {$vmailRoot} (pass drop_mail to remove it).");

            return;
        }
        Validator::typedConfirm((string) ($options['confirm'] ?? ''), Validator::DROP_MAIL_CONFIRM);
        $log->warn("Removing all mailbox data under {$vmailRoot} after DROP-MAIL confirmation.");
        $this->runtime->exec(['/bin/rm', '-rf', $vmailRoot], null, 300);
        foreach ([MailState::STATE_PATH, MailState::PASSDB_PATH] as $path) {
            if ($this->runtime->fileExists($path)) {
                $this->runtime->deleteFile($path);
            }
        }
    }

    /** @param list<string> $issues */
    private function onlyMtaConflicts(array $issues): bool
    {
        if ($issues === []) {
            return false;
        }
        foreach ($issues as $issue) {
            if (!str_starts_with((string) $issue, 'Conflicts with ')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install options land in the managed manifest, which is not a secret store.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function redactInstallOptions(array $options): array
    {
        foreach (['confirm', 'password', 'token', 'secret'] as $key) {
            unset($options[$key]);
        }

        return $options;
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
        if ($os->pkgMgr === 'apt') {
            $auto = $this->runtime->exec(
                ['/usr/bin/apt-get', '-o', 'DPkg::Lock::Timeout=120', '-y', 'autoremove', '--purge'],
                null,
                600
            );
            if (!$auto->ok()) {
                $log->warn('apt-get autoremove --purge: ' . trim($auto->stderr . "\n" . $auto->stdout));
            }
        }
    }

    /**
     * Expand the registry package list for apt removals that leave versioned dependents behind
     * (Debian/Ubuntu `postgresql` metapackage → `postgresql-17`, clients, common).
     *
     * @param list<string> $packages
     * @return list<string>
     */
    private function packagesForRemoval(string $componentId, OsRelease $os, array $packages): array
    {
        if ($os->pkgMgr !== 'apt' || $componentId !== 'postgresql') {
            return $packages;
        }
        $extra = PackageQuery::listInstalledMatching($this->runtime, '/^postgresql/', 'apt');
        if ($extra === []) {
            return $packages;
        }
        $merged = array_values(array_unique([...$packages, ...$extra]));
        sort($merged);

        return $merged;
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
