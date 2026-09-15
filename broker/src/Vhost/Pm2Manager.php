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
 * Per-vhost, opt-in PM2 (Node cluster) runtime (ADR A16/A37).
 *
 * Uses pm2-runtime under Supervisor (not the daemonizing pm2 CLI) so Supervisor
 * remains the outer process manager. Caddy reverse-proxies to the loopback port.
 */
final class Pm2Manager
{
    public const RUNTIME_PM2 = AppRuntime::PM2;

    /** Loopback range reserved for PM2 workers (docs/port-ownership.md). */
    public const PORT_MIN = 36000;
    public const PORT_MAX = 36999;

    public const DEFAULT_INSTANCES = 1;
    public const MIN_INSTANCES = 1;
    public const MAX_INSTANCES = 32;

    public const PROGRAM_PREFIX = 'pm2-';
    public const DOCS_URL = 'https://pm2.io/docs/runtime/integration/docker/';
    public const CLUSTER_DOCS_URL = 'https://pm2.keymetrics.io/docs/usage/cluster-mode/';

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
        $node = self::detectNodeApp($this->runtime, $vhost['root'] ?? null);
        $program = self::programName($domain);

        return [
            'domain' => $domain,
            'runtime' => AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM),
            'pm2_port' => $vhost['pm2_port'] ?? null,
            'pm2_instances' => $vhost['pm2_instances'] ?? null,
            'pm2_program' => $program,
            'pm2_entry' => $vhost['pm2_entry'] ?? ($node['entry'] ?? null),
            'program' => $this->programRow($program),
            'node_app' => $node['node'],
            'node_app_detail' => $node['detail'],
            'app_dir' => $node['app_dir'],
            'docs_url' => self::DOCS_URL,
            'cluster_docs_url' => self::CLUSTER_DOCS_URL,
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
        $this->assertNodeComponentInstalled();

        $vhost = $this->assertPm2Candidate($domain);
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) === self::RUNTIME_PM2) {
            throw new BrokerException("PM2 is already enabled for {$domain}.", 3);
        }

        $detected = $this->assertNodeApp($domain, $vhost['root'] ?? null, $input['entry'] ?? null);
        $instances = $this->resolveInstances($input['instances'] ?? self::DEFAULT_INSTANCES);
        $reserved = $this->reservedPorts($domain);
        if (array_key_exists('port', $input) && $input['port'] !== null && $input['port'] !== '') {
            $port = self::validatePort($input['port']);
            if (in_array($port, $reserved, true)) {
                throw new BrokerException("Port {$port} is already used by another PM2 vhost.", 3);
            }
        } else {
            $port = $this->allocatePort($reserved);
        }

        $this->ensurePm2Installed();
        $pm2Runtime = $this->pm2RuntimeBin();
        $pm2Home = $this->ensurePm2Home($domain);
        $this->writeRestoreMeta($domain, $vhost);

        $program = self::programName($domain);
        $appName = self::appName($domain);
        $this->upsertProgram($supervisor, $program, [
            'command' => $this->pm2Command($pm2Runtime, $pm2Home, $port, $appName, $instances, $detected),
            'directory' => $detected['app_dir'],
            'vhost_domain' => $domain,
            'autostart' => true,
            'autorestart' => true,
        ]);
        $supervisor->control($program, 'start');

        if (!$this->waitForPort($port)) {
            $this->removeProgram($supervisor, $program);
            throw new BrokerException(
                "PM2 did not start listening on 127.0.0.1:{$port}. Check the process logs under "
                . SupervisedUser::LOG_DIR . '/' . $program . '.stderr.log — the vhost was left unchanged. '
                . 'Ensure the app binds to process.env.PORT (and preferably 127.0.0.1).',
                1
            );
        }

        try {
            $applied = WebServers::for($this->config)->updateVhost($this->runtime, $this->config, $domain, [
                'type' => 'proxy',
                'runtime' => self::RUNTIME_PM2,
                'pm2_port' => $port,
                'pm2_instances' => $instances,
                'pm2_entry' => $detected['entry_label'],
                'upstream' => '127.0.0.1:' . $port,
                'root' => (string) ($vhost['root'] ?? $detected['app_dir']),
            ]);
        } catch (\Throwable $e) {
            $this->removeProgram($supervisor, $program);
            throw $e instanceof BrokerException
                ? new BrokerException($e->getMessage() . ' PM2 was rolled back; the vhost was left unchanged.', $e->errorCode)
                : new BrokerException($e->getMessage(), 1);
        }

        return [
            'domain' => $domain,
            'enabled' => true,
            'runtime' => self::RUNTIME_PM2,
            'pm2_port' => $port,
            'pm2_instances' => $instances,
            'pm2_program' => $program,
            'pm2_entry' => $detected['entry_label'],
            'app_dir' => $detected['app_dir'],
            'apply' => $applied['apply'] ?? null,
            'docs_url' => self::DOCS_URL,
            'cluster_docs_url' => self::CLUSTER_DOCS_URL,
        ];
    }

    /** @return array<string, mixed> */
    public function disable(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== self::RUNTIME_PM2) {
            throw new BrokerException("PM2 is not enabled for {$domain}.", 3);
        }

        $restore = $this->readRestoreMeta($domain);
        $changes = [
            'runtime' => AppRuntime::FPM,
            'pm2_port' => null,
            'pm2_instances' => null,
            'pm2_entry' => null,
        ];
        $prevType = (string) ($restore['type'] ?? 'static');
        if ($prevType === 'proxy') {
            $changes['type'] = 'proxy';
            $upstream = (string) ($restore['upstream'] ?? '');
            if ($upstream !== '' && !str_starts_with($upstream, '127.0.0.1:')) {
                $changes['upstream'] = $upstream;
            } else {
                // No useful prior upstream — fall back to static file_server on the docroot.
                $changes['type'] = 'static';
                $changes['upstream'] = null;
            }
        } else {
            $changes['type'] = $prevType === 'php' ? 'static' : $prevType;
            $changes['upstream'] = null;
        }
        if (!empty($restore['root'])) {
            $changes['root'] = (string) $restore['root'];
        } elseif (!empty($vhost['root'])) {
            $changes['root'] = (string) $vhost['root'];
        }

        $applied = WebServers::for($this->config)->updateVhost($this->runtime, $this->config, $domain, $changes);

        $program = self::programName($domain);
        $removed = $this->removeProgram(new SupervisorManager($this->config, $this->runtime), $program);
        $this->cleanupPm2Home($domain);

        return [
            'domain' => $domain,
            'disabled' => true,
            'runtime' => AppRuntime::FPM,
            'restored_type' => $changes['type'],
            'pm2_program' => $program,
            'program_removed' => $removed,
            'apply' => $applied['apply'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function reload(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== self::RUNTIME_PM2) {
            throw new BrokerException("PM2 is not enabled for {$domain}.", 3);
        }

        $program = self::programName($domain);
        $appName = self::appName($domain);
        $pm2Home = $this->pm2HomePath($domain);
        $pm2 = $this->pm2Bin();

        $reload = $this->runAsSupervised(
            ['/usr/bin/env', 'PM2_HOME=' . $pm2Home, $pm2, 'reload', $appName],
            (string) ($vhost['root'] ?? '/tmp'),
            120
        );
        if ($reload->ok()) {
            return [
                'domain' => $domain,
                'reloaded' => true,
                'method' => 'pm2-reload',
                'pm2_program' => $program,
                'output' => self::execDetail($reload),
            ];
        }

        $supervisor = new SupervisorManager($this->config, $this->runtime);
        $restart = $supervisor->control($program, 'restart');
        $port = (int) ($vhost['pm2_port'] ?? 0);
        if ($port > 0 && !$this->waitForPort($port)) {
            throw new BrokerException(
                'PM2 reload failed and Supervisor restart did not restore the listen port: '
                . self::execDetail($reload),
                1
            );
        }

        return [
            'domain' => $domain,
            'reloaded' => true,
            'method' => 'supervisor-restart',
            'pm2_program' => $program,
            'output' => (string) ($restart['output'] ?? ''),
            'fallback_reason' => self::execDetail($reload),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function scale(string $domain, array $input = []): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== self::RUNTIME_PM2) {
            throw new BrokerException("PM2 is not enabled for {$domain}.", 3);
        }

        $instances = $this->resolveInstances($input['instances'] ?? null);
        $program = self::programName($domain);
        $appName = self::appName($domain);
        $pm2Home = $this->pm2HomePath($domain);
        $pm2 = $this->pm2Bin();
        $appDir = (string) ($vhost['root'] ?? '/tmp');

        $scale = $this->runAsSupervised(
            ['/usr/bin/env', 'PM2_HOME=' . $pm2Home, $pm2, 'scale', $appName, (string) $instances],
            $appDir,
            180
        );
        if (!$scale->ok()) {
            throw new BrokerException('pm2 scale failed: ' . self::execDetail($scale), 1);
        }

        $applied = WebServers::for($this->config)->updateVhost($this->runtime, $this->config, $domain, [
            'runtime' => self::RUNTIME_PM2,
            'pm2_port' => (int) ($vhost['pm2_port'] ?? 0),
            'pm2_instances' => $instances,
            'pm2_entry' => $vhost['pm2_entry'] ?? null,
            'upstream' => '127.0.0.1:' . (int) ($vhost['pm2_port'] ?? 0),
            'type' => 'proxy',
            'root' => $vhost['root'] ?? null,
        ]);

        return [
            'domain' => $domain,
            'scaled' => true,
            'pm2_instances' => $instances,
            'pm2_program' => $program,
            'output' => self::execDetail($scale),
            'apply' => $applied['apply'] ?? null,
        ];
    }

    public static function validatePort(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1)) {
            throw new BrokerException('PM2 port must be a number.', 2);
        }
        $port = (int) $value;
        if ($port < self::PORT_MIN || $port > self::PORT_MAX) {
            throw new BrokerException(
                'PM2 port must be between ' . self::PORT_MIN . ' and ' . self::PORT_MAX . '.',
                2
            );
        }

        return $port;
    }

    public static function validateInstances(mixed $value): int
    {
        if ($value === null || $value === '') {
            throw new BrokerException('instances is required.', 2);
        }
        $raw = is_string($value) ? strtolower(trim($value)) : $value;
        if ($raw === 'max') {
            // Resolved at enable/scale time via nproc when a Runtime is available; here accept
            // the literal by mapping to a conservative multi-core default for validation-only paths.
            return min(self::MAX_INSTANCES, 2);
        }
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d{1,3}$/', $raw) === 1)) {
            throw new BrokerException('instances must be a number or "max".', 2);
        }
        $n = (int) $raw;
        if ($n < self::MIN_INSTANCES || $n > self::MAX_INSTANCES) {
            throw new BrokerException(
                'instances must be between ' . self::MIN_INSTANCES . ' and ' . self::MAX_INSTANCES . '.',
                2
            );
        }

        return $n;
    }

    public static function programName(string $domain): string
    {
        $slug = self::domainSlug($domain);
        $name = self::PROGRAM_PREFIX . $slug;
        if (strlen($name) > 49) {
            $hash = substr(hash('crc32b', $domain), 0, 8);
            $name = substr(self::PROGRAM_PREFIX . $hash . '-' . $slug, 0, 49);
            $name = rtrim($name, '-');
        }

        return Validator::supervisorProgramName($name);
    }

    public static function appName(string $domain): string
    {
        // PM2 app names are looser than Supervisor program names; keep them stable and short.
        $slug = self::domainSlug($domain);
        if (strlen($slug) > 40) {
            $slug = substr(hash('crc32b', $domain), 0, 8) . '-' . substr($slug, 0, 31);
        }

        return $slug;
    }

    /**
     * @return array{node:bool, app_dir:?string, entry:?string, detail:string}
     */
    public static function detectNodeApp(Runtime $runtime, ?string $root, mixed $entryHint = null): array
    {
        $root = is_string($root) ? rtrim(trim($root), '/') : '';
        if ($root === '') {
            return ['node' => false, 'app_dir' => null, 'entry' => null, 'detail' => 'Vhost has no document root.'];
        }

        $appDir = $root;
        // Common layout: public/ static + package.json in parent.
        if (!$runtime->fileExists($appDir . '/package.json') && basename($appDir) === 'public') {
            $parent = dirname($appDir);
            if ($parent !== '' && $parent !== '/' && $runtime->fileExists($parent . '/package.json')) {
                $appDir = $parent;
            }
        }

        $hint = is_string($entryHint) ? trim($entryHint) : '';
        if ($hint !== '') {
            if (str_contains($hint, '..') || str_starts_with($hint, '/')) {
                return ['node' => false, 'app_dir' => $appDir, 'entry' => null, 'detail' => 'Entry must be a relative path inside the app directory.'];
            }
            $full = $appDir . '/' . ltrim($hint, './');
            if (!$runtime->fileExists($full)) {
                return ['node' => false, 'app_dir' => $appDir, 'entry' => null, 'detail' => 'Entry file not found: ' . $hint];
            }

            return ['node' => true, 'app_dir' => $appDir, 'entry' => $hint, 'detail' => 'Using operator-supplied entry ' . $hint . '.'];
        }

        if ($runtime->fileExists($appDir . '/package.json')) {
            try {
                $decoded = json_decode($runtime->readFile($appDir . '/package.json'), true);
            } catch (\Throwable) {
                $decoded = null;
            }
            if (is_array($decoded) && is_array($decoded['scripts'] ?? null) && isset($decoded['scripts']['start'])) {
                return [
                    'node' => true,
                    'app_dir' => $appDir,
                    'entry' => 'npm:start',
                    'detail' => 'package.json defines scripts.start.',
                ];
            }
            $main = is_array($decoded) && is_string($decoded['main'] ?? null) ? trim((string) $decoded['main']) : '';
            if ($main !== '' && !str_contains($main, '..') && $runtime->fileExists($appDir . '/' . ltrim($main, './'))) {
                return [
                    'node' => true,
                    'app_dir' => $appDir,
                    'entry' => ltrim($main, './'),
                    'detail' => 'package.json main: ' . $main . '.',
                ];
            }
        }

        foreach (['server.js', 'app.js', 'index.js'] as $candidate) {
            if ($runtime->fileExists($appDir . '/' . $candidate)) {
                return [
                    'node' => true,
                    'app_dir' => $appDir,
                    'entry' => $candidate,
                    'detail' => 'Found ' . $candidate . '.',
                ];
            }
        }

        return [
            'node' => false,
            'app_dir' => $appDir,
            'entry' => null,
            'detail' => 'No Node entry found (need package.json scripts.start, main, or server.js/app.js/index.js).',
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
            'No free PM2 port available in ' . self::PORT_MIN . '-' . self::PORT_MAX . '.',
            1
        );
    }

    private static function domainSlug(string $domain): string
    {
        $slug = strtolower(trim($domain));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        if ($slug === '') {
            throw new BrokerException('Cannot derive a PM2 name for this domain.', 2);
        }

        return $slug;
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
    private function assertPm2Candidate(string $domain): array
    {
        $vhost = $this->findVhost($domain);
        if (!empty($vhost['readonly'])) {
            throw new BrokerException('This vhost is managed externally and cannot be switched to PM2.', 3);
        }
        $type = (string) ($vhost['type'] ?? '');
        if ($type === 'php') {
            throw new BrokerException(
                'PM2 is for Node apps. This is a PHP vhost — use Octane for Laravel, or create a separate Node/proxy site.',
                3
            );
        }
        if (!in_array($type, ['proxy', 'static'], true)) {
            throw new BrokerException('PM2 is only available for proxy or static vhosts with a Node entrypoint.', 3);
        }
        $engine = VhostEngine::normalize($vhost['engine'] ?? VhostEngine::CADDY);
        if (VhostEngine::isBackend($engine)) {
            throw new BrokerException(
                'PM2 requires the Caddy engine. Switch ' . $domain . ' to engine=caddy first.',
                3
            );
        }
        if ((string) ($vhost['root'] ?? '') === '') {
            throw new BrokerException('Vhost has no document root; set a root before enabling PM2.', 2);
        }

        return $vhost;
    }

    /**
     * @return array{app_dir:string, entry:string, entry_label:string, mode:string}
     */
    private function assertNodeApp(string $domain, ?string $root, mixed $entryHint): array
    {
        $detected = self::detectNodeApp($this->runtime, $root, $entryHint);
        if (!$detected['node'] || $detected['app_dir'] === null || $detected['entry'] === null) {
            throw new BrokerException(
                "{$domain} does not look like a Node application, so PM2 cannot run it. "
                . $detected['detail'],
                3
            );
        }
        $entry = $detected['entry'];
        if ($entry === 'npm:start') {
            return [
                'app_dir' => $detected['app_dir'],
                'entry' => 'npm',
                'entry_label' => 'npm start',
                'mode' => 'npm',
            ];
        }

        return [
            'app_dir' => $detected['app_dir'],
            'entry' => $entry,
            'entry_label' => $entry,
            'mode' => 'script',
        ];
    }

    /** @return list<int> */
    private function reservedPorts(string $exceptDomain): array
    {
        $ports = [];
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            if (($vhost['domain'] ?? '') === $exceptDomain) {
                continue;
            }
            $port = (int) ($vhost['pm2_port'] ?? 0);
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    /**
     * @param  array{app_dir:string, entry:string, entry_label:string, mode:string}  $detected
     */
    private function pm2Command(
        string $pm2Runtime,
        string $pm2Home,
        int $port,
        string $appName,
        int $instances,
        array $detected,
    ): string {
        $parts = [
            '/usr/bin/env',
            'PM2_HOME=' . $pm2Home,
            'PORT=' . $port,
            'HOST=127.0.0.1',
            'NODE_ENV=production',
            $pm2Runtime,
            'start',
        ];
        if ($detected['mode'] === 'npm') {
            $parts[] = 'npm';
            $parts[] = '--name';
            $parts[] = $appName;
            $parts[] = '-i';
            $parts[] = (string) $instances;
            $parts[] = '--';
            $parts[] = 'start';
        } else {
            $parts[] = $detected['entry'];
            $parts[] = '--name';
            $parts[] = $appName;
            $parts[] = '-i';
            $parts[] = (string) $instances;
        }

        return Validator::supervisorCommand(implode(' ', $parts));
    }

    private function assertNodeComponentInstalled(): void
    {
        $node = $this->nodeBin();
        if ($node === null) {
            throw new BrokerException(
                'Node.js is not installed. Install the Node.js component from Components first.',
                3
            );
        }
    }

    private function ensurePm2Installed(): void
    {
        if ($this->pm2RuntimeBinIfPresent() !== null && $this->pm2BinIfPresent() !== null) {
            return;
        }
        $npm = $this->npmBin();
        if ($npm === null) {
            throw new BrokerException('npm is required to install PM2 globally.', 1);
        }
        $result = $this->runtime->exec([$npm, 'install', '-g', 'pm2@7'], null, 300);
        if (!$result->ok() || $this->pm2RuntimeBinIfPresent() === null) {
            throw new BrokerException('npm install -g pm2 failed: ' . self::execDetail($result), 1);
        }
    }

    private function resolveInstances(mixed $value): int
    {
        if (is_string($value) && strtolower(trim($value)) === 'max') {
            $nproc = $this->runtime->exec(['/usr/bin/nproc'], null, 5);
            $n = $nproc->ok() ? (int) trim($nproc->stdout) : 0;
            if ($n < 1) {
                $n = 2;
            }

            return min(self::MAX_INSTANCES, max(self::MIN_INSTANCES, $n));
        }

        return self::validateInstances($value);
    }

    private function pm2HomePath(string $domain): string
    {
        return SupervisedUser::HOME . '/pm2/' . self::domainSlug($domain);
    }

    private function ensurePm2Home(string $domain): string
    {
        SupervisedUser::ensure($this->runtime);
        $base = SupervisedUser::HOME . '/pm2';
        $home = $this->pm2HomePath($domain);
        foreach ([$base, $home, $home . '/logs', $home . '/pids', $home . '/modules'] as $dir) {
            if (!$this->runtime->isDir($dir)) {
                $this->runtime->mkdir($dir, 0750);
            }
            if ($this->runtime->getuid() === 0) {
                $this->runtime->chown($dir, SupervisedUser::USERNAME, SupervisedUser::USERNAME);
                $this->runtime->chmod($dir, 0750);
            }
        }
        if ($this->runtime->getuid() === 0) {
            $this->runtime->exec([
                '/bin/chown', '-R',
                SupervisedUser::USERNAME . ':' . SupervisedUser::USERNAME,
                $home,
            ], null, 30);
        }

        return $home;
    }

    private function cleanupPm2Home(string $domain): void
    {
        $home = $this->pm2HomePath($domain);
        if ($this->runtime->isDir($home)) {
            $this->runtime->exec(['/bin/rm', '-rf', $home], null, 30);
        }
        $meta = $this->metaPath($domain);
        if ($this->runtime->fileExists($meta)) {
            $this->runtime->deleteFile($meta);
        }
    }

    private function metaPath(string $domain): string
    {
        $dir = '/var/lib/azerioid-panel/pm2-meta';
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0750);
        }

        return $dir . '/' . self::domainSlug($domain) . '.json';
    }

    /** @param  array<string, mixed>  $vhost */
    private function writeRestoreMeta(string $domain, array $vhost): void
    {
        $payload = json_encode([
            'type' => (string) ($vhost['type'] ?? 'static'),
            'root' => (string) ($vhost['root'] ?? ''),
            'upstream' => (string) ($vhost['reverse_proxy'] ?? ''),
        ], JSON_THROW_ON_ERROR);
        $path = $this->metaPath($domain);
        $this->runtime->writeFile($path, $payload . "\n", 0640);
    }

    /** @return array{type?:string, root?:string, upstream?:string} */
    private function readRestoreMeta(string $domain): array
    {
        $path = $this->metaPath($domain);
        if (!$this->runtime->fileExists($path)) {
            return [];
        }
        try {
            $decoded = json_decode($this->runtime->readFile($path), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
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

    private function waitForPort(int $port, int $attempts = 40): bool
    {
        if ($this->ssBin() === null) {
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

        throw new BrokerException('runuser is required to run pm2 as the supervised user.', 1);
    }

    private function nodeBin(): ?string
    {
        foreach (['/usr/bin/node', '/usr/local/bin/node'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function npmBin(): ?string
    {
        foreach (['/usr/bin/npm', '/usr/local/bin/npm'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function pm2RuntimeBin(): string
    {
        $bin = $this->pm2RuntimeBinIfPresent();
        if ($bin === null) {
            throw new BrokerException('pm2-runtime binary not found after install.', 1);
        }

        return $bin;
    }

    private function pm2RuntimeBinIfPresent(): ?string
    {
        foreach (['/usr/bin/pm2-runtime', '/usr/local/bin/pm2-runtime'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function pm2Bin(): string
    {
        $bin = $this->pm2BinIfPresent();
        if ($bin === null) {
            throw new BrokerException('pm2 binary not found after install.', 1);
        }

        return $bin;
    }

    private function pm2BinIfPresent(): ?string
    {
        foreach (['/usr/bin/pm2', '/usr/local/bin/pm2'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private static function execDetail(ExecResult $result): string
    {
        $detail = trim($result->stderr . "\n" . $result->stdout);
        // PM2 7.x paints a live metrics footer with ANSI escapes (and sometimes
        // non-UTF8 bytes). Strip CSI sequences so the broker JSON stays clean.
        $detail = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $detail) ?? $detail;
        if (function_exists('mb_scrub')) {
            $detail = mb_scrub($detail, 'UTF-8');
        } elseif (!mb_check_encoding($detail, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $detail);
            $detail = is_string($converted) ? $converted : '';
        }
        if (strlen($detail) > 400) {
            $detail = substr($detail, -400);
        }

        return $detail;
    }
}
