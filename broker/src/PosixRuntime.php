<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

use PDO;
use PDOException;

/**
 * Production runtime: real filesystem, proc_open argv (no shell), PDO MariaDB.
 */
final class PosixRuntime implements Runtime
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly ?string $mysqlSocket = null,
        private readonly ?string $mysqlUser = null,
        private readonly ?string $mysqlPassword = null,
    ) {
    }

    public function withDatabase(string $socket, string $user, string $password): self
    {
        return new self($socket, $user, $password);
    }

    public function exec(array $command, ?string $stdin = null, int $timeoutSeconds = 30): ExecResult
    {
        if ($command === []) {
            throw new BrokerException('Refusing to execute an empty command.', 1);
        }
        foreach ($command as $part) {
            if (!is_string($part)) {
                throw new BrokerException('Command argv must be strings.', 1);
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($command, $descriptors, $pipes, null, self::childEnv(), [
            'bypass_shell' => true,
        ]);
        if (!is_resource($proc)) {
            return new ExecResult($command, 127, '', 'Failed to spawn process.');
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new BrokerException('Process timed out.', 1);
            }
            usleep(20000);
        } while (true);

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return new ExecResult($command, (int) ($status['exitcode'] ?? $exit), $stdout, $stderr);
    }

    /**
     * systemd-run starts the broker with an empty environment. Child tools
     * need HOME/XDG so they do not write state into cwd. Prefer the env the
     * wrapper already set; otherwise Caddy's data dir if present, else the
     * panel state dir (LAMP has no /var/lib/caddy).
     *
     * @return array<string,string>
     */
    private static function childEnv(): array
    {
        $fallback = is_dir('/var/lib/caddy') ? '/var/lib/caddy' : '/var/lib/azerioid-panel';
        $home = getenv('HOME');
        if (! is_string($home) || $home === '' || $home === '/root') {
            $home = $fallback;
        }
        $path = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        $xdgConfig = getenv('XDG_CONFIG_HOME');
        $xdgData = getenv('XDG_DATA_HOME');
        if (! is_string($xdgConfig) || $xdgConfig === '' || str_starts_with($xdgConfig, '/root')) {
            $xdgConfig = $home . '/.config';
        }
        if (! is_string($xdgData) || $xdgData === '' || str_starts_with($xdgData, '/root')) {
            $xdgData = $home . '/.local/share';
        }

        return [
            'HOME' => $home,
            'XDG_CONFIG_HOME' => $xdgConfig,
            'XDG_DATA_HOME' => $xdgData,
            'PATH' => $path,
            'LC_ALL' => 'C',
        ];
    }

    public function readFile(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new BrokerException("Unable to read {$path}.", 1);
        }
        return $data;
    }

    public function writeFile(string $path, string $contents, int $mode = 0644): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new BrokerException("Directory does not exist: {$dir}", 1);
        }
        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new BrokerException(self::describeIoFailure('write', $path, error_get_last()), 1);
        }
        @chmod($path, $mode);
    }

    public function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            @unlink($from);
            throw new BrokerException(self::describeIoFailure('install', $to, error_get_last()), 1);
        }
    }

    public function deleteFile(string $path): void
    {
        if (is_file($path) && !@unlink($path)) {
            throw new BrokerException(self::describeIoFailure('delete', $path, error_get_last()), 1);
        }
    }

    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    public function isDir(string $path): bool
    {
        return is_dir($path);
    }

    public function mkdir(string $path, int $mode = 0755): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new BrokerException(self::describeIoFailure('create directory', $path, error_get_last()), 1);
        }
    }

    public function listDir(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }
        $entries = scandir($path);
        if ($entries === false) {
            return [];
        }
        return array_values(array_filter($entries, static fn ($e) => $e !== '.' && $e !== '..'));
    }

    public function glob(string $pattern): array
    {
        $matches = glob($pattern) ?: [];
        sort($matches);
        return $matches;
    }

    public function realPath(string $path): string
    {
        $resolved = realpath($path);
        return $resolved !== false ? $resolved : $path;
    }

    public function chmod(string $path, int $mode): void
    {
        @chmod($path, $mode);
    }

    public function chown(string $path, string $user, string $group): void
    {
        @chown($path, $user);
        @chgrp($path, $group);
    }

    public function resolveUnderBase(string $path, string $base): ?string
    {
        $baseReal = realpath($base) ?: $base;
        $baseReal = rtrim($baseReal, '/');

        if (file_exists($path)) {
            $real = realpath($path);
            if ($real === false) {
                return null;
            }
            if ($real === $baseReal || str_starts_with($real, $baseReal . '/')) {
                return $real;
            }
            return null;
        }

        $current = $path;
        while ($current !== '/' && $current !== '' && !file_exists($current)) {
            $current = dirname($current);
        }
        $parentReal = realpath($current);
        if ($parentReal === false) {
            return null;
        }
        if ($parentReal !== $baseReal && !str_starts_with($parentReal, $baseReal . '/')) {
            return null;
        }

        $suffix = substr($path, strlen($current));
        return rtrim($parentReal, '/') . $suffix;
    }

    public function getuid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    }

    public function now(): string
    {
        return gmdate('c');
    }

    public function phpVersions(): array
    {
        $versions = [];
        if (is_dir('/etc/php')) {
            foreach (scandir('/etc/php') ?: [] as $entry) {
                if (preg_match('/^[0-9]+\.[0-9]+$/', $entry) && is_dir('/etc/php/' . $entry)) {
                    $versions[] = $entry;
                }
            }
        }
        if (is_dir('/etc/opt/remi')) {
            foreach (scandir('/etc/opt/remi') ?: [] as $entry) {
                if (preg_match('/^php([0-9])([0-9]+)$/', $entry, $m) && is_dir('/etc/opt/remi/' . $entry)) {
                    $versions[] = $m[1] . '.' . $m[2];
                }
            }
        }
        if (is_executable('/usr/bin/php')) {
            $out = [];
            @exec('/usr/bin/php -r "echo PHP_MAJOR_VERSION.\'.\'.PHP_MINOR_VERSION;"', $out);
            $ver = trim(implode('', $out));
            if (preg_match('/^[0-9]+\.[0-9]+$/', $ver)) {
                $versions[] = $ver;
            }
        }
        $versions = array_values(array_unique($versions));
        sort($versions, SORT_NATURAL);
        return $versions;
    }

    public function dbQuery(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            throw new BrokerException(self::describePdo($e), 1);
        }
    }

    public function dbExec(string $sql, array $params = []): int
    {
        try {
            if ($params !== [] && self::isPasswordDdl($sql) && count($params) === 1) {
                $quoted = $this->pdo()->quote((string) $params[0]);
                if ($quoted === false) {
                    throw new BrokerException('MariaDB could not quote the password.', 1);
                }
                $sql = preg_replace('/\?/', $quoted, $sql, 1) ?? $sql;
                $params = [];
            }
            if ($params === []) {
                $n = $this->pdo()->exec($sql);
                return $n === false ? 0 : (int) $n;
            }
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (BrokerException $e) {
            throw $e;
        } catch (PDOException $e) {
            throw new BrokerException(self::describePdo($e), 1);
        }
    }

    private static function isPasswordDdl(string $sql): bool
    {
        return (bool) preg_match('/^\s*(CREATE|ALTER)\s+USER\b/i', $sql)
            && (stripos($sql, 'IDENTIFIED BY') !== false || stripos($sql, 'IDENTIFIED VIA') !== false);
    }

    public static function describePdo(PDOException $e): string
    {
        $msg = $e->getMessage();
        $msg = preg_replace("/IDENTIFIED BY\\s+'[^']*'/i", 'IDENTIFIED BY [redacted]', $msg) ?? $msg;
        $msg = preg_replace('/IDENTIFIED BY\\s+"[^"]*"/i', 'IDENTIFIED BY [redacted]', $msg) ?? $msg;
        $msg = preg_replace("/USING\\s+'[^']*'/i", 'USING [redacted]', $msg) ?? $msg;
        if (str_contains($msg, "near '?'")) {
            return 'MariaDB rejected a bound parameter in CREATE/ALTER USER; the broker quotes the password for that statement.';
        }
        return 'MariaDB error: ' . $msg;
    }

    /**
     * @param  array{message?: string}|null  $last
     */
    public static function describeIoFailure(string $op, string $path, ?array $last): string
    {
        $msg = is_array($last) ? (string) ($last['message'] ?? '') : '';
        $leaf = basename($path);
        if (str_contains($msg, 'open_basedir')) {
            return 'Broker PHP is restricted by open_basedir and cannot ' . $op . ' Caddy config. Re-run the panel installer so the broker wrapper is installed.';
        }
        if (stripos($msg, 'read-only file system') !== false || stripos($msg, 'readonly file system') !== false) {
            return 'the config directory is read-only for the broker context; expected the broker to leave the PHP-FPM ProtectSystem sandbox (systemd-run) or ReadWritePaths on the php-fpm unit for the vhost directory. Re-run the panel installer.';
        }
        if ($msg !== '') {
            return 'Broker could not ' . $op . ' ' . $leaf . ': ' . $msg;
        }
        return 'Broker could not ' . $op . ' ' . $leaf . ' (permission denied or missing directory).';
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if ($this->mysqlSocket === null || $this->mysqlUser === null || $this->mysqlPassword === null) {
            throw new BrokerException('MariaDB credentials are not configured on the broker.', 1);
        }
        try {
            $dsn = 'mysql:unix_socket=' . $this->mysqlSocket . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, $this->mysqlUser, $this->mysqlPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new BrokerException(self::describePdo($e), 1);
        }
        return $this->pdo;
    }
}
