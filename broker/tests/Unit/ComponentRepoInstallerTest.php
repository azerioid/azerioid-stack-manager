<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Component\ComponentRepoInstaller;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

final class ComponentRepoInstallerTest extends TestCase
{
    /**
     * A107: a vendor whose key file yields the pinned key ($honest) — or another key.
     * gpg --export writes the requested key; --show-keys lists what was "exported".
     */
    private function host(string $osRelease, bool $honest = true): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = $osRelease;
        $rt->script(['/usr/bin/dpkg', '--print-architecture'], 0, "amd64\n", '');
        $exported = '';
        $rt->execFn = function (array $c) use ($rt, $honest, &$exported): ?ExecResult {
            if (($c[0] ?? '') === '/usr/bin/mktemp') {
                return new ExecResult($c, 0, "/tmp/azerioid-key.TEST\n", '');
            }
            if (($c[0] ?? '') === '/usr/bin/gpg' && in_array('--export', $c, true)) {
                $exported = (string) end($c);
                $rt->files[$c[array_search('--output', $c, true) + 1]] = 'key';

                return new ExecResult($c, 0, '', '');
            }
            if (($c[0] ?? '') === '/usr/bin/gpg' && in_array('--show-keys', $c, true)) {
                $fpr = $honest ? $exported : str_repeat('0', 40);

                return new ExecResult($c, 0, "pub:-:4096:1:X:1::::::\nfpr:::::::::{$fpr}:\n", '');
            }

            return null;
        };

        return $rt;
    }

    private const UBUNTU = "ID=ubuntu\nVERSION_ID=\"24.04\"\nVERSION_CODENAME=noble\n";

    private const ALMA = "ID=almalinux\nVERSION_ID=\"9.8\"\n";

    /** @return iterable<string, array{0:string,1:string,2:string,3:list<string>}> component, os, pinned key, files that must not exist on refusal */
    public static function repos(): iterable
    {
        yield 'docker apt' => ['docker', self::UBUNTU, ComponentRepoInstaller::DOCKER_DEB_KEY, ['/etc/apt/sources.list.d/docker.list']];
        yield 'docker dnf' => ['docker', self::ALMA, ComponentRepoInstaller::DOCKER_RPM_KEY, ['/etc/yum.repos.d/docker-ce.repo']];
        yield 'mongodb apt' => ['mongodb', self::UBUNTU, ComponentRepoInstaller::MONGODB_80_KEY, ['/etc/apt/sources.list.d/mongodb-org-8.0.list']];
        yield 'mongodb dnf' => ['mongodb', self::ALMA, ComponentRepoInstaller::MONGODB_80_KEY, ['/etc/yum.repos.d/mongodb-org-8.0.repo']];
        yield 'nodejs apt' => ['nodejs', self::UBUNTU, ComponentRepoInstaller::NODESOURCE_DEB_KEY, ['/etc/apt/sources.list.d/nodesource.sources']];
        yield 'nodejs dnf' => ['nodejs', self::ALMA, ComponentRepoInstaller::NODESOURCE_RPM_KEY, ['/etc/yum.repos.d/nodesource-nodejs.repo']];
        yield 'php apt' => ['php-8.4', self::UBUNTU, ComponentRepoInstaller::SURY_KEY, ['/etc/apt/sources.list.d/php-sury.list']];
        yield 'php dnf' => ['php-8.4', self::ALMA, ComponentRepoInstaller::REMI_KEY, []];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repos')]
    public function test_every_repository_trusts_only_its_pinned_key(string $component, string $osRelease, string $pin, array $files): void
    {
        $rt = $this->host($osRelease);
        (new ComponentRepoInstaller($rt))->ensureForInstall(OsRelease::detect($rt), $component, [], new OperationLogger($rt, '/tmp/op.log'));

        $cmds = array_column($rt->execLog, 'command');
        $exports = array_values(array_filter($cmds, static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/gpg' && in_array('--export', $c, true)));
        $this->assertSame([$pin], array_map(static fn (array $c): string => (string) end($c), $exports));
        $joined = implode("\n", array_map(static fn (array $c): string => implode(' ', $c), $cmds));
        $this->assertStringNotContainsString('| bash', $joined);
        $this->assertStringNotContainsString('| gpg', $joined);
        $this->assertStringNotContainsString('--add-repo', $joined);
        foreach ($files as $f) {
            $this->assertArrayHasKey($f, $rt->files);
            $this->assertStringNotContainsString('gpgkey=http', $rt->files[$f]);
        }
        if ($component === 'php-8.4' && str_contains($osRelease, 'almalinux')) {
            // remi-release is verified against the imported pinned key before it installs.
            $this->assertNotNull(array_filter($cmds, static fn (array $c): bool => in_array('--setopt=localpkg_gpgcheck=1', $c, true)) ?: null);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repos')]
    public function test_a_key_file_without_the_pinned_key_adds_no_repository(string $component, string $osRelease, string $pin, array $files): void
    {
        $rt = $this->host($osRelease, honest: false);
        try {
            (new ComponentRepoInstaller($rt))->ensureForInstall(OsRelease::detect($rt), $component, [], new OperationLogger($rt, '/tmp/op.log'));
            $this->fail('expected refusal');
        } catch (\AzerioidPanel\Broker\BrokerException $e) {
            $this->assertStringContainsString('pinned fingerprint', $e->getMessage());
        }
        foreach ($files as $f) {
            $this->assertArrayNotHasKey($f, $rt->files);
        }
        $this->assertNull(array_filter(array_column($rt->execLog, 'command'), static fn (array $c): bool => in_array('install', $c, true) && ($c[0] ?? '') === '/usr/bin/dnf') ?: null, 'nothing is installed');
    }

    public function test_node_major_defaults_to_22_when_option_missing(): void
    {
        $rt = $this->host(self::UBUNTU);
        (new ComponentRepoInstaller($rt))->ensureForInstall(OsRelease::detect($rt), 'nodejs', [], new OperationLogger($rt, '/tmp/op.log'));
        $this->assertStringContainsString('URIs: https://deb.nodesource.com/node_22.x', $rt->files['/etc/apt/sources.list.d/nodesource.sources'] ?? '');
    }

    public function test_el_mongodb_repo_uses_rhel_major_not_minor_version(): void
    {
        $rt = $this->host(self::ALMA);
        $os = OsRelease::detect($rt);
        $log = new OperationLogger($rt, '/tmp/op.log');

        $installer = new ComponentRepoInstaller($rt);
        $installer->ensureForInstall($os, 'mongodb', [], $log);

        $repo = $rt->files['/etc/yum.repos.d/mongodb-org-8.0.repo'] ?? '';
        $this->assertStringContainsString('repo.mongodb.org/yum/redhat/9/mongodb-org/8.0/x86_64/', $repo);
        $this->assertStringNotContainsString('redhat/9.8/', $repo);
    }

    public function test_ubuntu_docker_repo_uses_download_docker_com(): void
    {
        $rt = $this->host(self::UBUNTU);
        $os = OsRelease::detect($rt);
        $log = new OperationLogger($rt, '/tmp/op.log');

        $installer = new ComponentRepoInstaller($rt);
        $installer->ensureForInstall($os, 'docker', [], $log);

        $list = $rt->files['/etc/apt/sources.list.d/docker.list'] ?? '';
        $this->assertStringContainsString('download.docker.com/linux/ubuntu', $list);
        $this->assertStringContainsString('noble stable', $list);
    }
}
