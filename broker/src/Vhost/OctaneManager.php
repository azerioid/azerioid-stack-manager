<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Supervisor\SupervisorManager;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\VhostEngine;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Per-vhost, opt-in Laravel Octane (FrankenPHP) runtime (ADR A35).
 *
 * The worker runs under Supervisor as azerioid-supervised on a loopback port;
 * Caddy stays the front door and reverse-proxies to it exactly like a type=proxy
 * vhost. The vhost stays type=php so PHP version, docroot, and TLS keep working.
 * The panel's own runtime is never switched to Octane.
 */
final class OctaneManager
{
    public const RUNTIME_FPM = 'fpm';
    public const RUNTIME_OCTANE = 'octane';

    /** Loopback range reserved for Octane workers (docs/port-ownership.md). */
    public const PORT_MIN = 34000;
    public const PORT_MAX = 34999;

    public const DEFAULT_MAX_REQUESTS = 500;
    public const MIN_MAX_REQUESTS = 50;
    public const MAX_MAX_REQUESTS = 100000;

    public const PROGRAM_PREFIX = 'octane-';
    public const DOCS_URL = 'https://laravel.com/docs/octane';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        $laravel = self::detectLaravel($this->runtime, $vhost['root'] ?? null);
        $program = self::programName($domain);

        return [
            'domain' => $domain,
            'runtime' => self::normalizeRuntime($vhost['runtime'] ?? self::RUNTIME_FPM),
            'octane_port' => $vhost['octane_port'] ?? null,
            'octane_max_requests' => $vhost['octane_max_requests'] ?? null,
            'octane_program' => $program,
            'program' => $this->programRow($program),
            'laravel_app' => $laravel['laravel'],
            'laravel_app_detail' => $laravel['detail'],
            'app_dir' => $laravel['app_dir'],
            'docs_url' => self::DOCS_URL,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function enable(string $domain, array $input = []): array
    {
        $domain = Validator::domain($domain);
        $supervisor = new SupervisorManager($this->config, $this->runtime);
        $supervisor->assertInstalled();

        $vhost = $this->assertOctaneCandidate($domain);
        if (self::normalizeRuntime($vhost['runtime'] ?? self::RUNTIME_FPM) === self::RUNTIME_OCTANE) {
            throw new BrokerException("Octane is already enabled for {$domain}.", 3);
        }

        $appDir = $this->assertLaravelApp($domain, $vhost['root'] ?? null);
        $phpVersion = (string) ($vhost['php_version'] ?? '');
        $php = $this->phpBin($phpVersion);
        $maxRequests = self::validateMaxRequests($input['max_requests'] ?? self::DEFAULT_MAX_REQUESTS);
        $reserved = $this->reservedPorts($domain);
        if (array_key_exists('port', $input) && $input['port'] !== null && $input['port'] !== '') {
            $port = self::validatePort($input['port']);
            if (in_array($port, $reserved, true)) {
                throw new BrokerException("Port {$port} is already used by another Octane vhost.", 3);
            }
        } else {
            $port = $this->allocatePort($reserved);
        }

        // Composer / octane:install write vendor + the FrankenPHP binary — grant the
        // supervised user recursive access on the full Laravel app (docroot may be …/public).
        $this->ensureAppWritable($appDir);
        $this->installOctanePackage($appDir, $php);
        $this->installFrankenPhpServer($appDir, $php);

        $program = self::programName($domain);
        $this->upsertProgram($supervisor, $program, [
            'command' => $this->octaneCommand($php, $port, $maxRequests),
            'directory' => $appDir,
            'vhost_domain' => $domain,
            'autostart' => true,
            'autorestart' => true,
        ]);
        $supervisor->control($program, 'start');

        if (!$this->waitForPort($port)) {
            $this->removeProgram($supervisor, $program);
            throw new BrokerException(
                "Octane did not start listening on 127.0.0.1:{$port}. Check the process logs under "
                . SupervisedUser::LOG_DIR . '/' . $program . '.stderr.log — the vhost was left on PHP-FPM.',
                1
            );
        }

        try {
            $applied = WebServers::for($this->config)->updateVhost($this->runtime, $this->config, $domain, [
                'runtime' => self::RUNTIME_OCTANE,
                'octane_port' => $port,
                'octane_max_requests' => $maxRequests,
            ]);
        } catch (\Throwable $e) {
            $this->removeProgram($supervisor, $program);
            throw $e instanceof BrokerException
                ? new BrokerException($e->getMessage() . ' Octane was rolled back; the vhost still serves through PHP-FPM.', $e->errorCode)
                : new BrokerException($e->getMessage(), 1);
        }

        return [
            'domain' => $domain,
            'enabled' => true,
            'runtime' => self::RUNTIME_OCTANE,
            'octane_port' => $port,
            'octane_max_requests' => $maxRequests,
            'octane_program' => $program,
            'app_dir' => $appDir,
            'php_version' => $phpVersion,
            'apply' => $applied['apply'] ?? null,
            'docs_url' => self::DOCS_URL,
        ];
    }

    /** @return array<string, mixed> */
    public function disable(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        if (self::normalizeRuntime($vhost['runtime'] ?? self::RUNTIME_FPM) !== self::RUNTIME_OCTANE) {
            throw new BrokerException("Octane is not enabled for {$domain}.", 3);
        }

        // Move traffic back to PHP-FPM first (validate → apply → rollback), then stop the worker.
        $applied = WebServers::for($this->config)->updateVhost($this->runtime, $this->config, $domain, [
            'runtime' => self::RUNTIME_FPM,
        ]);

        $program = self::programName($domain);
        $removed = $this->removeProgram(new SupervisorManager($this->config, $this->runtime), $program);

        // Composer/Octane runs as azerioid-supervised and may leave storage owned in ways
        // the site PHP-FPM pool cannot write — re-apply vhost ACLs on the docroot + app.
        $root = (string) ($vhost['root'] ?? '');
        if ($root !== '') {
            VhostUser::ensure($this->runtime, $this->config, $domain, $root);
            $appDir = self::detectLaravel($this->runtime, $root)['app_dir'] ?? null;
            if (is_string($appDir) && $appDir !== '') {
                $this->ensureFpmWritableLaravelDirs($appDir);
            }
        }

        return [
            'domain' => $domain,
            'disabled' => true,
            'runtime' => self::RUNTIME_FPM,
            'octane_program' => $program,
            'program_removed' => $removed,
            'apply' => $applied['apply'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function reload(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        if (self::normalizeRuntime($vhost['runtime'] ?? self::RUNTIME_FPM) !== self::RUNTIME_OCTANE) {
            throw new BrokerException("Octane is not enabled for {$domain}.", 3);
        }

        $appDir = $this->assertLaravelApp($domain, $vhost['root'] ?? null);
        $php = $this->phpBin((string) ($vhost['php_version'] ?? ''));
        $program = self::programName($domain);

        $reload = $this->runAsSupervised([$php, 'artisan', 'octane:reload'], $appDir, 120);
        if ($reload->ok()) {
            return [
                'domain' => $domain,
                'reloaded' => true,
                'method' => 'octane:reload',
                'octane_program' => $program,
                'output' => self::execDetail($reload),
            ];
        }

        $supervisor = new SupervisorManager($this->config, $this->runtime);
        $restart = $supervisor->control($program, 'restart');

        return [
            'domain' => $domain,
            'reloaded' => true,
            'method' => 'supervisor-restart',
            'octane_program' => $program,
            'output' => (string) ($restart['output'] ?? ''),
            'fallback_reason' => self::execDetail($reload),
        ];
    }

    public static function normalizeRuntime(mixed $value): string
    {
        return AppRuntime::normalize($value);
    }

    public static function validatePort(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1)) {
            throw new BrokerException('Octane port must be a number.', 2);
        }
        $port = (int) $value;
        if ($port < self::PORT_MIN || $port > self::PORT_MAX) {
            throw new BrokerException(
                'Octane port must be between ' . self::PORT_MIN . ' and ' . self::PORT_MAX . '.',
                2
            );
        }

        return $port;
    }

    public static function validateMaxRequests(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,7}$/', trim($value)) === 1)) {
            throw new BrokerException('max-requests must be a number.', 2);
        }
        $max = (int) $value;
        if ($max < self::MIN_MAX_REQUESTS || $max > self::MAX_MAX_REQUESTS) {
            throw new BrokerException(
                'max-requests must be between ' . self::MIN_MAX_REQUESTS . ' and ' . self::MAX_MAX_REQUESTS . '.',
                2
            );
        }

        return $max;
    }

    /** Supervisor program name for a domain — stable, and validated by Validator. */
    public static function programName(string $domain): string
    {
        $slug = strtolower(trim($domain));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        if ($slug === '') {
            throw new BrokerException('Cannot derive an Octane program name for this domain.', 2);
        }
        $name = self::PROGRAM_PREFIX . $slug;
        if (strlen($name) > 49) {
            $hash = substr(hash('crc32b', $domain), 0, 8);
            $name = substr(self::PROGRAM_PREFIX . $hash . '-' . $slug, 0, 49);
            $name = rtrim($name, '-');
        }

        return Validator::supervisorProgramName($name);
    }

    /**
     * A vhost is an Octane candidate only when it is a real Laravel app:
     * an artisan entrypoint plus laravel/framework (required or installed).
     *
     * @return array{laravel:bool, app_dir:?string, detail:string}
     */
    public static function detectLaravel(Runtime $runtime, ?string $root): array
    {
        $root = is_string($root) ? rtrim(trim($root), '/') : '';
        if ($root === '') {
            return ['laravel' => false, 'app_dir' => null, 'detail' => 'Vhost has no document root.'];
        }

        $appDir = null;
        foreach (self::appDirCandidates($root) as $candidate) {
            if ($runtime->fileExists($candidate . '/artisan')) {
                $appDir = $candidate;
                break;
            }
        }
        if ($appDir === null) {
            return [
                'laravel' => false,
                'app_dir' => null,
                'detail' => 'No artisan entrypoint found in ' . $root . ' or its parent application directory.',
            ];
        }

        if ($runtime->isDir($appDir . '/vendor/laravel/framework')) {
            return ['laravel' => true, 'app_dir' => $appDir, 'detail' => 'vendor/laravel/framework is installed.'];
        }
        if (self::composerRequiresLaravel($runtime, $appDir . '/composer.json')) {
            return ['laravel' => true, 'app_dir' => $appDir, 'detail' => 'composer.json requires laravel/framework.'];
        }

        return [
            'laravel' => false,
            'app_dir' => $appDir,
            'detail' => 'artisan was found in ' . $appDir . ', but laravel/framework is neither required in composer.json nor installed in vendor/.',
        ];
    }

    /**
     * @param  list<int>  $reserved
     */
    public function allocatePort(array $reserved = []): int
    {
        $used = [];
        foreach ($reserved as $port) {
            $used[(int) $port] = true;
        }
        for ($port = self::PORT_MIN; $port <= self::PORT_MAX; $port++) {
            if (isset($used[$port]) || $this->portListening($port)) {
                continue;
            }

            return $port;
        }

        throw new BrokerException(
            'No free Octane port available in ' . self::PORT_MIN . '-' . self::PORT_MAX . '.',
            1
        );
    }

    /** @return list<string> */
    private static function appDirCandidates(string $root): array
    {
        $candidates = [$root];
        // Laravel docroots point at <app>/public; the worker must run from <app>.
        if (basename($root) === 'public') {
            $parent = dirname($root);
            if ($parent !== '' && $parent !== '/' && $parent !== $root) {
                $candidates[] = $parent;
            }
        }

        return $candidates;
    }

    private static function composerRequiresLaravel(Runtime $runtime, string $path): bool
    {
        if (!$runtime->fileExists($path)) {
            return false;
        }
        try {
            $decoded = json_decode($runtime->readFile($path), true);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($decoded)) {
            return false;
        }
        foreach (['require', 'require-dev'] as $section) {
            if (is_array($decoded[$section] ?? null) && isset($decoded[$section]['laravel/framework'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function findVhost(string $domain): array
    {
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            if (($vhost['domain'] ?? '') === $domain || in_array($domain, $vhost['domains'] ?? [], true)) {
                return $vhost;
            }
        }

        throw new BrokerException('Vhost config does not exist.', 3);
    }

    /** @return array<string, mixed> */
    private function assertOctaneCandidate(string $domain): array
    {
        $vhost = $this->findVhost($domain);
        if (!empty($vhost['readonly'])) {
            throw new BrokerException('This vhost is managed externally and cannot be switched to Octane.', 3);
        }
        if ((string) ($vhost['type'] ?? '') !== 'php') {
            throw new BrokerException('Octane is only available for PHP vhosts.', 3);
        }
        $engine = VhostEngine::normalize($vhost['engine'] ?? VhostEngine::CADDY);
        if (VhostEngine::isBackend($engine)) {
            throw new BrokerException(
                'Octane requires the Caddy engine. Switch ' . $domain . ' to engine=caddy first — '
                . 'Apache and Nginx backends cannot front an Octane worker.',
                3
            );
        }
        if ((string) ($vhost['php_version'] ?? '') === '') {
            throw new BrokerException('Vhost has no PHP version; set one before enabling Octane.', 2);
        }

        return $vhost;
    }

    private function assertLaravelApp(string $domain, ?string $root): string
    {
        $detected = self::detectLaravel($this->runtime, $root);
        if (!$detected['laravel'] || $detected['app_dir'] === null) {
            throw new BrokerException(
                "{$domain} does not look like a Laravel application, so Octane cannot run it. "
                . $detected['detail'] . ' See ' . self::DOCS_URL . '.',
                3
            );
        }

        return $detected['app_dir'];
    }

    /** @return list<int> */
    private function reservedPorts(string $exceptDomain): array
    {
        $ports = [];
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            if (($vhost['domain'] ?? '') === $exceptDomain) {
                continue;
            }
            $port = (int) ($vhost['octane_port'] ?? 0);
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    private function octaneCommand(string $php, int $port, int $maxRequests): string
    {
        return Validator::supervisorCommand(implode(' ', [
            $php,
            'artisan',
            'octane:start',
            '--server=frankenphp',
            '--host=127.0.0.1',
            '--port=' . $port,
            '--max-requests=' . $maxRequests,
        ]));
    }

    private function ensureAppWritable(string $appDir): void
    {
        if ($this->runtime->getuid() !== 0) {
            return;
        }
        SupervisedUser::ensure($this->runtime);
        $user = SupervisedUser::USERNAME;
        $group = 'azerioid-vhosts';

        // Prefer ACL when available (Ubuntu/Debian often ship without `acl` by default).
        $setfacl = null;
        foreach (['/usr/bin/setfacl', '/bin/setfacl'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                $setfacl = $bin;
                break;
            }
        }
        if ($setfacl !== null) {
            $acl = $this->runtime->exec([
                $setfacl,
                '-R',
                '-m',
                'u:' . $user . ':rwx',
                '-m',
                'd:u:' . $user . ':rwx',
                $appDir,
            ], null, 120);
            if ($acl->ok()) {
                return;
            }
        }

        // Group-writable fallback: supervised is a member of azerioid-vhosts.
        $this->runtime->exec(['/bin/chgrp', '-R', $group, $appDir], null, 60);
        $this->runtime->exec(['/bin/chmod', '-R', 'g+rwX', $appDir], null, 60);
    }

    /**
     * After Octane disable, ensure Laravel writable dirs remain usable by the site FPM pool
     * (group bits + SELinux httpd_sys_rw_content_t when enforcing).
     */
    private function ensureFpmWritableLaravelDirs(string $appDir): void
    {
        if ($this->runtime->getuid() !== 0) {
            return;
        }
        $dirs = [
            $appDir . '/storage',
            $appDir . '/bootstrap/cache',
            $appDir . '/database',
        ];
        $phpUser = $this->config->phpUser !== '' ? $this->config->phpUser : 'caddy';
        $phpGroup = VhostUser::GROUP;
        foreach ($dirs as $writable) {
            if (!$this->runtime->isDir($writable)) {
                continue;
            }
            // Own as the FPM pool user so SQLite/session writes work after Octane
            // (azerioid-supervised) owned the tree; keep azerioid-vhosts for group share.
            $this->runtime->exec(['/bin/chown', '-R', $phpUser . ':' . $phpGroup, $writable], null, 30);
            $this->runtime->exec(['/bin/chmod', '-R', 'ug+rwX', $writable], null, 30);
            // Best-effort SELinux rw label (no-op when chcon is absent / SELinux off).
            foreach (['/usr/bin/chcon', '/bin/chcon'] as $chcon) {
                if ($this->runtime->fileExists($chcon)) {
                    $this->runtime->exec([
                        $chcon, '-R', '-t', 'httpd_sys_rw_content_t', $writable,
                    ], null, 60);
                    break;
                }
            }
        }
    }

    private function installOctanePackage(string $appDir, string $php): void
    {
        if ($this->runtime->isDir($appDir . '/vendor/laravel/octane')) {
            return;
        }
        $composer = $this->composerBin();
        $composerHome = '/tmp/azerioid-octane-composer-' . getmypid();
        $this->runtime->mkdir($composerHome, 0750);
        $this->runtime->chown($composerHome, SupervisedUser::USERNAME, SupervisedUser::USERNAME);

        $result = $this->runAsSupervised([
            '/usr/bin/env',
            'COMPOSER_HOME=' . $composerHome,
            $php,
            $composer,
            'require',
            'laravel/octane',
            '--no-interaction',
        ], $appDir, 600);
        $this->runtime->exec(['/bin/rm', '-rf', $composerHome], null, 30);

        if (!$result->ok()) {
            throw new BrokerException('composer require laravel/octane failed: ' . self::execDetail($result), 1);
        }

        // Ensure the octane:* artisan commands are registered even if scripts were skipped.
        $discover = $this->runAsSupervised([$php, 'artisan', 'package:discover', '--ansi', '--no-interaction'], $appDir, 120);
        if (!$discover->ok()) {
            throw new BrokerException(
                'artisan package:discover failed after installing laravel/octane: ' . self::execDetail($discover),
                1
            );
        }
    }

    private function installFrankenPhpServer(string $appDir, string $php): void
    {
        $result = $this->runAsSupervised([
            $php,
            'artisan',
            'octane:install',
            '--server=frankenphp',
            '--no-interaction',
        ], $appDir, 600);
        if (!$result->ok()) {
            throw new BrokerException('artisan octane:install --server=frankenphp failed: ' . self::execDetail($result), 1);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function upsertProgram(SupervisorManager $supervisor, string $program, array $spec): void
    {
        foreach ($supervisor->listPrograms()['programs'] as $row) {
            if (($row['name'] ?? '') === $program) {
                $supervisor->update($program, $spec);

                return;
            }
        }
        $supervisor->create(array_merge(['name' => $program], $spec));
    }

    private function removeProgram(SupervisorManager $supervisor, string $program): bool
    {
        try {
            $supervisor->delete($program);

            return true;
        } catch (BrokerException) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private function programRow(string $program): ?array
    {
        try {
            return (new SupervisorManager($this->config, $this->runtime))->status($program);
        } catch (BrokerException) {
            return null;
        }
    }

    private function waitForPort(int $port, int $attempts = 30): bool
    {
        if ($this->ssBin() === null) {
            // Without iproute2 there is nothing to probe; Supervisor state is the only signal.
            return true;
        }
        for ($i = 0; $i < $attempts; $i++) {
            if ($this->portListening($port)) {
                return true;
            }
            $this->runtime->exec(['/bin/sleep', '1'], null, 5);
        }

        return false;
    }

    private function portListening(int $port): bool
    {
        $ss = $this->ssBin();
        if ($ss === null) {
            return false;
        }
        $result = $this->runtime->exec([$ss, '-H', '-tln', 'sport', '=', ':' . $port], null, 5);

        return $result->ok() && trim($result->stdout) !== '';
    }

    private function ssBin(): ?string
    {
        foreach (['/usr/sbin/ss', '/usr/bin/ss', '/bin/ss'] as $ss) {
            if ($this->runtime->fileExists($ss)) {
                return $ss;
            }
        }

        return null;
    }

    /** @param  list<string>  $command */
    private function runAsSupervised(array $command, string $cwd, int $timeout): ExecResult
    {
        SupervisedUser::ensure($this->runtime);
        $runuser = $this->runuserBin();
        $shell = 'cd ' . escapeshellarg($cwd) . ' && exec ' . implode(' ', array_map('escapeshellarg', $command));

        return $this->runtime->exec(
            [$runuser, '-u', SupervisedUser::USERNAME, '--', '/bin/bash', '-lc', $shell],
            null,
            $timeout
        );
    }

    private function runuserBin(): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException('runuser is required to run composer/artisan as the supervised user.', 1);
    }

    private function phpBin(string $version): string
    {
        $candidates = [];
        if (preg_match(Validator::PHP_VERSION_PATTERN, $version) === 1) {
            $candidates[] = '/usr/bin/php' . $version;
        }
        $candidates[] = '/usr/bin/php';
        foreach ($candidates as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException('PHP CLI binary not found for Octane (looked for ' . implode(', ', $candidates) . ').', 1);
    }

    private function composerBin(): string
    {
        foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException('composer binary not found; install Composer before enabling Octane.', 1);
    }

    private static function execDetail(ExecResult $result): string
    {
        $detail = trim($result->stderr . "\n" . $result->stdout);
        if (strlen($detail) > 400) {
            $detail = substr($detail, -400);
        }

        return $detail;
    }
}
