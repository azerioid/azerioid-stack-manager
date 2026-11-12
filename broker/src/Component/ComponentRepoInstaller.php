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
            'elasticsearch' => $this->installElasticRepo($os, $log),
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
     * A107: the signing key is pinned and the repo file is written here, never fetched.
     */
    private function installDockerRepo(OsRelease $os, OperationLogger $log): void
    {
        if ($os->pkgMgr === 'apt') {
            $list = '/etc/apt/sources.list.d/docker.list';
            if ($this->runtime->fileExists($list)) {
                return;
            }
            $log->info('Adding Docker CE apt repository (download.docker.com; signing key pinned).');
            $keyring = '/usr/share/keyrings/docker-archive-keyring.gpg';
            $suite = $os->distroKey === 'debian' ? 'debian' : 'ubuntu';
            $this->fetchPinnedKey("https://download.docker.com/linux/{$suite}/gpg", self::DOCKER_DEB_KEY, $keyring, false);
            $codename = $os->codename;
            if ($os->distroKey === 'ubuntu') {
                // Prefer VERSION_CODENAME; fall back already handled by OsRelease.
                $codename = $os->codename !== '' ? $os->codename : 'noble';
            }
            $this->runtime->writeFile(
                $list,
                "deb [arch={$this->debArch()} signed-by={$keyring}] https://download.docker.com/linux/{$suite} {$codename} stable\n",
                0644
            );
            $this->aptUpdate($log);

            return;
        }

        $repo = '/etc/yum.repos.d/docker-ce.repo';
        if ($this->runtime->fileExists($repo)) {
            return;
        }
        $log->info('Adding Docker CE yum repository (download.docker.com; signing key pinned).');
        // Alma/Rocky/RHEL: Docker publishes both centos and rhel channels for EL9.
        $channel = in_array($os->id, ['rhel', 'ol'], true) ? 'rhel' : 'centos';
        $rpmKey = '/etc/pki/rpm-gpg/RPM-GPG-KEY-docker-ce';
        $this->fetchPinnedKey("https://download.docker.com/linux/{$channel}/gpg", self::DOCKER_RPM_KEY, $rpmKey, true);
        $this->rpmImport($rpmKey, 'Docker');
        $this->runtime->writeFile(
            $repo,
            "[docker-ce-stable]\n"
            . "name=Docker CE Stable - \$basearch\n"
            . "baseurl=https://download.docker.com/linux/{$channel}/\$releasever/\$basearch/stable\n"
            . "enabled=1\n"
            . "gpgcheck=1\n"
            . "gpgkey=file://{$rpmKey}\n",
            0644
        );
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
            $log->info('Adding Sury PHP repository (signing key pinned).');
            $this->fetchPinnedKey('https://packages.sury.org/php/apt.gpg', self::SURY_KEY, '/usr/share/keyrings/php-sury-archive-keyring.gpg', false);
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
        $log->info('Adding Remi PHP repository (signing key pinned).');
        $major = explode('.', $os->versionId)[0] ?: '9';
        // A107, as the installer does (A66): import Remi's pinned key first, then let dnf
        // verify the remi-release package against it before installing it.
        $rpmKey = '/etc/pki/rpm-gpg/RPM-GPG-KEY-remi2021';
        $this->fetchPinnedKey('https://rpms.remirepo.net/RPM-GPG-KEY-remi2021', self::REMI_KEY, $rpmKey, true);
        $this->rpmImport($rpmKey, 'Remi');
        $release = $this->runtime->exec(
            ['/usr/bin/dnf', '-y', '--setopt=localpkg_gpgcheck=1', 'install', "https://rpms.remirepo.net/enterprise/remi-release-{$major}.rpm"],
            null,
            300
        );
        if (!$release->ok()) {
            throw new BrokerException('Could not install the Remi repository (remi-release did not verify or install).', 1);
        }
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
            $log->info("Adding MongoDB {$version} apt repository (signing key pinned).");
            $keyring = "/usr/share/keyrings/mongodb-server-{$version}.gpg";
            $this->fetchPinnedKey("https://www.mongodb.org/static/pgp/server-{$version}.asc", self::MONGODB_80_KEY, $keyring, false);
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
        $log->info('Adding MongoDB yum repository (signing key pinned).');
        // MongoDB publishes RHEL repos under the major version only (redhat/9/,
        // not redhat/9.8/). AlmaLinux/Rocky VERSION_ID is often "9.x".
        $elMajor = explode('.', $os->versionId)[0] ?: '9';
        $rpmKey = '/etc/pki/rpm-gpg/RPM-GPG-KEY-mongodb-server-8.0';
        $this->fetchPinnedKey('https://www.mongodb.org/static/pgp/server-8.0.asc', self::MONGODB_80_KEY, $rpmKey, true);
        $this->rpmImport($rpmKey, 'MongoDB');
        $this->runtime->writeFile(
            $repo,
            "[mongodb-org-8.0]\nname=MongoDB Repository\nbaseurl=https://repo.mongodb.org/yum/redhat/{$elMajor}/mongodb-org/8.0/x86_64/\ngpgcheck=1\nenabled=1\ngpgkey=file://{$rpmKey}\n",
            0644
        );
        $this->runtime->exec(['/usr/bin/dnf', '-y', 'makecache'], null, 300);
    }

    /*
     * A107: every third-party repository's signing key is pinned by primary fingerprint.
     * Computed from the vendors' key files and checked against their documentation
     * (Docker) and other projects pinning the same keys; Sury and Remi are the pins the
     * installer already uses (deploy/lib/repos.sh, A66).
     */
    public const DOCKER_DEB_KEY = '9DC858229FC7DD38854AE2D88D81803C0EBFCD88';     // Docker Release (CE deb)

    public const DOCKER_RPM_KEY = '060A61C51B558A7F742B77AAC52FEB6B621E9F35';     // Docker Release (CE rpm)

    public const MONGODB_80_KEY = '4B0752C1BCA238C0B4EE14DC41DE058A4E7DCA05';     // MongoDB 8.0 Release Signing Key

    public const NODESOURCE_DEB_KEY = '6F71F525282841EEDAF851B42F59B5F99B1BE0B4'; // NodeSource (deb)

    public const NODESOURCE_RPM_KEY = '242B813831AF09562B6C46F76B88DA4E3AF28A14'; // Nodesource Operations (rpm)

    public const SURY_KEY = '15058500A0235D97F5D10063B188E2B695BD4743';           // DEB.SURY.ORG Automatic Signing Key

    public const REMI_KEY = 'B1ABF71E14C9D74897E198A8B19527F1478F8947';           // Remi's RPM repository 2021

    /** Elastic's signing key, pinned (A10): the key is refused unless it is this one. */
    public const ELASTIC_KEY_FINGERPRINT = '46095ACC8548582C1A2699A9D27D666CD88E42B4';

    private const ELASTIC_MAJOR = '9.x';

    /**
     * Elastic's own repository for Elasticsearch (A54). What gets trusted is a keyring
     * rebuilt from the pinned key alone (A91), never the file that was downloaded.
     */
    private function installElasticRepo(OsRelease $os, OperationLogger $log): void
    {
        $major = self::ELASTIC_MAJOR;
        $keyring = '/usr/share/keyrings/elasticsearch-keyring.gpg';
        $rpmKey = '/etc/pki/rpm-gpg/RPM-GPG-KEY-elasticsearch';
        if ($os->pkgMgr === 'apt' && $this->runtime->fileExists('/etc/apt/sources.list.d/elastic-' . $major . '.list')) {
            return;
        }
        if ($os->pkgMgr !== 'apt' && $this->runtime->fileExists('/etc/yum.repos.d/elasticsearch.repo')) {
            return;
        }
        $log->info("Adding Elastic {$major} repository (signing key fingerprint pinned).");
        $url = 'https://artifacts.elastic.co/GPG-KEY-elasticsearch';
        if ($os->pkgMgr === 'apt') {
            $this->fetchPinnedKey($url, self::ELASTIC_KEY_FINGERPRINT, $keyring, false);
            $this->runtime->writeFile(
                '/etc/apt/sources.list.d/elastic-' . $major . '.list',
                "deb [signed-by={$keyring}] https://artifacts.elastic.co/packages/{$major}/apt stable main\n",
                0644
            );
            $this->aptUpdate($log);

            return;
        }
        $this->fetchPinnedKey($url, self::ELASTIC_KEY_FINGERPRINT, $rpmKey, true);
        $this->rpmImport($rpmKey, 'Elastic');
        // gpgkey points at the pinned local copy: a URL here would let dnf fetch and
        // import whatever that URL serves the next time it needs a key.
        $this->runtime->writeFile(
            '/etc/yum.repos.d/elasticsearch.repo',
            "[elasticsearch]\nname=Elasticsearch repository for {$major} packages\nbaseurl=https://artifacts.elastic.co/packages/{$major}/yum\n"
            . "gpgcheck=1\ngpgkey=file://{$rpmKey}\nenabled=1\nautorefresh=1\ntype=rpm-md\n",
            0644
        );
        $this->runtime->exec(['/usr/bin/dnf', '-y', 'makecache'], null, 300);
    }

    /**
     * A107: download a vendor's key file into a private, root-only directory and install
     * a keyring holding only the pinned key (extractPinnedKey). Every repository this
     * class adds trusts its key this way; nothing is piped from the network into gpg.
     */
    private function fetchPinnedKey(string $url, string $fingerprint, string $dest, bool $armor): void
    {
        // mktemp -d creates the directory exclusively and root-only, so neither the
        // download nor the throwaway keyring sits under a name another account could
        // have prepared in /tmp.
        $made = $this->runtime->exec(['/usr/bin/mktemp', '-d', '/tmp/azerioid-key.XXXXXXXX'], null, 10);
        $work = trim($made->stdout);
        if (!$made->ok() || !str_starts_with($work, '/tmp/azerioid-key.')) {
            throw new BrokerException('Could not create a private staging directory for a signing key.', 1);
        }
        try {
            $fetched = $work . '/fetched.key';
            $fetch = $this->runtime->exec(['/usr/bin/curl', '-fsSL', '--max-time', '60', '-o', $fetched, $url], null, 90);
            if (!$fetch->ok()) {
                throw new BrokerException("Could not download the signing key {$url}.", 1);
            }
            $this->extractPinnedKey($work, $fetched, $fingerprint, $dest, $armor);
        } finally {
            $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $work], null, 30);
        }
    }

    private function rpmImport(string $key, string $vendor): void
    {
        if (!$this->runtime->exec(['/usr/bin/rpm', '--import', $key], null, 60)->ok()) {
            throw new BrokerException("Could not install {$vendor}'s signing key.", 1);
        }
    }

    private function debArch(): string
    {
        $arch = trim($this->runtime->exec(['/usr/bin/dpkg', '--print-architecture'], null, 10)->stdout);

        return $arch !== '' ? $arch : 'amd64';
    }

    /**
     * Write to $dest a keyring holding ONLY the pinned key from a fetched key file — the
     * PHP counterpart of extract_pinned_key in deploy/lib/repos.sh (A66).
     *
     * Checking that the pinned fingerprint is present in the fetched file and then
     * installing that file trusts every other key in it as well. So the file is imported
     * into a throwaway keyring, only the pinned key is exported, and the result must
     * contain exactly one primary key equal to the pin (a gpg key selector also matches
     * subkey fingerprints, so export-by-pin alone does not prove that).
     */
    private function extractPinnedKey(string $work, string $src, string $fingerprint, string $dest, bool $armor): void
    {
        $home = $work . '/gnupg';
        $this->runtime->mkdir($home, 0700);
        $gpg = ['/usr/bin/gpg', '--homedir', $home, '--batch', '--quiet'];
        $primaries = [];
        if ($this->runtime->exec([...$gpg, '--import', $src], null, 30)->ok()
            && $this->runtime->exec(
                [...$gpg, '--yes', ...($armor ? ['--armor'] : []), '--export-options', 'export-minimal', '--output', $dest, '--export', $fingerprint],
                null,
                30
            )->ok()) {
            $afterPub = false;
            $show = $this->runtime->exec(['/usr/bin/gpg', '--batch', '--with-colons', '--show-keys', $dest], null, 30);
            foreach (explode("\n", $show->ok() ? $show->stdout : '') as $line) {
                $field = explode(':', $line);
                if ($field[0] === 'pub') {
                    $afterPub = true;
                } elseif ($field[0] === 'fpr' && $afterPub) {
                    $primaries[] = strtoupper($field[9] ?? '');
                    $afterPub = false;
                } elseif ($field[0] === 'sub') {
                    $afterPub = false;
                }
            }
        }
        if ($primaries !== [strtoupper($fingerprint)]) {
            if ($this->runtime->fileExists($dest)) {
                $this->runtime->deleteFile($dest);
            }
            throw new BrokerException('The downloaded signing key does not yield exactly the pinned fingerprint; refusing to trust it.', 1);
        }
    }

    /**
     * A107: NodeSource's repository, written here as its setup script would write it — the
     * script itself is no longer piped from the network into a root shell — with the
     * signing key pinned.
     */
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

        $log->info("Adding NodeSource repository for Node.js {$major}.x (signing key pinned).");
        if ($os->pkgMgr === 'apt') {
            $keyring = '/usr/share/keyrings/nodesource.gpg';
            $this->fetchPinnedKey('https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key', self::NODESOURCE_DEB_KEY, $keyring, false);
            foreach (['/etc/apt/sources.list.d/nodesource.list', '/etc/apt/sources.list.d/nodesource.sources'] as $old) {
                if ($this->runtime->fileExists($old)) {
                    $this->runtime->deleteFile($old);
                }
            }
            $this->runtime->writeFile(
                '/etc/apt/sources.list.d/nodesource.sources',
                "Types: deb\nURIs: https://deb.nodesource.com/node_{$major}.x\nSuites: nodistro\nComponents: main\n"
                . "Architectures: {$this->debArch()}\nSigned-By: {$keyring}\n",
                0644
            );
            // NodeSource's nodejs must win over the distribution's (as its script pins it).
            $this->runtime->writeFile(
                '/etc/apt/preferences.d/nodejs',
                "Package: nodejs\nPin: origin deb.nodesource.com\nPin-Priority: 600\n",
                0644
            );
        } else {
            $rpmKey = '/etc/pki/rpm-gpg/RPM-GPG-KEY-nodesource';
            $this->fetchPinnedKey('https://rpm.nodesource.com/gpgkey/ns-operations-public.key', self::NODESOURCE_RPM_KEY, $rpmKey, true);
            $this->rpmImport($rpmKey, 'NodeSource');
            $this->runtime->writeFile(
                '/etc/yum.repos.d/nodesource-nodejs.repo',
                "[nodesource-nodejs]\nname=Node.js Packages for Linux RPM based distros - \$basearch\n"
                . "baseurl=https://rpm.nodesource.com/pub_{$major}.x/nodistro/nodejs/\$basearch\n"
                . "priority=9\nenabled=1\ngpgcheck=1\ngpgkey=file://{$rpmKey}\nmodule_hotfixes=1\n",
                0644
            );
            $this->runtime->exec(['/usr/bin/dnf', '-y', 'makecache', '--disablerepo=*', '--enablerepo=nodesource-nodejs'], null, 300);
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
