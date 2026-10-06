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
     * panel state dir (Apache-only stacks have no /var/lib/caddy).
     *
     * @return array<string,string>
     */
    private static function childEnv(): array
    {
        // A47: HOME for root children must be a root-owned directory. /var/lib/caddy
        // is owned by the (lower-trust) Caddy service user, so git/curl/gpg run as
        // root there would read config that account can write. Use the root-owned
        // broker home and neutralise per-user tool config explicitly. The caddy CLI
        // sets its own HOME via CaddyCli::dataEnvPrefix, so it does not need this.
        $home = '/var/lib/azerioid-broker';
        // Best-effort ensure the root-owned home exists (broker runs as root).
        // Suppressed: if we cannot create it, tools simply find no config there,
        // which is the safe outcome; never fail an action over HOME setup.
        if (! is_dir($home) && @mkdir($home, 0700, true)) {
            @chmod($home, 0700);
        }
        $path = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        return [
            'HOME' => $home,
            'XDG_CONFIG_HOME' => $home . '/.config',
            'XDG_DATA_HOME' => $home . '/.local/share',
            'PATH' => $path,
            'LC_ALL' => 'C',
            // Stop root git/curl/gpg from loading attacker-writable per-user config.
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'CURL_HOME' => $home,
            'GNUPGHOME' => $home . '/.gnupg',
            'PYTHONNOUSERSITE' => '1',
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
        // A47: create the file at its final mode before writing, so a secret
        // (sasl_passwd, Dovecot hashes) is never briefly world-readable. Write a
        // private temp file in the same directory, then rename over the target.
        // tempnam creates 0600; narrow/relax to the requested mode before content.
        $tmp = @tempnam($dir, '.azw');
        if ($tmp === false) {
            throw new BrokerException(self::describeIoFailure('write', $path, error_get_last()), 1);
        }
        @chmod($tmp, $mode);
        if (@file_put_contents($tmp, $contents) === false) {
            @unlink($tmp);
            throw new BrokerException(self::describeIoFailure('write', $path, error_get_last()), 1);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new BrokerException(self::describeIoFailure('write', $path, error_get_last()), 1);
        }
        @chmod($path, $mode);
    }

    public function gzReader(string $path): callable
    {
        $fh = @gzopen($path, 'rb');
        if ($fh === false) {
            throw new BrokerException(self::describeIoFailure('read', $path, error_get_last()), 1);
        }

        return static function (int $n) use ($fh): string {
            if ($n <= 0) {
                return '';
            }
            $buf = '';
            while (strlen($buf) < $n) {
                $part = gzread($fh, $n - strlen($buf));
                if ($part === false || $part === '') {
                    break;
                }
                $buf .= $part;
            }
            if ($buf === '') {
                gzclose($fh);
            }

            return $buf;
        };
    }

    public function execReader(array $command, ?string $cwd = null, int $timeoutSeconds = 3600): array
    {
        if ($command === []) {
            throw new BrokerException('Refusing to execute an empty command.', 1);
        }
        foreach ($command as $part) {
            if (!is_string($part)) {
                throw new BrokerException('Command argv must be strings.', 1);
            }
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $descriptors, $pipes, $cwd, self::childEnv(), ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new BrokerException('Failed to spawn process.', 1);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeoutSeconds;
        $stderr = '';

        $read = static function (int $n) use ($pipes, &$stderr, $deadline): string {
            if ($n <= 0) {
                return '';
            }
            // Drain stderr opportunistically so a chatty child cannot deadlock on
            // a full pipe while we are only reading stdout.
            $err = stream_get_contents($pipes[2]);
            if (is_string($err) && $err !== '') {
                $stderr .= $err;
            }
            if (microtime(true) > $deadline) {
                throw new BrokerException('Command timed out while streaming output.', 1);
            }
            $chunk = fread($pipes[1], $n);

            return $chunk === false ? '' : $chunk;
        };

        $finish = static function () use ($proc, $pipes, &$stderr, $command): ExecResult {
            $rest = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if (is_string($err) && $err !== '') {
                $stderr .= $err;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            return new ExecResult($command, $code, is_string($rest) ? $rest : '', $stderr);
        };

        return ['read' => $read, 'finish' => $finish];
    }

    public function appendWriter(string $path, int $mode = 0600): callable
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new BrokerException("Directory does not exist: {$dir}", 1);
        }
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new BrokerException(self::describeIoFailure('write', $path, error_get_last()), 1);
        }
        @chmod($path, $mode);

        return static function (string $chunk) use (&$fh, $path): void {
            if ($fh === null) {
                return;
            }
            if ($chunk === '') {
                fflush($fh);
                fclose($fh);
                $fh = null;

                return;
            }
            if (fwrite($fh, $chunk) === false) {
                fclose($fh);
                $fh = null;
                throw new BrokerException('Failed writing to ' . $path . '.', 1);
            }
        };
    }

    public function appendFile(string $path, string $contents, int $mode = 0640): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new BrokerException("Directory does not exist: {$dir}", 1);
        }
        $existed = file_exists($path);
        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            throw new BrokerException(self::describeIoFailure('append', $path, error_get_last()), 1);
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new BrokerException('Could not lock ' . $path . ' for append.', 1);
            }
            try {
                if (fwrite($fh, $contents) === false) {
                    throw new BrokerException('Failed appending to ' . $path . '.', 1);
                }
                fflush($fh);
            } finally {
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
        if (!$existed) {
            @chmod($path, $mode);
        }
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

    public function fileSize(string $path): int
    {
        if (!is_file($path)) {
            throw new BrokerException("Unable to size {$path}.", 1);
        }
        $size = filesize($path);
        if ($size === false) {
            throw new BrokerException("Unable to size {$path}.", 1);
        }
        return (int) $size;
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
