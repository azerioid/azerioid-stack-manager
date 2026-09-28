<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\DockerRootlessSetup;
use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Supervisor\SupervisorManager;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Web\VhostEngine;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Per-vhost, opt-in Docker (rootless) runtime (ADR A38).
 *
 * Rootless dockerd runs as azerioid-supervised; Supervisor programs call docker/compose
 * with DOCKER_HOST and publish only 127.0.0.1:HOST→INTERNAL. Caddy reverse-proxies.
 */
final class DockerManager
{
    public const RUNTIME_DOCKER = AppRuntime::DOCKER;

    /** Loopback range reserved for Docker containers (docs/port-ownership.md). */
    public const PORT_MIN = 37000;
    public const PORT_MAX = 37999;

    public const PROGRAM_PREFIX = 'docker-';
    public const DOCS_URL = DockerRootlessSetup::DOCS_URL;

    public const MODE_IMAGE = 'image';
    public const MODE_COMPOSE = 'compose';
    public const MODE_DOCKERFILE = 'dockerfile';

    public const DEFAULT_COMPOSE = 'docker-compose.yml';
    public const DEFAULT_DOCKERFILE = 'Dockerfile';

    /** Installed by DockerRootlessSetup / deploy broker-setup for container shell (ttyd). */
    public const DOCKER_EXEC_WRAPPER = '/usr/local/lib/azerioid-panel/sbin/azerioid-docker-exec';

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
        $detected = self::detectDockerApp($this->runtime, $vhost['root'] ?? null);
        $program = self::programName($domain);
        $container = $this->containerSnapshot($domain, $vhost);

        return [
            'domain' => $domain,
            'runtime' => AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM),
            'docker_port' => $vhost['docker_port'] ?? null,
            'docker_internal_port' => $vhost['docker_internal_port'] ?? null,
            'docker_mode' => $vhost['docker_mode'] ?? null,
            'docker_image' => $vhost['docker_image'] ?? null,
            'docker_compose' => $vhost['docker_compose'] ?? null,
            'docker_dockerfile' => $vhost['docker_dockerfile'] ?? null,
            'docker_program' => $program,
            'program' => $this->programRow($program),
            'container' => $container,
            'docker_app' => $detected['docker'],
            'docker_app_detail' => $detected['detail'],
            'app_dir' => $detected['app_dir'],
            'docs_url' => self::DOCS_URL,
            'settings' => DockerSettings::load($this->runtime, $domain),
            'env_keys' => array_keys(DockerSettings::loadEnv($this->runtime, $domain)),
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
        $this->assertDockerComponentInstalled();

        $vhost = $this->assertDockerCandidate($domain);
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) === self::RUNTIME_DOCKER) {
            throw new BrokerException("Docker is already enabled for {$domain}.", 3);
        }

        $spec = $this->resolveEnableSpec($domain, $vhost, $input);
        $this->writeRestoreMeta($domain, $vhost);
        $this->storeSettings($domain, $spec);
        if (array_key_exists('env', $input)) {
            DockerSettings::saveEnv($this->runtime, $domain, DockerSettings::validateEnv($input['env']));
        }
        try {
            $this->ensureVolumeDirs($spec);
            $this->ensureDaemonReaches($domain);
            $this->prepareWorkload($spec);
        } catch (\Throwable $e) {
            $this->cleanupMeta($domain);
            throw $e;
        }

        $program = self::programName($domain);
        $this->upsertProgram($supervisor, $program, [
            'command' => $this->runCommand($spec),
            'directory' => $spec['app_dir'],
            'vhost_domain' => $domain,
            'autostart' => true,
            'autorestart' => DockerSettings::autorestart($spec['restart']),
        ]);
        $supervisor->control($program, 'start');

        if (!$this->waitForPort($spec['port'])) {
            $this->removeProgram($supervisor, $program);
            $this->cleanupContainers($spec);
            throw new BrokerException(
                "Docker did not start listening on 127.0.0.1:{$spec['port']}. Check the process logs under "
                . SupervisedUser::LOG_DIR . '/' . $program . '.stderr.log — the vhost was left unchanged.',
                1
            );
        }

        try {
            $applied = WebServers::for($this->config)->updateVhost($this->runtime, $this->config, $domain, [
                'type' => 'proxy',
                'runtime' => self::RUNTIME_DOCKER,
                'docker_port' => $spec['port'],
                'docker_internal_port' => $spec['internal_port'],
                'docker_mode' => $spec['mode'],
                'docker_image' => $spec['image'],
                'docker_compose' => $spec['compose'],
                'docker_dockerfile' => $spec['dockerfile'],
                'upstream' => '127.0.0.1:' . $spec['port'],
                'root' => (string) ($vhost['root'] ?? $spec['app_dir']),
            ]);
        } catch (\Throwable $e) {
            $this->removeProgram($supervisor, $program);
            $this->cleanupContainers($spec);
            throw $e instanceof BrokerException
                ? new BrokerException($e->getMessage() . ' Docker was rolled back; the vhost was left unchanged.', $e->errorCode)
                : new BrokerException($e->getMessage(), 1);
        }

        return [
            'domain' => $domain,
            'enabled' => true,
            'runtime' => self::RUNTIME_DOCKER,
            'docker_port' => $spec['port'],
            'docker_internal_port' => $spec['internal_port'],
            'docker_mode' => $spec['mode'],
            'docker_image' => $spec['image'],
            'docker_compose' => $spec['compose'],
            'docker_dockerfile' => $spec['dockerfile'],
            'docker_program' => $program,
            'docker_service' => $spec['service'],
            'app_dir' => $spec['app_dir'],
            'apply' => $applied['apply'] ?? null,
            'docs_url' => self::DOCS_URL,
        ];
    }

    /** @return array<string, mixed> */
    public function disable(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== self::RUNTIME_DOCKER) {
            throw new BrokerException("Docker is not enabled for {$domain}.", 3);
        }

        $restore = $this->readRestoreMeta($domain);
        $changes = [
            'runtime' => AppRuntime::FPM,
            'docker_port' => null,
            'docker_internal_port' => null,
            'docker_mode' => null,
            'docker_image' => null,
            'docker_compose' => null,
            'docker_dockerfile' => null,
        ];
        $prevType = (string) ($restore['type'] ?? 'static');
        if ($prevType === 'proxy') {
            $changes['type'] = 'proxy';
            $upstream = (string) ($restore['upstream'] ?? '');
            if ($upstream !== '' && !str_starts_with($upstream, '127.0.0.1:')) {
                $changes['upstream'] = $upstream;
            } else {
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
        $spec = $this->specFromVhost($domain, $vhost);
        $removed = $this->removeProgram(new SupervisorManager($this->config, $this->runtime), $program);
        $this->cleanupContainers($spec);
        $this->cleanupMeta($domain);

        return [
            'domain' => $domain,
            'disabled' => true,
            'runtime' => AppRuntime::FPM,
            'restored_type' => $changes['type'],
            'docker_program' => $program,
            'program_removed' => $removed,
            'apply' => $applied['apply'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function build(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->requireEnabled($domain);
        $spec = $this->specFromVhost($domain, $vhost);
        $this->ensureVolumeDirs($spec);
        $this->ensureDaemonReaches($domain);
        $this->prepareWorkload($spec, true);

        $supervisor = new SupervisorManager($this->config, $this->runtime);
        $program = self::programName($domain);
        $this->upsertProgram($supervisor, $program, [
            'command' => $this->runCommand($spec),
            'directory' => $spec['app_dir'],
            'vhost_domain' => $domain,
            'autostart' => true,
            'autorestart' => DockerSettings::autorestart($spec['restart']),
        ]);
        $restart = $supervisor->control($program, 'restart');
        if (!$this->waitForPort((int) $spec['port'])) {
            throw new BrokerException(
                'Docker rebuild finished but 127.0.0.1:' . $spec['port'] . ' is not listening.',
                1
            );
        }

        return [
            'domain' => $domain,
            'built' => true,
            'docker_mode' => $spec['mode'],
            'docker_program' => $program,
            'output' => (string) ($restart['output'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function restart(string $domain): array
    {
        $domain = Validator::domain($domain);
        $this->requireEnabled($domain);
        $program = self::programName($domain);
        $restart = (new SupervisorManager($this->config, $this->runtime))->control($program, 'restart');

        return [
            'domain' => $domain,
            'restarted' => true,
            'docker_program' => $program,
            'output' => (string) ($restart['output'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function logs(string $domain, array $input = []): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->requireEnabled($domain);
        $spec = $this->specFromVhost($domain, $vhost);
        $lines = self::validateLogLines($input['lines'] ?? 100);
        $docker = $this->dockerBin();
        $env = $this->dockerEnvAssign();

        if ($spec['mode'] === self::MODE_COMPOSE) {
            $cmd = [
                '/usr/bin/env', $env, $docker, 'compose',
                '-f', $spec['compose'],
                '-f', $this->portsOverridePath($domain),
                '-p', self::composeProject($domain),
                'logs', '--no-color', '--tail', (string) $lines,
            ];
        } else {
            $cmd = [
                '/usr/bin/env', $env, $docker, 'logs',
                '--tail', (string) $lines,
                self::containerName($domain),
            ];
        }
        $result = $this->runAsSupervised($cmd, $spec['app_dir'], 60);

        return [
            'domain' => $domain,
            'lines' => $lines,
            'output' => self::execDetail($result, 8000),
            'ok' => $result->ok(),
        ];
    }

    /**
     * Validate that an image reference exists (manifest inspect; no layer pull when possible).
     *
     * @return array{ok:bool, exists:bool, image:string, detail:string}
     */
    public function validateRemoteImage(string $image, ?string $registry = null): array
    {
        $this->assertDockerComponentInstalled();
        $image = self::validateImage($image);
        $registry = $this->validateRegistryRef($registry);
        if ($registry !== null) {
            return $this->withRegistry($registry, function (array $auth) use ($image): array {
                $inspect = $this->runAsSupervised(
                    array_merge(['/usr/bin/env', $this->dockerEnvAssign()], $auth, [$this->dockerBin(), 'manifest', 'inspect', $image]),
                    '/tmp',
                    60
                );

                return [
                    'ok' => true,
                    'exists' => $inspect->ok(),
                    'image' => $image,
                    'detail' => $inspect->ok() ? 'manifest inspect ok (authenticated)' : self::execDetail($inspect, 400),
                ];
            });
        }
        $docker = $this->dockerBin();
        $env = $this->dockerEnvAssign();
        $manifest = $this->runAsSupervised(
            ['/usr/bin/env', $env, $docker, 'manifest', 'inspect', $image],
            '/tmp',
            60
        );
        if ($manifest->ok()) {
            return [
                'ok' => true,
                'exists' => true,
                'image' => $image,
                'detail' => 'manifest inspect ok',
            ];
        }
        $buildx = $this->runAsSupervised(
            ['/usr/bin/env', $env, $docker, 'buildx', 'imagetools', 'inspect', $image],
            '/tmp',
            60
        );
        if ($buildx->ok()) {
            return [
                'ok' => true,
                'exists' => true,
                'image' => $image,
                'detail' => 'buildx imagetools inspect ok',
            ];
        }
        $detail = self::execDetail($manifest->ok() ? $buildx : $manifest, 400);
        if ($detail === '') {
            $detail = self::execDetail($buildx, 400);
        }

        return [
            'ok' => true,
            'exists' => false,
            'image' => $image,
            'detail' => $detail !== '' ? $detail : 'Image not found: ' . $image,
        ];
    }

    /**
     * Soft Docker Hub repository search (public API). Fail soft if Hub is unreachable.
     *
     * @return array{ok:bool, query:string, suggestions: list<array{repo_name:string}>, detail:?string}
     */
    public function searchImages(string $query): array
    {
        $query = trim($query);
        if (strlen($query) < 2) {
            throw new BrokerException('query must be at least 2 characters.', 2);
        }
        if (strlen($query) > 128) {
            throw new BrokerException('query is too long.', 2);
        }
        $url = 'https://hub.docker.com/v2/search/repositories/?query='
            . rawurlencode($query) . '&page_size=8';
        $curl = $this->runtime->fileExists('/usr/bin/curl') ? '/usr/bin/curl' : null;
        if ($curl === null) {
            return [
                'ok' => false,
                'query' => $query,
                'suggestions' => [],
                'detail' => 'curl is not available for Docker Hub search.',
            ];
        }
        $result = $this->runtime->exec(
            [$curl, '-fsSL', '--max-time', '8', $url],
            null,
            15
        );
        if (!$result->ok()) {
            return [
                'ok' => false,
                'query' => $query,
                'suggestions' => [],
                'detail' => 'Docker Hub unreachable: ' . substr(trim($result->stderr . ' ' . $result->stdout), 0, 200),
            ];
        }
        $decoded = json_decode($result->stdout, true);
        $suggestions = [];
        foreach (is_array($decoded['results'] ?? null) ? $decoded['results'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['repo_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $suggestions[] = ['repo_name' => $name];
            if (count($suggestions) >= 8) {
                break;
            }
        }

        return [
            'ok' => true,
            'query' => $query,
            'suggestions' => $suggestions,
            'detail' => null,
        ];
    }

    /**
     * Resolve a running container for interactive shell (rootless).
     *
     * @return array{name:string, state:string, mode:string, domain:string}
     */
    public function resolveShellTarget(string $domain): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->requireEnabled($domain);
        $spec = $this->specFromVhost($domain, $vhost);
        $mode = $spec['mode'];

        if ($mode === self::MODE_COMPOSE) {
            $name = $this->resolveComposeRunningContainer($spec);
            if ($name === null) {
                throw new BrokerException(
                    "No running container found for compose project on {$domain}. Start/restart Docker first.",
                    3
                );
            }

            return [
                'name' => $name,
                'state' => 'running',
                'mode' => $mode,
                'domain' => $domain,
            ];
        }

        $snap = $this->containerSnapshot($domain, $vhost);
        $state = (string) ($snap['state'] ?? 'missing');
        if ($state !== 'running') {
            throw new BrokerException(
                "Container for {$domain} is not running (state={$state}). Start/restart Docker first.",
                3
            );
        }

        return [
            'name' => (string) ($snap['name'] ?? self::containerName($domain)),
            'state' => $state,
            'mode' => $mode,
            'domain' => $domain,
        ];
    }

    /**
     * Services a compose file defines, as compose itself resolves them (profiles, includes,
     * extends and interpolation included) — the reason no YAML parser of our own is used.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function services(string $domain, array $input = []): array
    {
        $domain = Validator::domain($domain);
        $this->assertDockerComponentInstalled();
        $vhost = $this->findVhost($domain);
        $detected = self::detectDockerApp($this->runtime, $vhost['root'] ?? null);
        $appDir = $detected['app_dir'] ?? rtrim((string) ($vhost['root'] ?? ''), '/');
        $compose = self::validateRelativePath(
            $input['compose'] ?? ($vhost['docker_compose'] ?? ($detected['compose'] ?? self::DEFAULT_COMPOSE)),
            'compose'
        );
        if (!$this->runtime->fileExists($appDir . '/' . $compose)) {
            throw new BrokerException("Compose file not found: {$compose}", 3);
        }

        return [
            'domain' => $domain,
            'compose' => $compose,
            'services' => $this->composeServices($appDir, $compose),
            'selected' => DockerSettings::load($this->runtime, $domain)['service'],
        ];
    }

    /** @return array<string, mixed> */
    public function settings(string $domain): array
    {
        $domain = Validator::domain($domain);
        $this->findVhost($domain);

        return [
            'domain' => $domain,
            'settings' => DockerSettings::load($this->runtime, $domain),
            'env_keys' => array_keys(DockerSettings::loadEnv($this->runtime, $domain)),
        ];
    }

    /**
     * Change the service, restart policy, volumes or registry, and apply them to a running
     * container. Values not given are kept.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateSettings(string $domain, array $input): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        $current = DockerSettings::load($this->runtime, $domain);
        $next = [
            'service' => array_key_exists('service', $input)
                ? ($input['service'] === null || $input['service'] === '' ? null : DockerSettings::validateService($input['service']))
                : $current['service'],
            'restart' => array_key_exists('restart', $input) ? DockerSettings::validateRestart($input['restart']) : $current['restart'],
            'volumes' => array_key_exists('volumes', $input) ? DockerSettings::validateVolumes($input['volumes']) : $current['volumes'],
            'registry' => array_key_exists('registry', $input) ? $this->validateRegistryRef($input['registry']) : $current['registry'],
        ];
        $enabled = AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) === self::RUNTIME_DOCKER;
        if ($enabled && $next['service'] !== null && ($vhost['docker_mode'] ?? '') === self::MODE_COMPOSE) {
            $spec = $this->specFromVhost($domain, $vhost);
            $services = $this->composeServices($spec['app_dir'], (string) $spec['compose']);
            if (!in_array($next['service'], $services, true)) {
                throw new BrokerException("Service {$next['service']} is not in the compose file (" . implode(', ', $services) . ').', 2);
            }
        }
        DockerSettings::save($this->runtime, $domain, $next);

        return [
            'domain' => $domain,
            'settings' => $next,
            'applied' => $enabled ? $this->reapply($domain) : false,
        ];
    }

    /** @return array<string, mixed> */
    public function env(string $domain): array
    {
        $domain = Validator::domain($domain);
        $this->findVhost($domain);

        return ['domain' => $domain, 'env' => DockerSettings::loadEnv($this->runtime, $domain)];
    }

    /**
     * Replace the container environment and restart the container with it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function setEnv(string $domain, array $input): array
    {
        $domain = Validator::domain($domain);
        $vhost = $this->findVhost($domain);
        $env = DockerSettings::validateEnv($input['env'] ?? []);
        DockerSettings::saveEnv($this->runtime, $domain, $env);
        $enabled = AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) === self::RUNTIME_DOCKER;

        return [
            'domain' => $domain,
            'env_keys' => array_keys($env),
            'applied' => $enabled ? $this->reapply($domain) : false,
        ];
    }

    /** Re-render what the container starts from and restart it. */
    private function reapply(string $domain): bool
    {
        $vhost = $this->requireEnabled($domain);
        $spec = $this->specFromVhost($domain, $vhost);
        $this->ensureVolumeDirs($spec);
        $this->ensureDaemonReaches($domain);
        if ($spec['mode'] === self::MODE_COMPOSE) {
            $this->writePortsOverride($spec);
        }
        $supervisor = new SupervisorManager($this->config, $this->runtime);
        $program = self::programName($domain);
        $this->upsertProgram($supervisor, $program, [
            'command' => $this->runCommand($spec),
            'directory' => $spec['app_dir'],
            'vhost_domain' => $domain,
            'autostart' => true,
            'autorestart' => DockerSettings::autorestart($spec['restart']),
        ]);
        $supervisor->control($program, 'restart');
        if (!$this->waitForPort((int) $spec['port'])) {
            throw new BrokerException(
                'Settings saved, but the container is not listening on 127.0.0.1:' . $spec['port']
                . ' after the restart. Check: azerioid vhost docker logs --domain=' . $domain,
                1
            );
        }

        return true;
    }

    public static function validatePort(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1)) {
            throw new BrokerException('Docker port must be a number.', 2);
        }
        $port = (int) $value;
        if ($port < self::PORT_MIN || $port > self::PORT_MAX) {
            throw new BrokerException(
                'Docker port must be between ' . self::PORT_MIN . ' and ' . self::PORT_MAX . '.',
                2
            );
        }

        return $port;
    }

    public static function validateInternalPort(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1)) {
            throw new BrokerException('internal_port must be a number.', 2);
        }
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new BrokerException('internal_port must be between 1 and 65535.', 2);
        }

        return $port;
    }

    public static function validateMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        if (!in_array($mode, [self::MODE_IMAGE, self::MODE_COMPOSE, self::MODE_DOCKERFILE], true)) {
            throw new BrokerException('mode must be image, compose, or dockerfile.', 2);
        }

        return $mode;
    }

    public static function validateLogLines(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 100;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1)) {
            throw new BrokerException('lines must be a number.', 2);
        }
        $n = (int) $value;
        if ($n < 1 || $n > 5000) {
            throw new BrokerException('lines must be between 1 and 5000.', 2);
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

    public static function containerName(string $domain): string
    {
        return self::programName($domain);
    }

    public static function composeProject(string $domain): string
    {
        return self::programName($domain);
    }

    /**
     * @return array{docker:bool, app_dir:?string, detail:string, compose:?string, dockerfile:?string}
     */
    public static function detectDockerApp(Runtime $runtime, ?string $root): array
    {
        $root = is_string($root) ? rtrim(trim($root), '/') : '';
        if ($root === '') {
            return [
                'docker' => false,
                'app_dir' => null,
                'detail' => 'Vhost has no document root.',
                'compose' => null,
                'dockerfile' => null,
            ];
        }

        $appDir = $root;
        if (basename($appDir) === 'public') {
            $parent = dirname($appDir);
            if ($parent !== '' && $parent !== '/' && (
                $runtime->fileExists($parent . '/Dockerfile')
                || $runtime->fileExists($parent . '/docker-compose.yml')
                || $runtime->fileExists($parent . '/compose.yaml')
            )) {
                $appDir = $parent;
            }
        }

        $compose = null;
        foreach ([self::DEFAULT_COMPOSE, 'compose.yaml', 'compose.yml', 'docker-compose.yaml'] as $candidate) {
            if ($runtime->fileExists($appDir . '/' . $candidate)) {
                $compose = $candidate;
                break;
            }
        }
        $dockerfile = $runtime->fileExists($appDir . '/' . self::DEFAULT_DOCKERFILE)
            ? self::DEFAULT_DOCKERFILE
            : null;

        if ($compose !== null || $dockerfile !== null) {
            $bits = array_filter([$compose !== null ? 'found ' . $compose : null, $dockerfile !== null ? 'found Dockerfile' : null]);

            return [
                'docker' => true,
                'app_dir' => $appDir,
                'detail' => implode('; ', $bits) . '.',
                'compose' => $compose,
                'dockerfile' => $dockerfile,
            ];
        }

        return [
            'docker' => false,
            'app_dir' => $appDir,
            'detail' => 'No Dockerfile or compose file found (image mode still works with an explicit image).',
            'compose' => null,
            'dockerfile' => null,
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
            'No free Docker port available in ' . self::PORT_MIN . '-' . self::PORT_MAX . '.',
            1
        );
    }

    /** True when any panel vhost still uses runtime=docker (blocks component uninstall). */
    public function anyDockerVhost(): bool
    {
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) === self::RUNTIME_DOCKER) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function dockerVhostDomains(): array
    {
        $domains = [];
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== self::RUNTIME_DOCKER) {
                continue;
            }
            $domain = (string) ($vhost['domain'] ?? '');
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    private static function domainSlug(string $domain): string
    {
        $slug = strtolower(trim($domain));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        if ($slug === '') {
            throw new BrokerException('Cannot derive a Docker name for this domain.', 2);
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
    private function assertDockerCandidate(string $domain): array
    {
        $vhost = $this->findVhost($domain);
        if (!empty($vhost['readonly'])) {
            throw new BrokerException('This vhost is managed externally and cannot be switched to Docker.', 3);
        }
        $type = (string) ($vhost['type'] ?? '');
        if ($type === 'php') {
            throw new BrokerException(
                'Docker is for containerized apps on proxy/static sites. This is a PHP vhost — use Octane for Laravel.',
                3
            );
        }
        if (!in_array($type, ['proxy', 'static'], true)) {
            throw new BrokerException('Docker is only available for proxy or static vhosts.', 3);
        }
        $engine = VhostEngine::normalize($vhost['engine'] ?? VhostEngine::CADDY);
        if (VhostEngine::isBackend($engine)) {
            throw new BrokerException(
                'Docker requires the Caddy engine. Switch ' . $domain . ' to engine=caddy first.',
                3
            );
        }
        if ((string) ($vhost['root'] ?? '') === '') {
            throw new BrokerException('Vhost has no document root; set a root before enabling Docker.', 2);
        }
        $current = AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM);
        if (in_array($current, [AppRuntime::PM2, AppRuntime::OCTANE], true)) {
            throw new BrokerException(
                "Cannot enable Docker while {$domain} uses runtime={$current}. Disable that runtime first.",
                3
            );
        }

        return $vhost;
    }

    /** @return array<string, mixed> */
    private function requireEnabled(string $domain): array
    {
        $vhost = $this->findVhost($domain);
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== self::RUNTIME_DOCKER) {
            throw new BrokerException("Docker is not enabled for {$domain}.", 3);
        }

        return $vhost;
    }

    /**
     * @param  array<string, mixed>  $vhost
     * @param  array<string, mixed>  $input
     * @return array{
     *   domain:string, mode:string, app_dir:string, port:int, internal_port:int,
     *   image:?string, compose:?string, dockerfile:?string
     * }
     */
    private function resolveEnableSpec(string $domain, array $vhost, array $input): array
    {
        $detected = self::detectDockerApp($this->runtime, $vhost['root'] ?? null);
        $appDir = $detected['app_dir'] ?? rtrim((string) ($vhost['root'] ?? ''), '/');
        $mode = self::validateMode($input['mode'] ?? $this->defaultMode($detected));
        $internal = self::validateInternalPort($input['internal_port'] ?? null);

        $reserved = $this->reservedPorts($domain);
        if (array_key_exists('port', $input) && $input['port'] !== null && $input['port'] !== '') {
            $port = self::validatePort($input['port']);
            if (in_array($port, $reserved, true)) {
                throw new BrokerException("Port {$port} is already used by another Docker vhost.", 3);
            }
        } else {
            $port = $this->allocatePort($reserved);
        }

        $stored = DockerSettings::load($this->runtime, $domain);
        $restart = array_key_exists('restart', $input) ? DockerSettings::validateRestart($input['restart']) : $stored['restart'];
        $volumes = array_key_exists('volumes', $input) ? DockerSettings::validateVolumes($input['volumes']) : $stored['volumes'];
        $registry = array_key_exists('registry', $input) ? $this->validateRegistryRef($input['registry']) : $stored['registry'];

        $image = null;
        $compose = null;
        $dockerfile = null;
        $service = null;
        if ($mode === self::MODE_IMAGE) {
            $image = self::validateImage($input['image'] ?? null);
        } elseif ($mode === self::MODE_COMPOSE) {
            $compose = self::validateRelativePath(
                $input['compose'] ?? ($detected['compose'] ?? self::DEFAULT_COMPOSE),
                'compose'
            );
            if (!$this->runtime->fileExists($appDir . '/' . $compose)) {
                throw new BrokerException("Compose file not found: {$compose}", 3);
            }
            $service = $this->resolveService($appDir, $compose, $input['service'] ?? $stored['service']);
        } else {
            $dockerfile = self::validateRelativePath(
                $input['dockerfile'] ?? ($detected['dockerfile'] ?? self::DEFAULT_DOCKERFILE),
                'dockerfile'
            );
            if (!$this->runtime->fileExists($appDir . '/' . $dockerfile)) {
                throw new BrokerException("Dockerfile not found: {$dockerfile}", 3);
            }
            $image = self::builtImageTag($domain);
        }

        return [
            'domain' => $domain,
            'mode' => $mode,
            'app_dir' => $appDir,
            'port' => $port,
            'internal_port' => $internal,
            'image' => $image,
            'compose' => $compose,
            'dockerfile' => $dockerfile,
            'service' => $service,
            'restart' => $restart,
            'volumes' => $volumes,
            'registry' => $registry,
        ];
    }

    /**
     * The service that gets the site's port (G8). Asked of compose itself; an explicit
     * choice must exist, and with no choice only a single-service file is unambiguous.
     */
    private function resolveService(string $appDir, string $compose, mixed $requested): string
    {
        $services = $this->composeServices($appDir, $compose);
        if ($requested !== null && $requested !== '') {
            $service = DockerSettings::validateService($requested);
            if (!in_array($service, $services, true)) {
                throw new BrokerException("Service {$service} is not in {$compose} (" . implode(', ', $services) . ').', 2);
            }

            return $service;
        }
        if (count($services) === 1) {
            return $services[0];
        }

        throw new BrokerException(
            "{$compose} defines " . count($services) . ' services (' . implode(', ', $services)
            . '). Choose the one that serves the site: service=<name>.',
            2
        );
    }

    /** @return list<string> */
    private function composeServices(string $appDir, string $compose): array
    {
        $result = $this->runAsSupervised(
            ['/usr/bin/env', $this->dockerEnvAssign(), $this->dockerBin(), 'compose', '-f', $compose, 'config', '--services'],
            $appDir,
            60
        );
        if (!$result->ok()) {
            throw new BrokerException('docker compose could not read ' . $compose . ': ' . self::execDetail($result), 3);
        }
        $services = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $line)) {
                $services[] = $line;
            }
        }
        if ($services === []) {
            throw new BrokerException("{$compose} defines no services.", 3);
        }

        return $services;
    }

    private function validateRegistryRef(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $name = DockerRegistries::validateName($value);
        if (!DockerRegistries::exists($this->runtime, $name)) {
            throw new BrokerException("No saved registry named {$name}. Add it first: azerioid docker registry add {$name}", 3);
        }

        return $name;
    }

    /** @param array<string, mixed> $spec */
    private function storeSettings(string $domain, array $spec): void
    {
        DockerSettings::save($this->runtime, $domain, [
            'service' => $spec['service'],
            'restart' => $spec['restart'],
            'volumes' => $spec['volumes'],
            'registry' => $spec['registry'],
        ]);
    }

    /**
     * Bind-mounted data directories live inside the app, so the site's own identity, its
     * backups and the File Manager see them. A missing one is created for the container:
     * owned by azerioid-supervised (root inside a rootless container), group the vhost's.
     *
     * @param  array<string, mixed>  $spec
     */
    private function ensureVolumeDirs(array $spec): void
    {
        foreach ($spec['volumes'] ?? [] as $volume) {
            $path = rtrim($spec['app_dir'], '/') . '/' . $volume['host'];
            if (!$this->runtime->isDir($path)) {
                $this->runtime->mkdir($path, 02770);
                if ($this->runtime->getuid() === 0) {
                    $this->runtime->chown($path, SupervisedUser::USERNAME, VhostUser::docrootGroup($this->runtime, $spec['domain']));
                    $this->runtime->chmod($path, 02770);
                }
            }
            // A symlink planted in the app must not turn a mount into a path outside it.
            if ($this->runtime->resolveUnderBase($path, $spec['app_dir']) === null) {
                throw new BrokerException("Volume {$volume['host']} resolves outside the app directory.", 3);
            }
        }
    }

    /**
     * The daemon resolves bind mounts itself, as azerioid-supervised with the group list it
     * started with. A vhost created after that (A49: every vhost has its own group) is closed
     * to it until it restarts — `mkdir …: permission denied` on the mount. Restart it once,
     * then bring back every Docker site it took down, whatever their restart policy.
     */
    private function ensureDaemonReaches(string $domain): void
    {
        if ($this->runtime->getuid() !== 0) {
            return;
        }
        $setup = new DockerRootlessSetup($this->config, $this->runtime);
        $groups = $setup->daemonGroups();
        if ($groups === null) {
            return;
        }
        $group = $this->runtime->exec(['/usr/bin/getent', 'group', VhostUser::docrootGroup($this->runtime, $domain)], null, 10);
        $gid = (int) (explode(':', trim($group->stdout))[2] ?? -1);
        if (!$group->ok() || $gid < 0 || in_array($gid, $groups, true)) {
            return;
        }
        self::restartDaemonAndSites($this->config, $this->runtime);
    }

    /** Restart the rootless daemon, then every Docker program that was running before. */
    public static function restartDaemonAndSites(Config $config, Runtime $runtime): void
    {
        $supervisor = new SupervisorManager($config, $runtime);
        $running = [];
        try {
            foreach ($supervisor->listPrograms()['programs'] as $row) {
                $status = $row['status'] ?? '';
                $state = is_array($status) ? (string) ($status['state'] ?? '') : (string) $status;
                if (str_starts_with((string) $row['name'], self::PROGRAM_PREFIX) && stripos($state, 'RUNNING') !== false) {
                    $running[] = (string) $row['name'];
                }
            }
        } catch (BrokerException) {
            // No Supervisor: nothing to bring back.
        }
        $setup = new DockerRootlessSetup($config, $runtime);
        $setup->restartDaemon();
        if ($running === []) {
            return;
        }
        // Supervisor has usually brought them back by itself already (a `docker run` whose
        // daemon went away exits, and autorestart runs it again). Restarting those as well
        // races the old container's --rm removal: "name already in use", three quick
        // failures, FATAL. So give it a moment, then only start what is still down —
        // including sites whose restart policy is "never" — after clearing a stale container.
        $runtime->exec(['/bin/sleep', '5'], null, 10);
        $states = [];
        try {
            foreach ($supervisor->listPrograms()['programs'] as $row) {
                $status = $row['status'] ?? '';
                $states[(string) $row['name']] = is_array($status) ? (string) ($status['state'] ?? '') : (string) $status;
            }
        } catch (BrokerException) {
            return;
        }
        $docker = $runtime->fileExists('/usr/bin/docker') ? '/usr/bin/docker' : '/usr/local/bin/docker';
        foreach ($running as $program) {
            if (stripos($states[$program] ?? '', 'RUNNING') !== false) {
                continue;
            }
            $runtime->exec([
                '/usr/sbin/runuser', '-u', SupervisedUser::USERNAME, '--', '/usr/bin/env', 'DOCKER_HOST=' . $setup->dockerHost(),
                $docker, 'rm', '-f', $program,
            ], null, 60);
            try {
                $supervisor->control($program, 'start');
            } catch (BrokerException) {
                // Reported by status; one site must not stop the others coming back.
            }
        }
    }

    /**
     * Run $fn with a throwaway docker config holding one registry login (#6). The credential
     * reaches docker on stdin and exists on disk only for the duration of $fn.
     *
     * @template T
     * @param  callable(list<string>):T  $fn  receives the env assignment(s) to add to docker calls
     * @return T
     */
    private function withRegistry(?string $registry, callable $fn): mixed
    {
        if ($registry === null) {
            return $fn([]);
        }
        $cred = DockerRegistries::get($this->runtime, $registry);
        if (!$this->runtime->isDir(DockerSettings::BASE)) {
            $this->runtime->mkdir(DockerSettings::BASE, 0750);
        }
        $dir = DockerSettings::BASE . '/.auth-' . bin2hex(random_bytes(8));
        $this->runtime->mkdir($dir, 0700);
        if ($this->runtime->getuid() === 0) {
            $this->runtime->chown(DockerSettings::BASE, 'root', SupervisedUser::USERNAME);
            $this->runtime->chmod(DockerSettings::BASE, 0750);
            $this->runtime->chown($dir, SupervisedUser::USERNAME, SupervisedUser::USERNAME);
            $this->runtime->chmod($dir, 0700);
        }
        $auth = ['DOCKER_CONFIG=' . $dir];
        try {
            $login = $this->runAsSupervised(
                array_merge(['/usr/bin/env', $this->dockerEnvAssign()], $auth, [
                    $this->dockerBin(), 'login', $cred['host'], '--username', $cred['username'], '--password-stdin',
                ]),
                '/tmp',
                60,
                $cred['password'] . "\n"
            );
            if (!$login->ok()) {
                throw new BrokerException("Login to {$cred['host']} as {$cred['username']} failed: " . self::execDetail($login, 300), 3);
            }

            return $fn($auth);
        } finally {
            $this->runAsSupervised(
                array_merge(['/usr/bin/env', $this->dockerEnvAssign()], $auth, [$this->dockerBin(), 'logout', $cred['host']]),
                '/tmp',
                30
            );
            $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $dir], null, 30);
        }
    }

    /**
     * @param  array{docker:bool, compose:?string, dockerfile:?string}  $detected
     */
    private function defaultMode(array $detected): string
    {
        if (!empty($detected['compose'])) {
            return self::MODE_COMPOSE;
        }
        if (!empty($detected['dockerfile'])) {
            return self::MODE_DOCKERFILE;
        }

        return self::MODE_IMAGE;
    }

    public static function validateImage(mixed $value): string
    {
        $image = trim((string) $value);
        if ($image === '' || strlen($image) > 255) {
            throw new BrokerException('image is required for image mode.', 2);
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\/\-:]*$/', $image) !== 1) {
            throw new BrokerException('image contains invalid characters.', 2);
        }

        return $image;
    }

    public static function validateRelativePath(mixed $value, string $label): string
    {
        $path = trim((string) $value);
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new BrokerException("{$label} must be a relative path inside the app directory.", 2);
        }

        return ltrim($path, './');
    }

    public static function builtImageTag(string $domain): string
    {
        return 'azerioid/' . self::domainSlug($domain) . ':panel';
    }

    /**
     * @param  array<string, mixed>  $vhost
     * @return array{
     *   domain:string, mode:string, app_dir:string, port:int, internal_port:int,
     *   image:?string, compose:?string, dockerfile:?string
     * }
     */
    private function specFromVhost(string $domain, array $vhost): array
    {
        $detected = self::detectDockerApp($this->runtime, $vhost['root'] ?? null);
        $mode = self::validateMode($vhost['docker_mode'] ?? self::MODE_IMAGE);
        $settings = DockerSettings::load($this->runtime, $domain);

        return [
            'service' => $settings['service'],
            'restart' => $settings['restart'],
            'volumes' => $settings['volumes'],
            'registry' => $settings['registry'],
            'domain' => $domain,
            'mode' => $mode,
            'app_dir' => $detected['app_dir'] ?? rtrim((string) ($vhost['root'] ?? ''), '/'),
            'port' => (int) ($vhost['docker_port'] ?? 0),
            'internal_port' => (int) ($vhost['docker_internal_port'] ?? 0),
            'image' => isset($vhost['docker_image']) ? (string) $vhost['docker_image'] : null,
            'compose' => isset($vhost['docker_compose']) ? (string) $vhost['docker_compose'] : null,
            'dockerfile' => isset($vhost['docker_dockerfile']) ? (string) $vhost['docker_dockerfile'] : null,
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
            $port = (int) ($vhost['docker_port'] ?? 0);
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    /**
     * @param  array{
     *   domain:string, mode:string, app_dir:string, port:int, internal_port:int,
     *   image:?string, compose:?string, dockerfile:?string
     * }  $spec
     */
    private function prepareWorkload(array $spec, bool $forceRebuild = false): void
    {
        $this->withRegistry($spec['registry'] ?? null, function (array $auth) use ($spec, $forceRebuild): void {
            $docker = $this->dockerBin();
            $env = array_merge(['/usr/bin/env', $this->dockerEnvAssign()], $auth);
            if ($spec['mode'] === self::MODE_IMAGE) {
                $pull = $this->runAsSupervised(array_merge($env, [$docker, 'pull', (string) $spec['image']]), $spec['app_dir'], 600);
                if (!$pull->ok()) {
                    throw new BrokerException('docker pull failed: ' . self::execDetail($pull), 1);
                }

                return;
            }
            if ($spec['mode'] === self::MODE_DOCKERFILE) {
                $build = $this->runAsSupervised(
                    array_merge($env, [$docker, 'build', '-t', (string) $spec['image'], '-f', (string) $spec['dockerfile'], '.']),
                    $spec['app_dir'],
                    900
                );
                if (!$build->ok()) {
                    throw new BrokerException('docker build failed: ' . self::execDetail($build), 1);
                }

                return;
            }

            $this->writePortsOverride($spec);
            $compose = array_merge($env, [
                $docker, 'compose',
                '-f', (string) $spec['compose'],
                '-f', $this->portsOverridePath($spec['domain']),
                '-p', self::composeProject($spec['domain']),
            ]);
            if ($auth !== []) {
                // Supervisor's `up` runs without the credential, so private images are
                // fetched now, while it exists.
                $pull = $this->runAsSupervised(array_merge($compose, ['pull', '--ignore-buildable']), $spec['app_dir'], 900);
                if (!$pull->ok()) {
                    throw new BrokerException('docker compose pull failed: ' . self::execDetail($pull), 1);
                }
            }
            $build = $this->runAsSupervised(
                array_merge($compose, $forceRebuild ? ['build', '--no-cache'] : ['build']),
                $spec['app_dir'],
                900
            );
            if (!$build->ok()) {
                throw new BrokerException('docker compose build failed: ' . self::execDetail($build), 1);
            }
        });
    }

    /**
     * @param  array{
     *   domain:string, mode:string, app_dir:string, port:int, internal_port:int,
     *   image:?string, compose:?string, dockerfile:?string
     * }  $spec
     */
    private function runCommand(array $spec): string
    {
        $docker = $this->dockerBin();
        $env = $this->dockerEnvAssign();
        if ($spec['mode'] === self::MODE_COMPOSE) {
            $parts = [
                '/usr/bin/env', $env, $docker, 'compose',
                '-f', (string) $spec['compose'],
                '-f', $this->portsOverridePath($spec['domain']),
                '-p', self::composeProject($spec['domain']),
                'up', '--abort-on-container-exit', '--remove-orphans',
            ];

            return Validator::supervisorCommand(implode(' ', $parts));
        }

        $parts = [
            '/usr/bin/env', $env, $docker, 'run', '--rm',
            '--name', self::containerName($spec['domain']),
            '-p', '127.0.0.1:' . $spec['port'] . ':' . $spec['internal_port'],
        ];
        if (DockerSettings::loadEnv($this->runtime, $spec['domain']) !== []) {
            // Values stay in the file: never on the command line, where ps would show them.
            array_push($parts, '--env-file', DockerSettings::envPath($spec['domain']));
        }
        foreach ($spec['volumes'] ?? [] as $volume) {
            array_push($parts, '-v', rtrim($spec['app_dir'], '/') . '/' . $volume['host'] . ':' . $volume['container']
                . ($volume['readonly'] ? ':ro' : ''));
        }
        $parts[] = (string) $spec['image'];

        return Validator::supervisorCommand(implode(' ', $parts));
    }

    /**
     * @param  array{
     *   domain:string, mode:string, app_dir:string, port:int, internal_port:int,
     *   image:?string, compose:?string, dockerfile:?string
     * }  $spec
     */
    private function writePortsOverride(array $spec): void
    {
        $service = $spec['service'] ?? null;
        if ($service === null) {
            // Enabled before the service was recorded (never on a real host: compose could not
            // read the override until v2.1.0). The same rule as enable: one service, or ask.
            $service = $this->resolveService($spec['app_dir'], (string) $spec['compose'], null);
        }
        DockerSettings::ensureDir($this->runtime, $spec['domain']);
        $yaml = "services:\n  {$service}:\n    ports:\n"
            . "      - \"127.0.0.1:{$spec['port']}:{$spec['internal_port']}\"\n";
        $env = DockerSettings::loadEnv($this->runtime, $spec['domain']);
        if ($env !== []) {
            $yaml .= "    environment:\n";
            foreach ($env as $key => $value) {
                $yaml .= "      {$key}: " . DockerSettings::yamlString($value) . "\n";
            }
        }
        if (($spec['volumes'] ?? []) !== []) {
            $yaml .= "    volumes:\n";
            foreach ($spec['volumes'] as $volume) {
                $yaml .= '      - ' . DockerSettings::yamlString(
                    rtrim($spec['app_dir'], '/') . '/' . $volume['host'] . ':' . $volume['container'] . ($volume['readonly'] ? ':ro' : '')
                ) . "\n";
            }
        }
        DockerSettings::writeShared($this->runtime, $this->portsOverridePath($spec['domain']), $yaml);
    }

    /**
     * @param  array{
     *   domain:string, mode:string, app_dir:string, port:int, internal_port:int,
     *   image:?string, compose:?string, dockerfile:?string
     * }  $spec
     */
    private function cleanupContainers(array $spec): void
    {
        $docker = $this->dockerBinIfPresent();
        if ($docker === null) {
            return;
        }
        $env = $this->dockerEnvAssign();
        if ($spec['mode'] === self::MODE_COMPOSE && is_string($spec['compose']) && $spec['compose'] !== '') {
            $this->runAsSupervised(
                [
                    '/usr/bin/env', $env, $docker, 'compose',
                    '-f', $spec['compose'],
                    '-f', $this->portsOverridePath($spec['domain']),
                    '-p', self::composeProject($spec['domain']),
                    'down', '--remove-orphans',
                ],
                $spec['app_dir'] !== '' ? $spec['app_dir'] : '/tmp',
                180
            );
        }
        $this->runAsSupervised(
            ['/usr/bin/env', $env, $docker, 'rm', '-f', self::containerName($spec['domain'])],
            '/tmp',
            60
        );
    }

    /** @param  array<string, mixed>  $vhost */
    private function containerSnapshot(string $domain, array $vhost): ?array
    {
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== self::RUNTIME_DOCKER) {
            return null;
        }
        $docker = $this->dockerBinIfPresent();
        if ($docker === null) {
            return null;
        }
        $env = $this->dockerEnvAssign();
        $name = self::containerName($domain);
        $result = $this->runAsSupervised(
            [
                '/usr/bin/env', $env, $docker, 'inspect',
                '--format', '{{.Id}} {{.State.Status}}',
                $name,
            ],
            '/tmp',
            30
        );
        if (!$result->ok()) {
            return ['name' => $name, 'id' => null, 'state' => 'missing'];
        }
        $parts = preg_split('/\s+/', trim($result->stdout)) ?: [];

        return [
            'name' => $name,
            'id' => $parts[0] ?? null,
            'state' => $parts[1] ?? 'unknown',
        ];
    }

    /**
     * @param  array{
     *   domain:string, mode:string, app_dir:string, port:int, internal_port:int,
     *   image:?string, compose:?string, dockerfile:?string
     * }  $spec
     */
    private function resolveComposeRunningContainer(array $spec): ?string
    {
        $docker = $this->dockerBinIfPresent();
        if ($docker === null || !is_string($spec['compose']) || $spec['compose'] === '') {
            return null;
        }
        $env = $this->dockerEnvAssign();
        $cwd = $spec['app_dir'] !== '' ? $spec['app_dir'] : '/tmp';
        $ps = $this->runAsSupervised(
            [
                '/usr/bin/env', $env, $docker, 'compose',
                '-f', $spec['compose'],
                '-f', $this->portsOverridePath($spec['domain']),
                '-p', self::composeProject($spec['domain']),
                'ps', '--status', 'running', '--format', '{{.Name}}',
            ],
            $cwd,
            30
        );
        if ($ps->ok()) {
            foreach (explode("\n", trim($ps->stdout)) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    return $line;
                }
            }
        }
        $project = self::composeProject($spec['domain']);
        $ids = $this->runAsSupervised(
            [
                '/usr/bin/env', $env, $docker, 'ps', '-q',
                '--filter', 'label=com.docker.compose.project=' . $project,
                '--filter', 'status=running',
            ],
            '/tmp',
            30
        );
        if (!$ids->ok()) {
            return null;
        }
        $id = trim(explode("\n", trim($ids->stdout))[0] ?? '');
        if ($id === '') {
            return null;
        }
        $inspect = $this->runAsSupervised(
            ['/usr/bin/env', $env, $docker, 'inspect', '--format', '{{.Name}}', $id],
            '/tmp',
            15
        );
        if (!$inspect->ok()) {
            return $id;
        }
        $name = ltrim(trim($inspect->stdout), '/');

        return $name !== '' ? $name : $id;
    }

    /**
     * Read by `docker compose` as azerioid-supervised, so it lives where that account can
     * read it. Until v2.1.0 it was under /var/lib/azerioid-panel/docker-meta (root 0750),
     * which the account cannot enter — compose mode never started on a real host.
     */
    private function portsOverridePath(string $domain): string
    {
        return DockerSettings::portsOverridePath($domain);
    }

    private function legacyPortsOverridePath(string $domain): string
    {
        return '/var/lib/azerioid-panel/docker-meta/' . self::domainSlug($domain) . '.ports.yml';
    }

    private function metaPath(string $domain): string
    {
        $dir = '/var/lib/azerioid-panel/docker-meta';
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
        $this->runtime->writeFile($this->metaPath($domain), $payload . "\n", 0640);
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

    private function cleanupMeta(string $domain): void
    {
        foreach ([$this->metaPath($domain), $this->portsOverridePath($domain), $this->legacyPortsOverridePath($domain)] as $path) {
            if ($this->runtime->fileExists($path)) {
                $this->runtime->deleteFile($path);
            }
        }
    }

    private function assertDockerComponentInstalled(): void
    {
        $managed = ManagedManifest::load($this->runtime, $this->config->managedComponentsPath);
        if (!$managed->has('docker')) {
            throw new BrokerException(
                'Docker is not installed. Install the Docker (rootless) component from Components first.',
                3
            );
        }
        if ($this->dockerBinIfPresent() === null) {
            throw new BrokerException('docker binary not found; reinstall the Docker component.', 1);
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

    private function waitForPort(int $port, int $attempts = 60): bool
    {
        if ($port < 1 || $this->ssBin() === null) {
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
    private function runAsSupervised(array $command, string $cwd, int $timeout, ?string $stdin = null): ExecResult
    {
        SupervisedUser::ensure($this->runtime);
        $runuser = $this->runuserBin();
        $shell = 'cd ' . escapeshellarg($cwd) . ' && exec ' . implode(' ', array_map('escapeshellarg', $command));

        return $this->runtime->exec(
            [$runuser, '-u', SupervisedUser::USERNAME, '--', '/bin/bash', '-lc', $shell],
            $stdin,
            $timeout
        );
    }

    private function dockerEnvAssign(): string
    {
        return 'DOCKER_HOST=' . (new DockerRootlessSetup($this->config, $this->runtime))->dockerHost();
    }

    private function dockerBin(): string
    {
        $bin = $this->dockerBinIfPresent();
        if ($bin === null) {
            throw new BrokerException('docker binary not found.', 1);
        }

        return $bin;
    }

    private function dockerBinIfPresent(): ?string
    {
        foreach (['/usr/bin/docker', '/usr/local/bin/docker'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function runuserBin(): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException('runuser is required to run docker as the supervised user.', 1);
    }

    private static function execDetail(ExecResult $result, int $max = 400): string
    {
        $detail = trim($result->stderr . "\n" . $result->stdout);
        if (strlen($detail) > $max) {
            $detail = substr($detail, -$max);
        }

        return $detail;
    }
}
