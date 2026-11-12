<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Co-installed Node.js majors for per-vhost selection (request #7, ADR A51).
 *
 * Distro and NodeSource packages all own /usr/bin/node, so two majors cannot be installed side
 * by side from them. Each major lives in a versioned prefix instead — /opt/azerioid-node/<major>
 * — unpacked from the official nodejs.org tarball whose version and SHA-256 are pinned in the
 * registry entry (the Adminer pattern): the trust anchor is this repository, and the pins were
 * taken from SHASUMS256.txt after checking its signature against the Node.js release keys.
 * Each prefix carries its own PM2, because pm2-runtime must run under the Node it manages.
 *
 * The NodeSource `nodejs` component keeps working as "system" Node, and PM2 vhosts enabled
 * before this change keep running on it: changing a site's Node major can break native modules,
 * so it is only ever the operator's choice (A51).
 */
final class NodeRuntimes
{
    public const BASE = '/opt/azerioid-node';

    public const SYSTEM = 'system';

    /** @var list<string> */
    public const MAJORS = ['20', '22', '24'];

    /**
     * A105: PM2 is installed from the lockfile shipped next to this class (pm2/), whose every
     * package carries a sha512 integrity value — not resolved from the registry at install
     * time, and with no install scripts run as root.
     */
    public const PM2_SPEC = 'pm2@7.1.2';

    /** Where the locked PM2 tree lives, relative to a prefix; bin/ links point into it. */
    private const PM2_DIR = 'lib/azerioid-pm2';

    /** @var list<string> */
    private const PM2_BINS = ['pm2', 'pm2-runtime'];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public static function isComponent(string $componentId): bool
    {
        return self::majorOfComponent($componentId) !== null;
    }

    public static function majorOfComponent(string $componentId): ?string
    {
        return preg_match('/^nodejs-(\d{2})$/', $componentId, $m) === 1 && in_array($m[1], self::MAJORS, true) ? $m[1] : null;
    }

    public static function prefix(string $major): string
    {
        return self::BASE . '/' . $major;
    }

    /** Binaries for a Node choice: 'system' or a major. */
    public function bin(string $node, string $name): ?string
    {
        if ($node === self::SYSTEM) {
            foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name] as $bin) {
                if ($this->runtime->fileExists($bin)) {
                    return $bin;
                }
            }

            return null;
        }
        $bin = self::prefix($node) . '/bin/' . $name;

        return $this->runtime->fileExists($bin) ? $bin : null;
    }

    /**
     * PATH for processes of a Node choice. pm2-runtime and npm start with `#!/usr/bin/env node`,
     * so without the prefix first they would run under whatever `node` the system has.
     */
    public static function pathEnv(string $node): string
    {
        $system = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        return 'PATH=' . ($node === self::SYSTEM ? $system : self::prefix($node) . '/bin:' . $system);
    }

    /** @return list<string> installed choices, 'system' first */
    public function installed(): array
    {
        $out = [];
        if ($this->bin(self::SYSTEM, 'node') !== null) {
            $out[] = self::SYSTEM;
        }
        foreach (self::MAJORS as $major) {
            if ($this->runtime->fileExists(self::prefix($major) . '/bin/node')) {
                $out[] = $major;
            }
        }

        return $out;
    }

    /**
     * A requested Node choice, validated against what is installed. No request: system Node
     * when present (what PM2 vhosts always used), otherwise the newest installed major.
     */
    public function resolve(mixed $requested): string
    {
        $installed = $this->installed();
        if ($installed === []) {
            throw new BrokerException('Node.js is not installed. Install a Node.js component from Components first.', 3);
        }
        if ($requested === null || $requested === '') {
            return in_array(self::SYSTEM, $installed, true) ? self::SYSTEM : (string) end($installed);
        }
        $node = strtolower(trim((string) $requested));
        if ($node !== self::SYSTEM && !in_array($node, self::MAJORS, true)) {
            throw new BrokerException('node must be one of: ' . implode(', ', array_merge([self::SYSTEM], self::MAJORS)) . '.', 2);
        }
        if (!in_array($node, $installed, true)) {
            throw new BrokerException(
                $node === self::SYSTEM
                    ? 'System Node.js is not installed.'
                    : "Node.js {$node} is not installed. Install the Node.js {$node} component first.",
                3
            );
        }

        return $node;
    }

    /** Version string of a Node choice, e.g. v22.23.3; null if it does not run. */
    public function version(string $node): ?string
    {
        $bin = $this->bin($node, 'node');
        if ($bin === null) {
            return null;
        }
        $r = $this->runtime->exec([$bin, '--version'], null, 10);

        return $r->ok() ? trim($r->stdout) : null;
    }

    /** Make sure the Node choice has its own pm2-runtime; install it into the prefix if not. */
    public function ensurePm2(string $node): void
    {
        if ($this->bin($node, 'pm2-runtime') !== null && $this->bin($node, 'pm2') !== null) {
            return;
        }
        $npm = $this->bin($node, 'npm');
        if ($npm === null) {
            throw new BrokerException('npm is required to install PM2 for Node.js ' . $node . '.', 1);
        }
        // System Node's npm owns /usr/lib; the locked tree goes under /usr/local instead.
        $this->installLockedPm2($node === self::SYSTEM ? '/usr/local' : self::prefix($node), $npm, self::pathEnv($node));
        if ($this->bin($node, 'pm2-runtime') === null) {
            throw new BrokerException('pm2-runtime is missing after installing PM2 for Node.js ' . $node . '.', 1);
        }
    }

    /**
     * A105: `npm ci` from the shipped lockfile into <prefix>/lib/azerioid-pm2, then link the
     * PM2 binaries into <prefix>/bin. --ignore-scripts: no package code runs as root at install.
     */
    private function installLockedPm2(string $prefix, string $npm, string $pathEnv): void
    {
        $dir = rtrim($prefix, '/') . '/' . self::PM2_DIR;
        $manifest = @file_get_contents(__DIR__ . '/pm2/package.json');
        $lock = @file_get_contents(__DIR__ . '/pm2/package-lock.json');
        if (!is_string($manifest) || !is_string($lock)) {
            throw new BrokerException('The PM2 lockfile shipped with the broker is missing.', 1);
        }
        $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $dir], null, 120);
        $this->runtime->mkdir($dir, 0755);
        $this->runtime->writeFile($dir . '/package.json', $manifest, 0644);
        $this->runtime->writeFile($dir . '/package-lock.json', $lock, 0644);
        $ci = $this->runtime->exec(
            ['/usr/bin/env', $pathEnv, $npm, 'ci', '--prefix', $dir, '--omit=dev', '--omit=optional', '--ignore-scripts', '--no-audit', '--no-fund'],
            null,
            600
        );
        if (!$ci->ok()) {
            throw new BrokerException('Installing PM2 from its lockfile failed: ' . substr(trim($ci->stderr . "\n" . $ci->stdout), -300), 1);
        }
        $this->runtime->mkdir(rtrim($prefix, '/') . '/bin', 0755);
        foreach (self::PM2_BINS as $name) {
            $link = $this->runtime->exec(['/bin/ln', '-sfn', '../' . self::PM2_DIR . '/node_modules/pm2/bin/' . $name, rtrim($prefix, '/') . '/bin/' . $name], null, 10);
            if (!$link->ok()) {
                throw new BrokerException('Could not link ' . $name . ': ' . trim($link->stderr), 1);
            }
        }
    }

    // ------------------------------------------------------------ component

    /** @param array<string,mixed> $definition */
    public function install(array $definition, OperationLogger $log): void
    {
        $major = self::majorOfComponent((string) ($definition['id'] ?? ''))
            ?? throw new BrokerException('Not a Node.js runtime component.', 2);
        $artifact = is_array($definition['artifact'] ?? null) ? $definition['artifact'] : [];
        $version = (string) ($artifact['version'] ?? '');
        $arch = $this->arch();
        $source = $artifact['sources'][$arch] ?? null;
        if (!preg_match('/^' . $major . '\.\d+\.\d+$/', $version) || !is_array($source)
            || !preg_match('#^https://nodejs\.org/dist/v' . preg_quote($version, '#') . '/node-v' . preg_quote($version, '#') . '-linux-' . $arch . '\.tar\.xz$#', (string) ($source['url'] ?? ''))
            || !preg_match('/^[0-9a-f]{64}$/', (string) ($source['sha256'] ?? ''))) {
            throw new BrokerException("Registry entry for Node.js {$major} has no valid pinned artifact for {$arch}.", 2);
        }

        $prefix = self::prefix($major);
        $staging = rtrim($this->config->stagingDir, '/') . '/node-v' . $version . '-linux-' . $arch . '.tar.xz';
        $next = self::BASE . '/.' . $major . '.new';
        $old = self::BASE . '/.' . $major . '.old';
        if (!$this->runtime->isDir(self::BASE)) {
            $this->runtime->mkdir(self::BASE, 0755);
        }
        $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $next, $old], null, 120);

        try {
            $log->info("Downloading Node.js {$version} ({$arch}) from nodejs.org; checksum pinned in the registry.");
            $fetch = $this->runtime->exec(['/usr/bin/curl', '-fsSL', '--max-time', '600', '-o', $staging, (string) $source['url']], null, 620);
            if (!$fetch->ok()) {
                throw new BrokerException('Node.js download failed: ' . trim($fetch->stderr), 1);
            }
            $hash = $this->runtime->exec(['/usr/bin/sha256sum', $staging], null, 60);
            $got = strtolower((string) (preg_split('/\s+/', trim($hash->stdout))[0] ?? ''));
            if (!$hash->ok() || $got !== strtolower((string) $source['sha256'])) {
                throw new BrokerException('Node.js checksum mismatch; refusing to install.', 1);
            }
            $this->runtime->mkdir($next, 0755);
            $untar = $this->runtime->exec(['/usr/bin/tar', '-xJf', $staging, '-C', $next, '--strip-components=1', '--no-same-owner'], null, 300);
            if (!$untar->ok()) {
                throw new BrokerException('Could not unpack Node.js: ' . trim($untar->stderr), 1);
            }
            $this->runtime->exec(['/usr/bin/chown', '-R', 'root:root', $next], null, 120);
            $this->runtime->exec(['/usr/bin/chmod', '-R', 'go-w', $next], null, 120);
            $probe = $this->runtime->exec([$next . '/bin/node', '--version'], null, 20);
            if (!$probe->ok() || trim($probe->stdout) !== 'v' . $version) {
                throw new BrokerException('The unpacked Node.js does not run (' . trim($probe->stdout . ' ' . $probe->stderr) . ').', 1);
            }
            $log->info('Installing ' . self::PM2_SPEC . " into the Node.js {$major} prefix.");
            $this->installLockedPm2($next, $next . '/bin/npm', 'PATH=' . $next . '/bin:/usr/local/bin:/usr/bin:/bin');
            if ($this->runtime->isDir($prefix)) {
                $this->runtime->rename($prefix, $old);
            }
            $this->runtime->rename($next, $prefix);
            $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $old], null, 120);
            $log->info("Node.js {$version} installed at {$prefix}.");
        } finally {
            $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $next], null, 120);
            if ($this->runtime->fileExists($staging)) {
                $this->runtime->deleteFile($staging);
            }
        }
    }

    public function uninstall(string $major, OperationLogger $log): void
    {
        $users = (new Pm2Manager($this->config, $this->runtime))->vhostsUsingNode($major);
        if ($users !== []) {
            throw new BrokerException("Node.js {$major} is used by PM2 vhost(s): " . implode(', ', $users)
                . '. Move them to another Node.js version first.', 3);
        }
        $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', self::prefix($major)], null, 120);
        $log->info('Removed ' . self::prefix($major) . '.');
    }

    private function arch(): string
    {
        $m = trim($this->runtime->exec(['/usr/bin/uname', '-m'], null, 5)->stdout);

        return match ($m) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new BrokerException("Node.js runtimes are available for x86_64 and aarch64, not {$m}.", 2),
        };
    }
}
