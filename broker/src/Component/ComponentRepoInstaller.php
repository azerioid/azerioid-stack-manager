<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

final class ComponentRepoInstaller
{
    public function __construct(
        private readonly Runtime $runtime,
    ) {
    }

    public function ensureForInstall(OsRelease $os, string $componentId, array $options, OperationLogger $log): void
    {
        if (str_starts_with($componentId, 'php-')) {
            $this->ensurePhpRepo($os, $log);

            return;
        }

        match ($componentId) {
            'mongodb' => $this->installMongoDbRepo($os, $log),
            'mail' => $this->ensureEpel($os, $log),
            'docker' => $this->installDockerRepo($os, $log),
            'nodejs' => $this->installNodeSourceRepo(
                $os,
                (string) ($options['node_major'] ?? '22'),
                $log
            ),
            default => null,
        };
    }

    /**
     * Official Docker CE apt/dnf repos (download.docker.com) — not distro docker.io.
     */
    private function installDockerRepo(OsRelease $os, OperationLogger $log): void
    {
        if ($os->pkgMgr === 'apt') {
            $list = '/etc/apt/sources.list.d/docker.list';
            if ($this->runtime->fileExists($list)) {
                return;
            }
            $log->info('Adding Docker CE apt repository (download.docker.com).');
            $keyring = '/usr/share/keyrings/docker-archive-keyring.gpg';
            $suite = $os->distroKey === 'debian' ? 'debian' : 'ubuntu';
            $this->runtime->exec(
                [
                    '/bin/sh',
                    '-c',
                    "curl -fsSL https://download.docker.com/linux/{$suite}/gpg | gpg --batch --yes --dearmor -o {$keyring}",
                ],
                null,
                120
            );
            $codename = $os->codename;
            if ($os->distroKey === 'ubuntu') {
                // Prefer VERSION_CODENAME; fall back already handled by OsRelease.
                $codename = $os->codename !== '' ? $os->codename : 'noble';
            }
            $arch = trim($this->runtime->exec(['/usr/bin/dpkg', '--print-architecture'], null, 10)->stdout);
            if ($arch === '') {
                $arch = 'amd64';
            }
            $this->runtime->writeFile(
                $list,
                "deb [arch={$arch} signed-by={$keyring}] https://download.docker.com/linux/{$suite} {$codename} stable\n",
                0644
            );
            $this->aptUpdate($log);

            return;
        }

        $repo = '/etc/yum.repos.d/docker-ce.repo';
        if ($this->runtime->fileExists($repo)) {
            return;
        }
        $log->info('Adding Docker CE yum repository (download.docker.com).');
        // Alma/Rocky/RHEL: Docker publishes both centos and rhel channels for EL9.
        $channel = in_array($os->id, ['rhel', 'ol'], true) ? 'rhel' : 'centos';
        $result = $this->runtime->exec(
            [
                '/usr/bin/dnf',
                '-y',
                'config-manager',
                '--add-repo',
                "https://download.docker.com/linux/{$channel}/docker-ce.repo",
            ],
            null,
            120
        );
        if (!$result->ok()) {
            // Fallback: write the repo file when config-manager is unavailable.
            $this->runtime->writeFile(
                $repo,
                "[docker-ce-stable]\n"
                . "name=Docker CE Stable - \$basearch\n"
                . "baseurl=https://download.docker.com/linux/{$channel}/\$releasever/\$basearch/stable\n"
                . "enabled=1\n"
                . "gpgcheck=1\n"
                . "gpgkey=https://download.docker.com/linux/{$channel}/gpg\n",
                0644
            );
        }
        $this->runtime->exec(['/usr/bin/dnf', '-y', 'makecache'], null, 300);
    }

    /**
     * OpenDKIM is not in EL base/AppStream, so EPEL is an accepted dependency of
     * the mail component (ADR A36 §9.5). CRB is enabled alongside it because EPEL
     * packages routinely build against it.
     */
    private function ensureEpel(OsRelease $os, OperationLogger $log): void
    {
        if ($os->pkgMgr !== 'dnf') {
            return;
        }
        if (!PackageQuery::isInstalled($this->runtime, 'epel-release', $os->pkgMgr)) {
            $log->info('Adding EPEL (required for OpenDKIM on EL).');
            $result = $this->runtime->exec(['/usr/bin/dnf', '-y', 'install', 'epel-release'], null, 300);
            if (!$result->ok()) {
                throw new BrokerException('Could not enable EPEL, which OpenDKIM requires on this OS.', 1);
            }
        }

        // CRB is "powertools" before EL9; try both and let the wrong one fail quietly.
        foreach (['crb', 'powertools'] as $repo) {
            $enabled = $this->runtime->exec(
                ['/usr/bin/dnf', 'config-manager', '--set-enabled', $repo],
                null,
                120
            );
            if ($enabled->ok()) {
                $log->info("Enabled {$repo} repository for EPEL dependencies.");
                break;
            }
        }
        $this->runtime->exec(['/usr/bin/dnf', '-y', 'makecache'], null, 300);
    }

    private function ensurePhpRepo(OsRelease $os, OperationLogger $log): void
    {
        if ($os->pkgMgr === 'apt') {
            $list = '/etc/apt/sources.list.d/php-sury.list';
            if ($this->runtime->fileExists($list)) {
                return;
            }
            $log->info('Adding Sury PHP repository.');
            $this->runtime->exec(
                ['/bin/sh', '-c', 'curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --batch --yes --dearmor -o /usr/share/keyrings/php-sury-archive-keyring.gpg'],
                null,
                120
            );
            $this->runtime->writeFile(
                $list,
                "deb [signed-by=/usr/share/keyrings/php-sury-archive-keyring.gpg] https://packages.sury.org/php/ {$os->codename} main\n",
                0644
            );
            $this->aptUpdate($log);

            return;
        }

        $remi = '/etc/yum.repos.d/remi.repo';
        if ($this->runtime->fileExists($remi)) {
            return;
        }
        $log->info('Adding Remi PHP repository.');
        $major = explode('.', $os->versionId)[0] ?: '9';
        $this->runtime->exec(
            ['/usr/bin/dnf', '-y', 'install', "https://rpms.remirepo.net/enterprise/remi-release-{$major}.rpm"],
            null,
            300
        );
        $this->runtime->exec(['/usr/bin/dnf', '-y', 'makecache'], null, 300);
    }

    private function installMongoDbRepo(OsRelease $os, OperationLogger $log): void
    {
        $version = '8.0';
        if ($os->pkgMgr === 'apt') {
            $list = "/etc/apt/sources.list.d/mongodb-org-{$version}.list";
            if ($this->runtime->fileExists($list)) {
                return;
            }
            $log->info("Adding MongoDB {$version} apt repository.");
            $keyring = "/usr/share/keyrings/mongodb-server-{$version}.gpg";
            $this->runtime->exec(
                ['/bin/sh', '-c', "curl -fsSL https://www.mongodb.org/static/pgp/server-{$version}.asc | gpg --batch --yes --dearmor -o {$keyring}"],
                null,
                120
            );
            $suite = $os->distroKey === 'debian' ? 'debian' : 'ubuntu';
            $codename = $os->codename;
            // MongoDB 8.0 publishes debian server packages under bookworm only.
            // The trixie path exists but currently ships mongosh, not mongodb-org.
            if ($os->distroKey === 'debian' && ($codename === 'trixie' || version_compare($os->versionId, '13', '>='))) {
                $codename = 'bookworm';
            }
            $component = $os->distroKey === 'debian' ? 'main' : 'multiverse';
            $this->runtime->writeFile(
                $list,
                "deb [ signed-by={$keyring} ] https://repo.mongodb.org/apt/{$suite} {$codename}/mongodb-org/{$version} {$component}\n",
                0644
            );
            $this->aptUpdate($log);

            return;
        }

        $repo = '/etc/yum.repos.d/mongodb-org-8.0.repo';
        if ($this->runtime->fileExists($repo)) {
            return;
        }
        $log->info('Adding MongoDB yum repository.');
        // MongoDB publishes RHEL repos under the major version only (redhat/9/,
        // not redhat/9.8/). AlmaLinux/Rocky VERSION_ID is often "9.x".
        $elMajor = explode('.', $os->versionId)[0] ?: '9';
        $this->runtime->writeFile(
            $repo,
            "[mongodb-org-8.0]\nname=MongoDB Repository\nbaseurl=https://repo.mongodb.org/yum/redhat/{$elMajor}/mongodb-org/8.0/x86_64/\ngpgcheck=1\nenabled=1\ngpgkey=https://www.mongodb.org/static/pgp/server-8.0.asc\n",
            0644
        );
        $this->runtime->exec(['/usr/bin/dnf', '-y', 'makecache'], null, 300);
    }

    private function installNodeSourceRepo(OsRelease $os, string $major, OperationLogger $log): void
    {
        $major = preg_replace('/\D/', '', $major) ?: '22';
        if (!in_array($major, ['20', '22', '24'], true)) {
            throw new BrokerException('Unsupported Node.js major version.', 2);
        }

        $marker = "/etc/azerioid-panel/nodesource-{$major}.installed";
        if ($this->runtime->fileExists($marker)) {
            return;
        }

        $log->info("Adding NodeSource repository for Node.js {$major}.x");
        if ($os->pkgMgr === 'apt') {
            $result = $this->runtime->exec(
                ['/bin/sh', '-c', "curl -fsSL https://deb.nodesource.com/setup_{$major}.x | bash -"],
                null,
                300
            );
        } else {
            $result = $this->runtime->exec(
                ['/bin/sh', '-c', "curl -fsSL https://rpm.nodesource.com/setup_{$major}.x | bash -"],
                null,
                300
            );
        }
        if (!$result->ok()) {
            throw new BrokerException('NodeSource repository setup failed.', 1);
        }
        $this->runtime->mkdir('/etc/azerioid-panel', 0750);
        $this->runtime->writeFile($marker, $this->runtime->now() . "\n", 0644);
        if ($os->pkgMgr === 'apt') {
            $this->aptUpdate($log);
        }
    }

    private function aptUpdate(OperationLogger $log): void
    {
        $log->info('Refreshing apt package index.');
        $result = $this->runtime->exec(
            ['/usr/bin/apt-get', '-o', 'DPkg::Lock::Timeout=120', 'update'],
            null,
            300
        );
        if (!$result->ok()) {
            throw new BrokerException('apt-get update failed after adding repository.', 1);
        }
    }
}
