<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

/**
 * Single Caddy apply ladder for the installer (caddy.apply) and vhost add/del.
 *
 * Never dials `localhost` (glibc → ::1). Caddy's admin listener is IPv4
 * 127.0.0.1:2019 on typical Ubuntu packages.
 */
final class CaddyApply
{
    public const DROPIN = '/etc/systemd/system/caddy.service.d/azerioid-panel-reload.conf';

    public const ADMIN_MARKER = '/etc/azerioid-panel/caddy-admin-managed';

    public const IPV4_ADMIN = '127.0.0.1:2019';

    /**
     * @param  list<int>  $expectPorts
     * @return array{path: string, address: string, admin_spec: string, admin_enabled: bool}
     */
    public static function run(Runtime $runtime, Config $config, string $mode = 'auto', array $expectPorts = []): array
    {
        if (!in_array($mode, ['auto', 'api', 'systemctl', 'restart'], true)) {
            throw new BrokerException('Invalid Caddy apply mode.', 2);
        }

        $caddyfile = Config::CADDYFILE;
        Config::assertMainConfigPath($caddyfile, Config::CADDYFILE);
        if (!$runtime->fileExists($caddyfile)) {
            throw new BrokerException("Caddy main-config not found: {$caddyfile}", 1);
        }

        self::ensureSkipInstallTrust($runtime, $caddyfile);

        $validate = CaddyCli::validate($runtime, $config, $caddyfile);
        if (!$validate->ok()) {
            $detail = trim($validate->stderr . "\n" . $validate->stdout);
            throw new BrokerException(
                'Caddy rejected the config: ' . ($detail !== '' ? $detail : 'validation failed'),
                1
            );
        }

        $adminEnabled = self::ensureIpv4Admin($runtime, $config);
        $address = self::clientAddress(self::parseAdmin($runtime->readFile($caddyfile)));
        self::ensureReloadDropin($runtime, $config, $address);

        self::ensureActive($runtime);

        if ($adminEnabled) {
            fwrite(STDERR, "==> Admin was off; one restart to bind " . self::IPV4_ADMIN . " (later applies use graceful API reload)\n");
            $path = self::tryRestart($runtime, true);
            self::waitForAdmin($runtime, $address);
        } else {
            $path = match ($mode) {
                'api' => self::tryApi($runtime, $config, $address, true),
                'systemctl' => self::trySystemdReload($runtime, true),
                'restart' => self::tryRestart($runtime, true),
                default => self::ladder($runtime, $config, $address),
            };
        }

        self::assertHealthy($runtime, $expectPorts);
        CaddyCli::reclaimDataDir($runtime);

        fwrite(STDERR, "==> Caddy apply path: {$path} (admin {$address})\n");

        return [
            'path' => $path,
            'address' => $address,
            'admin_spec' => self::parseAdmin($runtime->readFile($caddyfile)),
            'admin_enabled' => $adminEnabled,
        ];
    }

    public static function parseAdmin(string $caddyfile): string
    {
        if (!preg_match('/(?ms)^\s*\{(.*?)\}/', $caddyfile, $m)) {
            return 'default';
        }
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            $s = trim($line);
            if (str_starts_with($s, 'admin ')) {
                return trim(trim(substr($s, 6)), '"\'');
            }
        }
        return 'default';
    }

    /**
     * Skip installing the local CA into the OS trust store (ADR A33).
     * Avoids recurring sudo/tee failures as the caddy user; TLS internal still works.
     */
    private static function ensureSkipInstallTrust(Runtime $runtime, string $caddyfile): void
    {
        $text = $runtime->readFile($caddyfile);
        if (preg_match('/(?m)^\s*skip_install_trust\b/', $text) === 1) {
            return;
        }
        if (!preg_match('/(?ms)^(\s*\{)(.*?)(\n\}\s*)/', $text, $m)) {
            $patched = "{\n    skip_install_trust\n}\n" . $text;
        } else {
            $inner = rtrim($m[2]) . "\n    skip_install_trust\n";
            $patched = $m[1] . $inner . $m[3] . substr($text, strlen($m[0]));
        }
        $runtime->writeFile($caddyfile, $patched, 0644);
    }

    public static function clientAddress(string $spec): string
    {
        $spec = trim($spec);
        if ($spec === '' || $spec === 'default' || $spec === 'off' || $spec === 'disabled') {
            return self::IPV4_ADMIN;
        }
        if (str_starts_with($spec, 'unix/') || str_starts_with($spec, 'unix://')) {
            return $spec;
        }
        $spec = preg_replace('#^https?://#', '', $spec) ?? $spec;
        return str_replace('localhost', '127.0.0.1', $spec);
    }

    private static function waitForAdmin(Runtime $runtime, string $address): void
    {
        if (str_starts_with($address, 'unix/') || str_starts_with($address, 'unix://')) {
            return;
        }
        $url = 'http://' . $address . '/config/';
        $curl = $runtime->fileExists('/usr/bin/curl') ? '/usr/bin/curl' : 'curl';
        for ($i = 0; $i < 20; $i++) {
            $probe = $runtime->exec([$curl, '-fsS', '--max-time', '2', $url], null, 5);
            if ($probe->ok()) {
                fwrite(STDERR, "==> Caddy admin is reachable at {$address}\n");
                return;
            }
            usleep(150000);
        }
        fwrite(STDERR, "==> Warning: admin {$address} not yet reachable after restart; later applies will use the API\n");
    }

    private static function ensureIpv4Admin(Runtime $runtime, Config $config): bool
    {
        $text = $runtime->readFile(Config::CADDYFILE);
        $spec = self::parseAdmin($text);
        if ($spec !== 'off' && $spec !== 'disabled') {
            return false;
        }

        $patched = preg_replace(
            '/^(\s*admin\s+)(?:off|disabled)(\s*)$/m',
            '${1}' . self::IPV4_ADMIN . '${2}' . "\n    # azerioid-panel: IPv4 admin so reload does not dial [::1]",
            $text,
            1,
            $count
        );
        if (!is_string($patched) || $count === 0) {
            if (!preg_match('/(?ms)^\s*\{/', $text)) {
                $patched = "{\n    admin " . self::IPV4_ADMIN . "\n}\n" . $text;
            } else {
                throw new BrokerException('Caddy admin is off and the Caddyfile could not be patched to enable 127.0.0.1:2019.', 1);
            }
        }

        $runtime->writeFile(Config::CADDYFILE, $patched, 0644);
        $again = CaddyCli::validate($runtime, $config, Config::CADDYFILE);
        if (!$again->ok()) {
            $runtime->writeFile(Config::CADDYFILE, $text, 0644);
            throw new BrokerException(
                'Enabling Caddy admin 127.0.0.1:2019 failed validation; Caddyfile was restored. ' . trim($again->stderr . "\n" . $again->stdout),
                1
            );
        }

        try {
            $runtime->writeFile(self::ADMIN_MARKER, "from-off\n", 0640);
        } catch (BrokerException) {
        }

        fwrite(STDERR, "==> Enabled Caddy admin on " . self::IPV4_ADMIN . " (was off; reversible via uninstall)\n");
        return true;
    }

    private static function ensureReloadDropin(Runtime $runtime, Config $config, string $address): void
    {
        if (str_starts_with($address, 'unix')) {
            return;
        }
        $dir = dirname(self::DROPIN);
        if (!$runtime->isDir($dir)) {
            $runtime->mkdir($dir, 0755);
        }
        $body = "[Service]\n"
            . "ExecReload=\n"
            . 'ExecReload=' . $config->caddyBin . ' reload --config ' . Config::CADDYFILE
            . ' --address ' . $address . " --force\n";
        $runtime->writeFile(self::DROPIN, $body, 0644);
        $runtime->exec(['/usr/bin/systemctl', 'daemon-reload'], null, 30);
    }

    private static function ensureActive(Runtime $runtime): void
    {
        $active = $runtime->exec(['/usr/bin/systemctl', 'is-active', 'caddy']);
        if ($active->ok()) {
            return;
        }
        fwrite(STDERR, "==> Caddy is not active; starting unit\n");
        $start = $runtime->exec(['/usr/bin/systemctl', 'start', 'caddy'], null, 60);
        $again = $runtime->exec(['/usr/bin/systemctl', 'is-active', 'caddy']);
        if (!$start->ok() || !$again->ok()) {
            throw new BrokerException('Could not start Caddy (systemctl start caddy failed).', 1);
        }
    }

    private static function ladder(Runtime $runtime, Config $config, string $address): string
    {
        $errors = [];

        $api = self::tryApi($runtime, $config, $address, false);
        if ($api !== null) {
            return $api;
        }
        $errors[] = 'admin API (' . $address . ') unreachable or reload failed';

        // ExecReload is the same `caddy reload --address` as the API (drop-in).
        // Retrying it after an API failure often blocks on the admin socket.
        fwrite(STDERR, "==> Skipping systemctl reload (same admin API as step 1)\n");

        $restart = self::tryRestart($runtime, false);
        if ($restart !== null) {
            return $restart;
        }
        $errors[] = 'systemctl restart caddy failed';

        throw new BrokerException(
            'All Caddy apply methods failed (' . implode('; ', $errors) . ').',
            1
        );
    }

    private static function tryApi(Runtime $runtime, Config $config, string $address, bool $required): ?string
    {
        fwrite(STDERR, "==> Applying via Caddy admin API ({$address})\n");
        $result = CaddyCli::reload($runtime, $config, $address);
        if ($result->ok()) {
            return 'api';
        }
        $detail = trim($result->stderr . "\n" . $result->stdout);
        if (self::isLiveConfigError($detail)) {
            throw new BrokerException(
                'Caddy rejected the live config: ' . ($detail !== '' ? $detail : 'HTTP 400'),
                1
            );
        }
        if ($required) {
            throw new BrokerException(
                'Caddy admin API reload failed at ' . $address . ($detail !== '' ? ': ' . $detail : '.'),
                1
            );
        }
        fwrite(STDERR, "==> Caddy admin API reload failed; trying next method\n");
        if ($detail !== '') {
            fwrite(STDERR, $detail . "\n");
        }
        return null;
    }

    private static function isLiveConfigError(string $detail): bool
    {
        $d = strtolower($detail);
        return str_contains($d, 'http 400')
            || str_contains($d, 'loading config')
            || str_contains($d, 'loading new config');
    }

    private static function trySystemdReload(Runtime $runtime, bool $required): ?string
    {
        fwrite(STDERR, "==> Applying via systemctl reload caddy\n");
        $result = $runtime->exec(['/usr/bin/systemctl', 'reload', 'caddy'], null, 60);
        if ($result->ok()) {
            return 'systemctl-reload';
        }
        $detail = trim($result->stderr . "\n" . $result->stdout);
        if ($required) {
            throw new BrokerException(
                'systemctl reload caddy failed' . ($detail !== '' ? ': ' . $detail : '.'),
                1
            );
        }
        return null;
    }

    private static function tryRestart(Runtime $runtime, bool $required): ?string
    {
        CaddyCli::reclaimDataDir($runtime);
        fwrite(STDERR, "==> Applying via systemctl restart caddy (brief connection drop)\n");
        $result = $runtime->exec(['/usr/bin/systemctl', 'restart', 'caddy'], null, 60);
        if ($result->ok()) {
            return 'restart';
        }
        $detail = trim($result->stderr . "\n" . $result->stdout);
        if ($required) {
            throw new BrokerException(
                'systemctl restart caddy failed' . ($detail !== '' ? ': ' . $detail : '.'),
                1
            );
        }
        return null;
    }

    /** @param list<int> $expectPorts */
    private static function assertHealthy(Runtime $runtime, array $expectPorts): void
    {
        $active = $runtime->exec(['/usr/bin/systemctl', 'is-active', 'caddy']);
        if (!$active->ok()) {
            throw new BrokerException('Caddy is not active after apply (systemctl is-active caddy failed).', 1);
        }
        foreach ($expectPorts as $port) {
            $port = (int) $port;
            if ($port < 1 || $port > 65535) {
                continue;
            }
            if (!self::portListening($runtime, $port)) {
                throw new BrokerException("Caddy is active but nothing is listening on port {$port} after apply.", 1);
            }
        }
    }

    private static function portListening(Runtime $runtime, int $port): bool
    {
        // Prefer /usr/sbin/ss (EL/Fedora); fall back to /usr/bin and /bin (Debian/Ubuntu).
        foreach (['/usr/sbin/ss', '/usr/bin/ss', '/bin/ss'] as $ss) {
            $result = $runtime->exec([$ss, '-tln']);
            if ($result->ok() && preg_match('/:' . $port . '(?:\s|$)/', $result->stdout) === 1) {
                return true;
            }
        }
        return false;
    }
}
