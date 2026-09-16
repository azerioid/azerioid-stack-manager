<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;
use AzerioidPanel\Broker\Vhost\DockerManager;

/**
 * Configure official Docker CE for rootless use by azerioid-supervised only (ADR A38).
 *
 * Never leaves rootful docker.service/socket enabled and never uses the docker group.
 */
final class DockerRootlessSetup
{
    public const DOCS_URL = 'https://docs.docker.com/engine/security/rootless/';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function configure(OperationLogger $log): void
    {
        $this->maskRootfulDocker($log);
        SupervisedUser::ensure($this->runtime);
        $this->ensureSubIds($log);
        $this->ensureLinger($log);
        $this->installRootlessDaemon($log);
        $this->assertRootless($log);
        $this->installDockerExecWrapper($log);
    }

    /**
     * Install azerioid-docker-exec for container shell (ttyd → rootless docker exec).
     * Privilege: wrapper itself is root-owned; ttyd invokes it only as azerioid-supervised.
     */
    public function installDockerExecWrapper(?OperationLogger $log = null): void
    {
        $dest = DockerManager::DOCKER_EXEC_WRAPPER;
        $dir = dirname($dest);
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0751);
        }
        $sourceCandidates = [
            rtrim($this->config->panelSourcePath, '/') . '/deploy/bin/azerioid-docker-exec',
            rtrim($this->config->panelRoot, '/') . '/deploy/bin/azerioid-docker-exec',
        ];
        $copied = false;
        foreach ($sourceCandidates as $src) {
            if ($src === '' || !$this->runtime->fileExists($src)) {
                continue;
            }
            $this->runtime->writeFile($dest, $this->runtime->readFile($src), 0755);
            $copied = true;
            break;
        }
        if (!$copied) {
            $this->runtime->writeFile($dest, self::dockerExecWrapperScript(), 0755);
        }
        $log?->info('Installed container shell wrapper at ' . $dest . '.');
    }

    public static function dockerExecWrapperScript(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# Interactive shell into a rootless Docker container.
# Invoked by ttyd as azerioid-supervised with DOCKER_HOST already set.
# Never run as root or az-vh-*; does not use the docker group.
set -euo pipefail

if [[ $# -lt 1 || -z "${1:-}" ]]; then
    echo "azerioid-docker-exec: container name required" >&2
    exit 2
fi

NAME="$1"

if ! command -v docker >/dev/null 2>&1; then
    echo "azerioid-docker-exec: docker binary not found" >&2
    exit 1
fi

if [[ -z "${DOCKER_HOST:-}" ]]; then
    echo "azerioid-docker-exec: DOCKER_HOST is required (rootless socket only)" >&2
    exit 1
fi

# Probe without -it so a missing shell is a clean miss (not a TTY race).
if docker exec "$NAME" bash -c 'exit 0' >/dev/null 2>&1; then
    exec docker exec -it "$NAME" bash
fi

if docker exec "$NAME" sh -c 'exit 0' >/dev/null 2>&1; then
    exec docker exec -it "$NAME" sh
fi

echo "azerioid-docker-exec: neither bash nor sh is available in container '${NAME}'" >&2
exit 1
BASH;
    }

    public function dockerHost(): string
    {
        return 'unix:///run/user/' . $this->supervisedUid() . '/docker.sock';
    }

    public function supervisedUid(): int
    {
        $id = $this->runtime->exec(['/usr/bin/id', '-u', SupervisedUser::USERNAME], null, 10);
        if (!$id->ok() || !preg_match('/^\d+$/', trim($id->stdout))) {
            throw new BrokerException('Cannot resolve uid for ' . SupervisedUser::USERNAME . '.', 1);
        }

        return (int) trim($id->stdout);
    }

    private function maskRootfulDocker(OperationLogger $log): void
    {
        foreach (['docker.service', 'docker.socket'] as $unit) {
            $this->runtime->exec(['/usr/bin/systemctl', 'stop', $unit], null, 60);
            $this->runtime->exec(['/usr/bin/systemctl', 'disable', $unit], null, 60);
            $mask = $this->runtime->exec(['/usr/bin/systemctl', 'mask', $unit], null, 30);
            if (!$mask->ok()) {
                $log->warn("Could not mask {$unit}: " . trim($mask->stderr));
            } else {
                $log->info("Masked rootful {$unit}.");
            }
        }
        // Package postinst may leave a stale rootful socket even after mask.
        if ($this->runtime->fileExists('/var/run/docker.sock') || $this->runtime->fileExists('/run/docker.sock')) {
            $this->runtime->exec(['/bin/rm', '-f', '/var/run/docker.sock', '/run/docker.sock'], null, 10);
            $log->info('Removed leftover rootful /run/docker.sock.');
        }
    }

    private function ensureSubIds(OperationLogger $log): void
    {
        $user = SupervisedUser::USERNAME;
        $changed = false;
        foreach (['/etc/subuid', '/etc/subgid'] as $path) {
            if ($this->ensureSubIdFile($path, $user)) {
                $changed = true;
            }
        }
        if ($changed) {
            $log->info("Ensured ≥65536 subordinate ids for {$user} in /etc/subuid and /etc/subgid.");
        }
    }

    private function ensureSubIdFile(string $path, string $user): bool
    {
        $contents = $this->runtime->fileExists($path) ? $this->runtime->readFile($path) : '';
        if (preg_match('/^' . preg_quote($user, '/') . ':(\d+):(\d+)\s*$/m', $contents, $m) === 1) {
            if ((int) $m[2] >= 65536) {
                return false;
            }
            $contents = (string) preg_replace(
                '/^' . preg_quote($user, '/') . ':\d+:\d+\s*$/m',
                $user . ':' . $m[1] . ':65536',
                $contents
            );
            $this->runtime->writeFile($path, rtrim($contents) . "\n", 0644);

            return true;
        }
        $start = $this->nextSubIdStart($contents);
        $this->runtime->writeFile($path, rtrim($contents) . "\n{$user}:{$start}:65536\n", 0644);

        return true;
    }

    private function nextSubIdStart(string $contents): int
    {
        $maxEnd = 100000;
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^[^:]+:(\d+):(\d+)\s*$/', trim($line), $m) !== 1) {
                continue;
            }
            $end = (int) $m[1] + (int) $m[2];
            if ($end > $maxEnd) {
                $maxEnd = $end;
            }
        }

        return $maxEnd;
    }

    private function ensureLinger(OperationLogger $log): void
    {
        $user = SupervisedUser::USERNAME;
        $result = $this->runtime->exec(['/usr/bin/loginctl', 'enable-linger', $user], null, 30);
        if (!$result->ok()) {
            throw new BrokerException(
                'loginctl enable-linger failed for ' . $user . ': ' . trim($result->stderr . ' ' . $result->stdout),
                1
            );
        }
        $log->info("Enabled linger for {$user} (required for rootless dockerd).");
    }

    private function installRootlessDaemon(OperationLogger $log): void
    {
        $this->ensureUserRuntime($log);
        $setup = $this->setuptoolBin();
        $runuser = $this->runuserBin();
        $home = SupervisedUser::HOME;
        $shell = 'export HOME=' . escapeshellarg($home) . '; '
            . 'export XDG_CONFIG_HOME=' . escapeshellarg($home . '/.config') . '; '
            . 'export XDG_DATA_HOME=' . escapeshellarg($home . '/.local/share') . '; '
            . 'export XDG_RUNTIME_DIR=/run/user/$(id -u); '
            . 'export DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$(id -u)/bus; '
            . 'mkdir -p "$HOME/.config" "$HOME/.local/share"; '
            . escapeshellarg($setup) . ' install --force';
        $result = $this->runtime->exec(
            [$runuser, '-u', SupervisedUser::USERNAME, '--', '/bin/bash', '-lc', $shell],
            null,
            300
        );
        if (!$result->ok()) {
            $detail = trim($result->stderr . "\n" . $result->stdout);
            // Idempotent re-install often exits non-zero when already configured.
            if (!str_contains(strtolower($detail), 'already') && !str_contains(strtolower($detail), 'installed')) {
                throw new BrokerException(
                    'dockerd-rootless-setuptool.sh install failed: ' . substr($detail, -400),
                    1
                );
            }
            $log->info('Rootless dockerd already configured for ' . SupervisedUser::USERNAME . '.');
        } else {
            $log->info('Installed rootless dockerd for ' . SupervisedUser::USERNAME . '.');
        }
        $this->enableUserDocker($log);
    }

    private function ensureUserRuntime(OperationLogger $log): void
    {
        $uid = $this->supervisedUid();
        $start = $this->runtime->exec(['/usr/bin/systemctl', 'start', 'user@' . $uid . '.service'], null, 60);
        if (!$start->ok()) {
            $log->warn('systemctl start user@' . $uid . '.service: ' . trim($start->stderr));
        }
        $deadline = time() + 15;
        $runtimeDir = '/run/user/' . $uid;
        while (time() < $deadline) {
            if ($this->runtime->isDir($runtimeDir)) {
                $log->info("User runtime directory ready at {$runtimeDir}.");

                return;
            }
            usleep(250000);
        }
        throw new BrokerException(
            "User runtime directory {$runtimeDir} did not appear after enabling linger for "
            . SupervisedUser::USERNAME . '.',
            1
        );
    }

    private function enableUserDocker(OperationLogger $log): void
    {
        $runuser = $this->runuserBin();
        $home = SupervisedUser::HOME;
        $shell = 'export HOME=' . escapeshellarg($home) . '; '
            . 'export XDG_RUNTIME_DIR=/run/user/$(id -u); '
            . 'export DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$(id -u)/bus; '
            . 'systemctl --user daemon-reload; '
            . 'systemctl --user enable --now docker.service';
        $result = $this->runtime->exec(
            [$runuser, '-u', SupervisedUser::USERNAME, '--', '/bin/bash', '-lc', $shell],
            null,
            120
        );
        if (!$result->ok()) {
            throw new BrokerException(
                'systemctl --user enable --now docker.service failed: '
                . substr(trim($result->stderr . "\n" . $result->stdout), -400),
                1
            );
        }
        $log->info('Enabled and started systemd --user docker.service for ' . SupervisedUser::USERNAME . '.');
    }

    private function assertRootless(OperationLogger $log): void
    {
        $host = $this->dockerHost();
        $docker = $this->dockerBin();
        $runuser = $this->runuserBin();
        $home = SupervisedUser::HOME;
        $shell = 'export HOME=' . escapeshellarg($home) . '; '
            . 'export DOCKER_HOST=' . escapeshellarg($host) . '; '
            . escapeshellarg($docker) . ' info --format "{{.SecurityOptions}}"';
        $out = '';
        $ok = false;
        for ($i = 0; $i < 10; $i++) {
            $result = $this->runtime->exec(
                [$runuser, '-u', SupervisedUser::USERNAME, '--', '/bin/bash', '-lc', $shell],
                null,
                60
            );
            $out = strtolower(trim($result->stdout . "\n" . $result->stderr));
            if ($result->ok() && str_contains($out, 'rootless')) {
                $ok = true;
                break;
            }
            usleep(500000);
        }
        if (!$ok) {
            throw new BrokerException(
                'docker info did not report rootless after setup (DOCKER_HOST=' . $host . '). '
                . 'See ' . self::DOCS_URL . '. Detail: ' . substr($out, -300),
                1
            );
        }
        $log->info('Verified rootless Docker at ' . $host . '.');
    }

    private function setuptoolBin(): string
    {
        foreach ([
            '/usr/bin/dockerd-rootless-setuptool.sh',
            '/usr/sbin/dockerd-rootless-setuptool.sh',
        ] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException(
            'dockerd-rootless-setuptool.sh not found. Install docker-ce-rootless-extras.',
            1
        );
    }

    private function dockerBin(): string
    {
        foreach (['/usr/bin/docker', '/usr/local/bin/docker'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException('docker binary not found after package install.', 1);
    }

    private function runuserBin(): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($this->runtime->fileExists($bin)) {
                return $bin;
            }
        }

        throw new BrokerException('runuser is required to configure rootless Docker.', 1);
    }
}
