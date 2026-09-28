<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\ProgramIdentity;

/**
 * A rootless Docker daemon of the site's own, run by the site's identity (ADR A56 part 2).
 *
 * Until A56 every Docker site shared one rootless daemon under azerioid-supervised, an account
 * in every site's group: any site's compose file could bind-mount another site. Now each Docker
 * site has its own daemon, under its own identity, so a container can reach exactly what the
 * site's Terminal can (proven on the Ubuntu host: its own docroot yes; another site, /etc no).
 *
 * The identity's home is its docroot, where rootless Docker would put its units and image
 * data. A drop-in for the identity's systemd user manager points XDG_CONFIG_HOME and
 * XDG_DATA_HOME at /var/lib/azerioid-docker-home/<site> (0700, the site's) instead, so nothing
 * lands in the web root. Also: 65 536 subordinate ids of its own, and linger, so the daemon
 * runs without a login. Cost: roughly 100–170 MB of RAM per Docker site (operator decision).
 */
final class SiteDocker
{
    public const HOME_BASE = '/var/lib/azerioid-docker-home';

    private const READY = '.azerioid-ready';

    private const DROPIN = 'azerioid-docker.conf';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public static function home(string $domain): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)), '-');
        if ($slug === '') {
            throw new BrokerException('Cannot derive a Docker home for this domain.', 2);
        }

        return self::HOME_BASE . '/' . $slug;
    }

    /** The site's daemon is set up and the site has not been put back on the shared one. */
    public static function ready(Runtime $runtime, string $domain): bool
    {
        return $runtime->fileExists(self::home($domain) . '/' . self::READY)
            && ProgramIdentity::sharedReason($runtime, $domain) === null;
    }

    public function user(string $domain): string
    {
        return VhostUser::username($domain);
    }

    public function uid(string $domain): int
    {
        $id = $this->runtime->exec(['/usr/bin/id', '-u', $this->user($domain)], null, 10);
        if (!$id->ok() || !preg_match('/^\d+$/', trim($id->stdout))) {
            throw new BrokerException('Cannot resolve the uid of ' . $this->user($domain) . '.', 1);
        }

        return (int) trim($id->stdout);
    }

    public function dockerHost(string $domain): string
    {
        return 'unix:///run/user/' . $this->uid($domain) . '/docker.sock';
    }

    /** Environment for a docker CLI call as the site: its own home, its own daemon. */
    public function env(string $domain): array
    {
        return ['HOME=' . self::home($domain), 'DOCKER_HOST=' . $this->dockerHost($domain)];
    }

    /** First id of the account's subordinate range, or null. */
    public function subIdStart(string $user): ?int
    {
        $contents = $this->runtime->fileExists('/etc/subuid') ? $this->runtime->readFile('/etc/subuid') : '';
        if (preg_match('/^' . preg_quote($user, '/') . ':(\d+):(\d+)\s*$/m', $contents, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Set up (idempotently) and start the site's daemon; returns when `docker info` reports
     * rootless. Only then is the site marked ready, so a failure leaves it on the shared daemon.
     */
    public function ensure(string $domain): void
    {
        if ($this->runtime->getuid() !== 0) {
            throw new BrokerException('A site Docker daemon can only be set up as root.', 3);
        }
        $user = $this->user($domain);
        if (!VhostUser::userExists($this->runtime, $user)) {
            throw new BrokerException("{$domain} has no site account yet.", 3);
        }
        $group = VhostUser::primaryGroup($this->runtime, $user) ?? $user;
        $uid = $this->uid($domain);
        $home = self::home($domain);

        if (!$this->runtime->isDir(self::HOME_BASE)) {
            $this->runtime->mkdir(self::HOME_BASE, 0711);
        }
        $this->runtime->chown(self::HOME_BASE, 'root', 'root');
        $this->runtime->chmod(self::HOME_BASE, 0711);
        foreach ([$home, $home . '/.config', $home . '/.local', $home . '/.local/share'] as $dir) {
            if (!$this->runtime->isDir($dir)) {
                $this->runtime->mkdir($dir, 0700);
            }
            $this->runtime->chown($dir, $user, $group);
        }
        $this->runtime->chmod($home, 0700);

        $this->ensureSubIds($user);

        $dropinDir = '/etc/systemd/system/user@' . $uid . '.service.d';
        $dropin = $dropinDir . '/' . self::DROPIN;
        $body = "# AZERIOID Stack Manager — rootless Docker of {$domain} keeps its units and data here, not in the docroot (ADR A56).\n"
            . "[Service]\nEnvironment=XDG_CONFIG_HOME={$home}/.config XDG_DATA_HOME={$home}/.local/share\n";
        $changed = !$this->runtime->fileExists($dropin) || $this->runtime->readFile($dropin) !== $body;
        if ($changed) {
            if (!$this->runtime->isDir($dropinDir)) {
                $this->runtime->mkdir($dropinDir, 0755);
            }
            $this->runtime->writeFile($dropin, $body, 0644);
            $this->must(['/usr/bin/systemctl', 'daemon-reload'], 'reload systemd');
        }
        $this->must(['/usr/bin/loginctl', 'enable-linger', $user], 'enable linger for ' . $user);
        if ($changed) {
            // The user manager reads its environment when it starts.
            $this->runtime->exec(['/usr/bin/systemctl', 'restart', 'user@' . $uid . '.service'], null, 60);
        } else {
            $this->runtime->exec(['/usr/bin/systemctl', 'start', 'user@' . $uid . '.service'], null, 60);
        }
        $this->waitFor(fn (): bool => $this->runtime->isDir('/run/user/' . $uid), 30, "the user runtime directory /run/user/{$uid}");

        if (!$this->runtime->fileExists($home . '/.config/systemd/user/docker.service')) {
            $setup = $this->asUser($domain, 'dockerd-rootless-setuptool.sh install --force', 300);
            if (!$setup->ok() && !$this->runtime->fileExists($home . '/.config/systemd/user/docker.service')) {
                throw new BrokerException('dockerd-rootless-setuptool.sh failed for ' . $domain . ': ' . substr(trim($setup->stderr . "\n" . $setup->stdout), -400), 1);
            }
        }
        $enable = $this->asUser($domain, 'systemctl --user daemon-reload; systemctl --user enable --now docker.service', 120);
        if (!$enable->ok()) {
            throw new BrokerException('Could not start the Docker daemon of ' . $domain . ': ' . substr(trim($enable->stderr . "\n" . $enable->stdout), -400), 1);
        }
        $this->waitFor(function () use ($domain): bool {
            $info = $this->asUser($domain, 'docker info --format "{{.SecurityOptions}}"', 30);

            return $info->ok() && str_contains($info->stdout, 'rootless');
        }, 60, "the rootless Docker daemon of {$domain}");

        $this->runtime->writeFile($home . '/' . self::READY, $this->runtime->now() . "\n", 0600);
    }

    /** Stop and remove the site's daemon, its data and its subordinate ids. */
    public function remove(string $domain): void
    {
        $user = $this->user($domain);
        $home = self::home($domain);
        if (VhostUser::userExists($this->runtime, $user)) {
            $uid = $this->uid($domain);
            if ($this->runtime->isDir('/run/user/' . $uid)) {
                $this->asUser($domain, 'systemctl --user disable --now docker.service', 120);
            }
            $this->runtime->exec(['/usr/bin/loginctl', 'disable-linger', $user], null, 30);
            $this->runtime->exec(['/usr/bin/systemctl', 'stop', 'user@' . $uid . '.service'], null, 60);
            $dropin = '/etc/systemd/system/user@' . $uid . '.service.d/' . self::DROPIN;
            if ($this->runtime->fileExists($dropin)) {
                $this->runtime->deleteFile($dropin);
                $this->runtime->exec(['/bin/rmdir', dirname($dropin)], null, 10);
                $this->runtime->exec(['/usr/bin/systemctl', 'daemon-reload'], null, 60);
            }
        }
        if ($this->runtime->isDir($home)) {
            // Image layers owned by subordinate ids: only root can remove them.
            $this->runtime->exec(['/bin/rm', '-rf', '--one-file-system', $home], null, 600);
        }
        foreach (['/etc/subuid', '/etc/subgid'] as $path) {
            if (!$this->runtime->fileExists($path)) {
                continue;
            }
            $lines = array_filter(
                explode("\n", $this->runtime->readFile($path)),
                static fn (string $l): bool => $l !== '' && !str_starts_with($l, $user . ':'),
            );
            $this->runtime->writeFile($path, implode("\n", $lines) . "\n", 0644);
        }
    }

    /** A shell command as the site, with its Docker home and daemon. */
    public function asUser(string $domain, string $shell, int $timeout): \AzerioidPanel\Broker\ExecResult
    {
        $uid = $this->uid($domain);
        $home = self::home($domain);

        return $this->runtime->exec([
            '/usr/sbin/runuser', '-u', $this->user($domain), '--', '/usr/bin/env',
            'HOME=' . $home,
            'XDG_CONFIG_HOME=' . $home . '/.config',
            'XDG_DATA_HOME=' . $home . '/.local/share',
            'XDG_RUNTIME_DIR=/run/user/' . $uid,
            'DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/' . $uid . '/bus',
            'DOCKER_HOST=unix:///run/user/' . $uid . '/docker.sock',
            'PATH=/usr/bin:/usr/sbin:/bin:/sbin',
            '/bin/bash', '-c', $shell,
        ], null, $timeout);
    }

    /** 65 536 subordinate uids and gids of the account's own, after every range in use. */
    private function ensureSubIds(string $user): void
    {
        $all = '';
        foreach (['/etc/subuid', '/etc/subgid'] as $path) {
            $all .= $this->runtime->fileExists($path) ? $this->runtime->readFile($path) . "\n" : '';
        }
        $start = 165536;
        foreach (explode("\n", $all) as $line) {
            if (preg_match('/^[^:]+:(\d+):(\d+)\s*$/', trim($line), $m) === 1) {
                $start = max($start, (int) $m[1] + (int) $m[2]);
            }
        }
        foreach (['/etc/subuid', '/etc/subgid'] as $path) {
            $contents = $this->runtime->fileExists($path) ? $this->runtime->readFile($path) : '';
            if (preg_match('/^' . preg_quote($user, '/') . ':\d+:(\d+)\s*$/m', $contents, $m) === 1 && (int) $m[1] >= 65536) {
                continue;
            }
            $contents = (string) preg_replace('/^' . preg_quote($user, '/') . ':.*\n?/m', '', $contents);
            $this->runtime->writeFile($path, rtrim($contents) . "\n{$user}:{$start}:65536\n", 0644);
        }
    }

    private function waitFor(callable $check, int $seconds, string $what): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            if ($check()) {
                return;
            }
            $this->runtime->exec(['/bin/sleep', '1'], null, 5);
        }

        throw new BrokerException("Timed out waiting for {$what}.", 1);
    }

    private function must(array $cmd, string $what): void
    {
        $r = $this->runtime->exec($cmd, null, 60);
        if (!$r->ok()) {
            throw new BrokerException("Could not {$what}: " . trim($r->stderr . ' ' . $r->stdout), 1);
        }
    }
}
