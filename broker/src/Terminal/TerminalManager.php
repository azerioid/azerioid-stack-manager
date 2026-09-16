<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Terminal;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\Component\DockerRootlessSetup;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\AppRuntime;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\VhostUser;
use AzerioidPanel\Broker\Web\WebServers;

final class TerminalManager
{
    private const SESSION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public const KIND_HOST = 'host';
    public const KIND_CONTAINER = 'container';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function start(string $domain, array $input): array
    {
        $this->assertTtydInstalled();
        $this->cleanupExpired();

        $kind = $this->normalizeKind((string) ($input['mode'] ?? self::KIND_HOST));
        $vhost = $this->eligibleVhost($domain);
        $root = (string) $vhost['root'];

        $adminId = trim((string) ($input['admin_user_id'] ?? ''));
        $sourceIp = trim((string) ($input['source_ip'] ?? ''));
        if ($adminId === '') {
            throw new BrokerException('admin_user_id is required for terminal audit.', 2);
        }

        foreach ($this->sessions()['sessions'] as $existing) {
            $sameDomain = ($existing['domain'] ?? '') === $domain;
            $sameAdmin = ($existing['admin_user_id'] ?? '') === $adminId;
            $sameKind = ($existing['kind'] ?? self::KIND_HOST) === $kind;
            if ($sameDomain && $sameAdmin && $sameKind) {
                $this->stop((string) $existing['id'], 'replaced');
            }
        }

        $sessionId = bin2hex(random_bytes(16));
        $port = $this->allocatePort();
        $now = time();
        $idle = $this->config->terminalIdleSeconds;

        if ($kind === self::KIND_CONTAINER) {
            $session = $this->startContainerSession(
                $sessionId,
                $port,
                $domain,
                $vhost,
                $root,
                $adminId,
                $sourceIp,
                $now,
                $idle
            );
        } else {
            $session = $this->startHostSession(
                $sessionId,
                $port,
                $domain,
                $root,
                $adminId,
                $sourceIp,
                $now,
                $idle
            );
        }

        $store = $this->sessions();
        $store['sessions'][$sessionId] = $session;
        $this->saveSessions($store);
        $this->syncCaddyRoutes($store['sessions']);

        return [
            'session_id' => $sessionId,
            'domain' => $domain,
            'root' => $root,
            'username' => $session['username'],
            'kind' => $kind,
            'container' => $session['container'] ?? null,
            'ws_path' => '/terminal/' . $sessionId,
            'idle_seconds' => $idle,
            'started_at' => $session['started_at'],
        ];
    }

    /**
     * Host docroot shell as az-vh-* (unchanged privilege model).
     *
     * @param  array<string, mixed>  $vhost unused for host path; kept for call symmetry
     * @return array<string, mixed>
     */
    private function startHostSession(
        string $sessionId,
        int $port,
        string $domain,
        string $root,
        string $adminId,
        string $sourceIp,
        int $now,
        int $idle,
    ): array {
        $identity = VhostUser::ensure($this->runtime, $this->config, $domain, $root);
        $pid = $this->spawnTtydHost($sessionId, $port, $identity['username'], $root);

        return [
            'id' => $sessionId,
            'kind' => self::KIND_HOST,
            'domain' => $domain,
            'root' => $root,
            'username' => $identity['username'],
            'container' => null,
            'port' => $port,
            'pid' => $pid,
            'admin_user_id' => $adminId,
            'source_ip' => $sourceIp,
            'started_at' => gmdate('c', $now),
            'last_activity_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + $idle),
        ];
    }

    /**
     * Container shell: ttyd runs ONLY as azerioid-supervised with rootless DOCKER_HOST.
     * Never root, never az-vh-*, never docker group — privilege boundary for ADR A38.
     *
     * @param  array<string, mixed>  $vhost
     * @return array<string, mixed>
     */
    private function startContainerSession(
        string $sessionId,
        int $port,
        string $domain,
        array $vhost,
        string $root,
        string $adminId,
        string $sourceIp,
        int $now,
        int $idle,
    ): array {
        if (AppRuntime::normalize($vhost['runtime'] ?? AppRuntime::FPM) !== AppRuntime::DOCKER) {
            throw new BrokerException(
                'Container shell requires runtime=docker on this vhost.',
                3
            );
        }
        $docker = new DockerManager($this->config, $this->runtime);
        $target = $docker->resolveShellTarget($domain);
        $wrapper = DockerManager::DOCKER_EXEC_WRAPPER;
        if (!$this->runtime->fileExists($wrapper)) {
            (new DockerRootlessSetup($this->config, $this->runtime))->installDockerExecWrapper();
        }
        if (!$this->runtime->fileExists($wrapper)) {
            throw new BrokerException(
                'Container shell wrapper is missing at ' . $wrapper . '. Re-install or update the Docker component.',
                3
            );
        }
        $dockerHost = (new DockerRootlessSetup($this->config, $this->runtime))->dockerHost();
        $pid = $this->spawnTtydContainer($sessionId, $port, $dockerHost, $wrapper, $target['name']);

        return [
            'id' => $sessionId,
            'kind' => self::KIND_CONTAINER,
            'domain' => $domain,
            'root' => $root,
            'username' => SupervisedUser::USERNAME,
            'container' => $target['name'],
            'port' => $port,
            'pid' => $pid,
            'admin_user_id' => $adminId,
            'source_ip' => $sourceIp,
            'started_at' => gmdate('c', $now),
            'last_activity_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + $idle),
        ];
    }

    private function normalizeKind(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === '' || $mode === self::KIND_HOST) {
            return self::KIND_HOST;
        }
        if ($mode === self::KIND_CONTAINER) {
            return self::KIND_CONTAINER;
        }
        throw new BrokerException('mode must be host or container.', 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $sessionId, string $reason = 'manual'): array
    {
        $sessionId = $this->normalizeSessionId($sessionId);
        $store = $this->sessions();
        if (!isset($store['sessions'][$sessionId])) {
            throw new BrokerException('Terminal session not found.', 2);
        }

        $session = $store['sessions'][$sessionId];
        $this->stopTtyd($sessionId, (int) ($session['pid'] ?? 0));
        unset($store['sessions'][$sessionId]);
        $this->saveSessions($store);
        $this->syncCaddyRoutes($store['sessions']);

        $started = strtotime((string) ($session['started_at'] ?? '')) ?: time();
        $ended = time();

        return [
            'stopped' => true,
            'session_id' => $sessionId,
            'domain' => $session['domain'] ?? '',
            'kind' => $session['kind'] ?? self::KIND_HOST,
            'reason' => $reason,
            'started_at' => $session['started_at'] ?? null,
            'ended_at' => gmdate('c', $ended),
            'duration_seconds' => max(0, $ended - $started),
            'admin_user_id' => $session['admin_user_id'] ?? null,
            'source_ip' => $session['source_ip'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(string $sessionId): array
    {
        $sessionId = $this->normalizeSessionId($sessionId);
        $store = $this->sessions();
        if (!isset($store['sessions'][$sessionId])) {
            throw new BrokerException('Terminal session not found.', 2);
        }

        $now = time();
        $idle = $this->config->terminalIdleSeconds;
        $store['sessions'][$sessionId]['last_activity_at'] = gmdate('c', $now);
        $store['sessions'][$sessionId]['expires_at'] = gmdate('c', $now + $idle);
        $this->saveSessions($store);

        return [
            'session_id' => $sessionId,
            'expires_at' => $store['sessions'][$sessionId]['expires_at'],
        ];
    }

    /**
     * @return array{sessions: list<array<string, mixed>>}
     */
    public function listSessions(): array
    {
        $this->cleanupExpired();
        $out = array_values($this->sessions()['sessions']);
        usort($out, fn ($a, $b) => strcmp((string) ($a['started_at'] ?? ''), (string) ($b['started_at'] ?? '')));

        return ['sessions' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $sessionId): array
    {
        $sessionId = $this->normalizeSessionId($sessionId);
        $store = $this->sessions();
        if (!isset($store['sessions'][$sessionId])) {
            throw new BrokerException('Terminal session not found.', 2);
        }

        return $store['sessions'][$sessionId];
    }

    /**
     * @return array{removed: list<string>}
     */
    public function cleanupExpired(): array
    {
        $store = $this->sessions();
        $removed = [];
        $now = time();
        foreach ($store['sessions'] as $id => $session) {
            $expires = strtotime((string) ($session['expires_at'] ?? '')) ?: 0;
            $pid = (int) ($session['pid'] ?? 0);
            if ($expires > 0 && $expires < $now) {
                $this->stopTtyd((string) $id, $pid);
                unset($store['sessions'][$id]);
                $removed[] = (string) $id;
                continue;
            }
            if (!$this->ttydSessionAlive((string) $id, $pid)) {
                unset($store['sessions'][$id]);
                $removed[] = (string) $id;
            }
        }
        if ($removed !== []) {
            $this->saveSessions($store);
            $this->syncCaddyRoutes($store['sessions']);
        }

        return ['removed' => $removed];
    }

    public function stopForVhost(string $domain): void
    {
        $domain = Validator::domain($domain);
        $store = $this->sessions();
        foreach ($store['sessions'] as $id => $session) {
            if (($session['domain'] ?? '') === $domain) {
                $this->stopTtyd((string) $id, (int) ($session['pid'] ?? 0));
                unset($store['sessions'][$id]);
            }
        }
        $this->saveSessions($store);
        $this->syncCaddyRoutes($store['sessions']);
    }

    /**
     * @return array<string, mixed>
     */
    private function eligibleVhost(string $domain): array
    {
        $domain = Validator::domain($domain);
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            if (($vhost['domain'] ?? '') !== $domain) {
                continue;
            }
            if (!empty($vhost['readonly'])) {
                throw new BrokerException('Terminal access is not available for read-only or system vhosts.', 3);
            }
            $root = (string) ($vhost['root'] ?? '');
            if ($root === '') {
                throw new BrokerException('Vhost has no document root.', 2);
            }

            return $vhost;
        }

        throw new BrokerException('Vhost not found.', 2);
    }

    private function assertTtydInstalled(): void
    {
        if (!$this->runtime->fileExists($this->config->ttydBin)) {
            throw new BrokerException('ttyd is not installed (panel bootstrap dependency).', 3);
        }
    }

    private function spawnTtydHost(string $sessionId, int $port, string $username, string $root): int
    {
        if (!$this->runtime->fileExists('/usr/bin/systemd-run')) {
            throw new BrokerException('systemd-run is not installed; cannot start terminal session.', 3);
        }

        $runuser = $this->runuserBin();
        $unit = $this->ttydUnit($sessionId);
        $base = '/terminal/' . $sessionId;
        $log = '/var/log/azerioid-panel/ttyd-' . $sessionId . '.log';
        $result = $this->runtime->exec([
            '/usr/bin/systemd-run',
            '--quiet',
            '--unit=' . $unit,
            '-p', 'StandardOutput=append:' . $log,
            '-p', 'StandardError=append:' . $log,
            $runuser,
            '-u', $username,
            '--',
            $this->config->ttydBin,
            '-p', (string) $port,
            '-i', '127.0.0.1',
            '-W',
            '-b', $base,
            '-w', $root,
            '-t', 'disableReconnect=true',
            '/bin/bash',
            '-l',
        ], null, 15);
        if (!$result->ok()) {
            throw new BrokerException('Failed to start ttyd: ' . trim($result->stderr !== '' ? $result->stderr : $result->stdout), 1);
        }

        return $this->requireTtydPid($sessionId, $log);
    }

    /**
     * Spawn ttyd as azerioid-supervised only; DOCKER_HOST points at the rootless user socket.
     */
    private function spawnTtydContainer(
        string $sessionId,
        int $port,
        string $dockerHost,
        string $wrapper,
        string $containerName,
    ): int {
        if (!$this->runtime->fileExists('/usr/bin/systemd-run')) {
            throw new BrokerException('systemd-run is not installed; cannot start terminal session.', 3);
        }

        SupervisedUser::ensure($this->runtime);
        $runuser = $this->runuserBin();
        $unit = $this->ttydUnit($sessionId);
        $base = '/terminal/' . $sessionId;
        $log = '/var/log/azerioid-panel/ttyd-' . $sessionId . '.log';
        $result = $this->runtime->exec([
            '/usr/bin/systemd-run',
            '--quiet',
            '--unit=' . $unit,
            '-p', 'StandardOutput=append:' . $log,
            '-p', 'StandardError=append:' . $log,
            $runuser,
            '-u', SupervisedUser::USERNAME,
            '--',
            '/usr/bin/env',
            'DOCKER_HOST=' . $dockerHost,
            $this->config->ttydBin,
            '-p', (string) $port,
            '-i', '127.0.0.1',
            '-W',
            '-b', $base,
            '-t', 'disableReconnect=true',
            $wrapper,
            $containerName,
        ], null, 15);
        if (!$result->ok()) {
            throw new BrokerException(
                'Failed to start container ttyd: ' . trim($result->stderr !== '' ? $result->stderr : $result->stdout),
                1
            );
        }

        return $this->requireTtydPid($sessionId, $log);
    }

    private function requireTtydPid(string $sessionId, string $log): int
    {
        $pid = $this->ttydMainPid($sessionId);
        if ($pid < 1) {
            $detail = '';
            if ($this->runtime->fileExists($log)) {
                $detail = trim($this->runtime->readFile($log));
                if (strlen($detail) > 400) {
                    $detail = substr($detail, -400);
                }
            }
            throw new BrokerException(
                'Failed to start ttyd (no pid).' . ($detail !== '' ? ' ' . $detail : ''),
                1
            );
        }

        return $pid;
    }

    private function allocatePort(): int
    {
        $used = [];
        foreach ($this->sessions()['sessions'] as $session) {
            $used[(int) ($session['port'] ?? 0)] = true;
        }
        for ($port = $this->config->terminalPortMin; $port <= $this->config->terminalPortMax; $port++) {
            if (!isset($used[$port]) && !$this->portListening($port)) {
                return $port;
            }
        }
        throw new BrokerException('No free terminal ports available.', 1);
    }

    /**
     * @param  array<string, array<string, mixed>>  $sessions
     */
    private function syncCaddyRoutes(array $sessions): void
    {
        $path = $this->config->terminalCaddyRoutesPath;
        $this->runtime->mkdir(dirname($path), 0750);
        $this->runtime->writeFile($path, $this->renderCaddyRoutes($sessions), 0644);
        try {
            CaddyApply::run($this->runtime, $this->config, 'auto');
        } catch (BrokerException $e) {
            throw new BrokerException('Caddy could not apply terminal routes: ' . $e->getMessage(), 1);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sessions
     */
    private function renderCaddyRoutes(array $sessions): string
    {
        if ($sessions === []) {
            return "# AZERIOID Stack Manager — no active terminal sessions\n";
        }
        $auth = '127.0.0.1:' . $this->config->panelPort;
        $lines = ['# AZERIOID Stack Manager — terminal routes (broker-managed; do not edit)'];
        foreach ($sessions as $session) {
            $id = (string) ($session['id'] ?? '');
            $port = (int) ($session['port'] ?? 0);
            if ($id === '' || $port < 1) {
                continue;
            }
            $lines[] = "handle /terminal/{$id}/* {";
            $lines[] = "    forward_auth {$auth} {";
            $lines[] = "        uri /internal/terminal/auth/{$id}";
            $lines[] = '    }';
            $lines[] = "    reverse_proxy 127.0.0.1:{$port}";
            $lines[] = '}';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{sessions: array<string, array<string, mixed>>}
     */
    private function sessions(): array
    {
        $path = $this->config->terminalSessionsPath;
        if (!$this->runtime->fileExists($path)) {
            return ['sessions' => []];
        }
        $decoded = json_decode($this->runtime->readFile($path), true);
        if (!is_array($decoded) || !isset($decoded['sessions']) || !is_array($decoded['sessions'])) {
            return ['sessions' => []];
        }

        return ['sessions' => $decoded['sessions']];
    }

    /**
     * @param  array{sessions: array<string, array<string, mixed>>}  $store
     */
    private function saveSessions(array $store): void
    {
        $path = $this->config->terminalSessionsPath;
        $this->runtime->mkdir(dirname($path), 0750);
        $this->runtime->writeFile(
            $path,
            json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0640
        );
    }

    private function normalizeSessionId(string $sessionId): string
    {
        $sessionId = strtolower(trim($sessionId));
        if (!preg_match(self::SESSION_ID_PATTERN, $sessionId)) {
            throw new BrokerException('Invalid terminal session id.', 2);
        }

        return $sessionId;
    }

    private function killProcess(int $pid): void
    {
        if ($pid < 1) {
            return;
        }
        $this->runtime->exec(['/bin/kill', '-TERM', (string) $pid], null, 10);
        usleep(200_000);
        if ($this->processAlive($pid)) {
            $this->runtime->exec(['/bin/kill', '-KILL', (string) $pid], null, 10);
        }
    }

    private function stopTtyd(string $sessionId, int $fallbackPid = 0): void
    {
        if ($this->runtime->fileExists('/bin/systemctl')) {
            $unit = $this->ttydUnit($sessionId);
            $this->runtime->exec(['/bin/systemctl', 'stop', $unit], null, 15);
            $this->runtime->exec(['/bin/systemctl', 'reset-failed', $unit], null, 5);
        }
        if ($fallbackPid > 0) {
            $this->killProcess($fallbackPid);
        }
    }

    private function ttydSessionAlive(string $sessionId, int $fallbackPid): bool
    {
        if ($this->runtime->fileExists('/bin/systemctl')) {
            $result = $this->runtime->exec(['/bin/systemctl', 'is-active', $this->ttydUnit($sessionId)], null, 5);
            $state = trim($result->stdout);
            if ($state === 'active' || $state === 'activating') {
                return true;
            }
            if ($state === 'inactive' || $state === 'failed') {
                return false;
            }
        }

        return $fallbackPid > 0 && $this->processAlive($fallbackPid);
    }

    private function ttydMainPid(string $sessionId): int
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $result = $this->runtime->exec([
                '/bin/systemctl',
                'show',
                $this->ttydUnit($sessionId),
                '-p',
                'MainPID',
                '--value',
            ], null, 5);
            $pid = (int) trim($result->stdout);
            if ($pid > 0) {
                return $pid;
            }
            usleep(100_000);
        }

        return 0;
    }

    private function ttydUnit(string $sessionId): string
    {
        return 'az-terminal-' . $sessionId;
    }

    private function processAlive(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }

        return $this->runtime->exec(['/bin/kill', '-0', (string) $pid], null, 5)->ok();
    }

    private function portListening(int $port): bool
    {
        $ss = null;
        foreach (['/usr/sbin/ss', '/usr/bin/ss', '/bin/ss'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                $ss = $bin;
                break;
            }
        }
        if ($ss === null) {
            return false;
        }
        $r = $this->runtime->exec([$ss, '-H', '-tln', 'sport', '=', ':' . $port], null, 5);

        return trim($r->stdout) !== '';
    }

    private function runuserBin(): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException('runuser is not installed; cannot start terminal as vhost user.', 3);
    }
}
