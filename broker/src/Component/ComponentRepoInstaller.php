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
            'nodejs' => $this->installNodeSourceRepo(
                $os,
                (string) ($options['node_major'] ?? '22'),
                $log
            ),
            default => null,
        };
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
