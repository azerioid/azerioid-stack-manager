<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;

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
        $setup = $this->setuptoolBin();
        $runuser = $this->runuserBin();
        $shell = 'export XDG_RUNTIME_DIR=/run/user/$(id -u); '
            . 'export DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$(id -u)/bus; '
            . escapeshellarg($setup) . ' install';
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

            return;
        }
        $log->info('Installed rootless dockerd for ' . SupervisedUser::USERNAME . '.');
    }

    private function assertRootless(OperationLogger $log): void
    {
        $host = $this->dockerHost();
        $docker = $this->dockerBin();
        $runuser = $this->runuserBin();
        $shell = 'export DOCKER_HOST=' . escapeshellarg($host) . '; '
            . escapeshellarg($docker) . ' info --format "{{.SecurityOptions}}"';
        $result = $this->runtime->exec(
            [$runuser, '-u', SupervisedUser::USERNAME, '--', '/bin/bash', '-lc', $shell],
            null,
            60
        );
        $out = strtolower(trim($result->stdout . "\n" . $result->stderr));
        if (!$result->ok() || !str_contains($out, 'rootless')) {
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
