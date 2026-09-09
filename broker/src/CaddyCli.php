<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

/**
 * Run Caddy CLI as the systemd service user with that user's data dir.
 *
 * `tls internal` provisions an internal CA under XDG_DATA_HOME (then HOME).
 * `runuser -u caddy` resets HOME from passwd but **keeps** the caller's
 * XDG_* vars. PosixRuntime::childEnv() inherits sudo's HOME=/root, so the
 * CLI would open /root/.local/share/caddy/pki/... as user caddy → EACCES.
 */
final class CaddyCli
{
    public const DATA_DIR = '/var/lib/caddy';

    /**
     * @param  list<string>  $caddyArgs  argv after the caddy binary
     * @return list<string>
     */
    public static function argv(Runtime $runtime, Config $config, array $caddyArgs): array
    {
        $cmd = array_merge(self::dataEnvPrefix($runtime), [$config->caddyBin], $caddyArgs);
        $user = self::serviceUser($runtime);
        if ($user === null) {
            return $cmd;
        }
        $wrapper = self::runAsWrapper($runtime, $user);
        if ($wrapper === null) {
            return $cmd;
        }
        return array_merge($wrapper, $cmd);
    }

    /**
     * Force Caddy's PKI/data dir even when the parent env is root's XDG_*.
     *
     * @return list<string>
     */
    public static function dataEnvPrefix(Runtime $runtime): array
    {
        if (!$runtime->isDir(self::DATA_DIR)) {
            return [];
        }
        $envBin = '/usr/bin/env';
        if (!$runtime->fileExists($envBin)) {
            $envBin = '/bin/env';
        }
        if (!$runtime->fileExists($envBin)) {
            $envBin = 'env';
        }

        return [
            $envBin,
            'HOME=' . self::DATA_DIR,
            'XDG_CONFIG_HOME=' . self::DATA_DIR . '/.config',
            'XDG_DATA_HOME=' . self::DATA_DIR . '/.local/share',
        ];
    }

    public static function validate(Runtime $runtime, Config $config, string $caddyfile): ExecResult
    {
        self::reclaimDataDir($runtime);
        return $runtime->exec(self::argv($runtime, $config, ['validate', '--config', $caddyfile]), null, 20);
    }

    public static function reload(Runtime $runtime, Config $config, string $address): ExecResult
    {
        self::reclaimDataDir($runtime);
        return $runtime->exec(
            self::argv($runtime, $config, ['reload', '--config', Config::CADDYFILE, '--address', $address, '--force']),
            null,
            30
        );
    }

    public static function reclaimDataDir(Runtime $runtime): void
    {
        $user = self::serviceUser($runtime);
        if ($user === null || !$runtime->isDir(self::DATA_DIR)) {
            return;
        }
        $spec = $user . ':' . $user;
        $chown = $runtime->fileExists('/usr/bin/chown') ? '/usr/bin/chown' : 'chown';
        $result = $runtime->exec([$chown, '-R', $spec, self::DATA_DIR], null, 30);
        if (!$result->ok()) {
            $detail = trim($result->stderr . "\n" . $result->stdout);
            throw new BrokerException(
                'Could not set owner of ' . self::DATA_DIR . ' to ' . $spec
                . ($detail !== '' ? ': ' . $detail : '.'),
                1
            );
        }
        fwrite(STDERR, '==> Caddy data dir owner: ' . $user . "\n");
    }

    public static function serviceUser(Runtime $runtime): ?string
    {
        $show = $runtime->exec(['/usr/bin/systemctl', 'show', 'caddy', '-p', 'User', '--value']);
        $raw = $show->ok() ? trim($show->stdout) : '';
        if ($raw === '' || str_starts_with($raw, 'User=')) {
            $show = $runtime->exec(['/usr/bin/systemctl', 'show', 'caddy', '-p', 'User']);
            $raw = $show->ok() ? trim($show->stdout) : '';
            if (str_starts_with($raw, 'User=')) {
                $raw = trim(substr($raw, 5));
            }
        }
        $raw = trim(explode("\n", $raw)[0] ?? '');
        return self::safeUser($raw);
    }

    private static function safeUser(string $user): ?string
    {
        if ($user === '' || $user === 'root' || $user === '0') {
            return null;
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{0,31}$/', $user)) {
            return null;
        }
        return $user;
    }

    /**
     * @return list<string>|null
     */
    private static function runAsWrapper(Runtime $runtime, string $user): ?array
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'] as $bin) {
            if ($runtime->fileExists($bin)) {
                return [$bin, '-u', $user, '--'];
            }
        }
        if ($runtime->fileExists('/usr/bin/sudo')) {
            return ['/usr/bin/sudo', '-n', '-u', $user, '--'];
        }
        return null;
    }
}
