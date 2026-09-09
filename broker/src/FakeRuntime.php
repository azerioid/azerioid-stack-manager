<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

/**
 * In-memory runtime for unit tests. Never touches the real machine.
 */
final class FakeRuntime implements Runtime
{
    /** @var array<string,string> */
    public array $files = [];

    /** @var array<string,bool> */
    public array $dirs = [];

    /** @var list<array{command: array, stdin: ?string}> */
    public array $execLog = [];

    /** @var array<string, ExecResult> keyed by implode("\0", $command) */
    public array $execResponses = [];

    public ExecResult $defaultExec;

    /** @var list<array<string,mixed>> */
    public array $dbRows = [];

    public int $uid = 0;
    public string $clock = '2026-08-28T07:00:00+00:00';

    /** Fail the Nth dbExec (1-based). 0 = never. */
    public int $dbExecFailAt = 0;

    public int $dbExecCount = 0;

    /** @var list<string> */
    public array $installedPhp = ['8.3', '8.4'];

    /** @var list<string> */
    public array $dbExecLog = [];

    /** @var (callable(string, array): list<array<string,mixed>>)|null */
    public $dbQueryFn = null;

    public function __construct()
    {
        $this->defaultExec = new ExecResult(['true'], 0, '', '');
        $this->dirs['/'] = true;
        $this->dirs['/data'] = true;
        $this->dirs['/data/www'] = true;
        $this->dirs['/etc'] = true;
        $this->dirs['/etc/caddy'] = true;
        $this->dirs['/etc/caddy/conf.d'] = true;
        $this->dirs['/etc/php'] = true;
        $this->dirs['/etc/php/8.4'] = true;
        $this->dirs['/var'] = true;
        $this->dirs['/var/log/caddy'] = true;
        $this->dirs['/var/log/azerioid-panel'] = true;
        $this->dirs['/var/lib'] = true;
        $this->dirs['/var/lib/azerioid-panel'] = true;
        $this->dirs['/var/lib/azerioid-panel/staging'] = true;
        $this->dirs['/usr/local/lib'] = true;
        $this->dirs['/usr/local/lib/azerioid-panel'] = true;
        $this->dirs['/usr/local/lib/azerioid-panel/web'] = true;
        $this->dirs['/etc/systemd'] = true;
        $this->dirs['/etc/systemd/system'] = true;
    }

    public function exec(array $command, ?string $stdin = null, int $timeoutSeconds = 30): ExecResult
    {
        $this->execLog[] = ['command' => $command, 'stdin' => $stdin];
        $key = implode("\0", $command);
        return $this->execResponses[$key] ?? $this->defaultExec;
    }

    public function script(array $command, int $exit, string $stdout = '', string $stderr = ''): void
    {
        $this->execResponses[implode("\0", $command)] = new ExecResult($command, $exit, $stdout, $stderr);
    }

    public function readFile(string $path): string
    {
        if (!isset($this->files[$path])) {
            throw new BrokerException("Unable to read {$path}.", 1);
        }
        return $this->files[$path];
    }

    public function writeFile(string $path, string $contents, int $mode = 0644): void
    {
        $this->files[$path] = $contents;
        $this->dirs[dirname($path)] = true;
    }

    public function rename(string $from, string $to): void
    {
        if (!isset($this->files[$from])) {
            throw new BrokerException('Could not install the vhost configuration through the broker.', 1);
        }
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);
        $this->dirs[dirname($to)] = true;
    }

    public function deleteFile(string $path): void
    {
        unset($this->files[$path]);
    }

    public function fileExists(string $path): bool
    {
        return isset($this->files[$path]) || isset($this->dirs[$path]);
    }

    public function isDir(string $path): bool
    {
        return isset($this->dirs[$path]);
    }

    public function mkdir(string $path, int $mode = 0755): void
    {
        $this->dirs[$path] = true;
    }

    public function listDir(string $path): array
    {
        $path = rtrim($path, '/');
        $out = [];
        foreach (array_keys($this->dirs) as $dir) {
            if (dirname($dir) === $path && $dir !== $path) {
                $out[] = basename($dir);
            }
        }
        foreach (array_keys($this->files) as $file) {
            if (dirname($file) === $path) {
                $out[] = basename($file);
            }
        }
        return array_values(array_unique($out));
    }

    public function glob(string $pattern): array
    {
        $regex = '#^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '#')) . '$#';
        $out = [];
        foreach (array_keys($this->files) as $file) {
            if (preg_match($regex, $file)) {
                $out[] = $file;
            }
        }
        sort($out);
        return $out;
    }

    public function realPath(string $path): string
    {
        $resolved = realpath($path);
        return $resolved !== false ? $resolved : $path;
    }

    public function chmod(string $path, int $mode): void
    {
    }

    public function chown(string $path, string $user, string $group): void
    {
    }

    public function resolveUnderBase(string $path, string $base): ?string
    {
        $normalized = Validator::normalizeAbsolute($path);
        $baseN = Validator::normalizeAbsolute($base);
        if ($normalized === $baseN || str_starts_with($normalized . '/', $baseN . '/')) {
            return $normalized;
        }
        return null;
    }

    public function getuid(): int
    {
        return $this->uid;
    }

    public function now(): string
    {
        return $this->clock;
    }

    public function phpVersions(): array
    {
        return $this->installedPhp;
    }

    public function dbQuery(string $sql, array $params = []): array
    {
        $this->dbExecLog[] = $sql;
        if ($this->dbQueryFn !== null) {
            return ($this->dbQueryFn)($sql, $params);
        }
        return $this->dbRows;
    }

    public function dbExec(string $sql, array $params = []): int
    {
        $this->dbExecLog[] = $sql;
        $this->dbExecCount++;
        if ($this->dbExecFailAt > 0 && $this->dbExecCount === $this->dbExecFailAt) {
            throw new BrokerException('simulated MariaDB failure.', 1);
        }
        return 1;
    }
}
