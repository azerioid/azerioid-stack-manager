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

    /** @var array<string,int> chmod() calls, so tests can assert file modes */
    public array $modes = [];

    /** @var array<string,array{0:string,1:string}> chown() calls, path => [user, group] */
    public array $owners = [];

    /**
     * Side-effect hook for commands whose real effect this fake cannot reproduce
     * (e.g. `sqlite3 .backup` writing a file).
     *
     * @var (callable(list<string>, ?string):void)|null
     */
    public $execHook = null;

    /**
     * Stateful responder for tests that simulate a host (users, groups, permissions):
     * return an ExecResult to answer a command, or null to fall through to the scripted
     * responses.
     *
     * @var (callable(list<string>, ?string):?ExecResult)|null
     */
    public $execFn = null;

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
        if ($this->execHook !== null) {
            ($this->execHook)($command, $stdin);
        }
        if ($this->execFn !== null) {
            $answer = ($this->execFn)($command, $stdin);
            if ($answer !== null) {
                return $answer;
            }
        }
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

    public function gzReader(string $path): callable
    {
        if (!array_key_exists($path, $this->files)) {
            throw new BrokerException("File not found: {$path}", 1);
        }
        $raw = $this->files[$path];
        // Accept both gzipped and plain fixtures so tests can use either.
        $decoded = @gzdecode($raw);
        $body = is_string($decoded) ? $decoded : $raw;
        $pos = 0;

        return static function (int $n) use ($body, &$pos): string {
            if ($n <= 0) {
                return '';
            }
            $out = substr($body, $pos, $n);
            $pos += strlen($out);

            return $out;
        };
    }

    public function execReader(array $command, ?string $cwd = null, int $timeoutSeconds = 3600): array
    {
        $result = $this->exec($command, null, min($timeoutSeconds, 30));
        $body = $result->stdout;
        $pos = 0;

        return [
            'read' => static function (int $n) use ($body, &$pos): string {
                if ($n <= 0) {
                    return '';
                }
                $out = substr($body, $pos, $n);
                $pos += strlen($out);

                return $out;
            },
            'finish' => static fn (): ExecResult => new ExecResult($command, $result->exitCode, '', $result->stderr),
        ];
    }

    public function appendWriter(string $path, int $mode = 0600): callable
    {
        $this->files[$path] = '';
        $files = &$this->files;

        return static function (string $chunk) use (&$files, $path): void {
            if ($chunk === '') {
                return;
            }
            $files[$path] .= $chunk;
        };
    }

    public function appendFile(string $path, string $contents, int $mode = 0640): void
    {
        $this->files[$path] = ($this->files[$path] ?? '') . $contents;
    }

    public function rename(string $from, string $to): void
    {
        if (!isset($this->files[$from]) && isset($this->dirs[$from])) {
            // rename(2) moves a directory with everything under it.
            foreach ([&$this->files, &$this->dirs] as &$map) {
                foreach (array_keys($map) as $path) {
                    if ($path === $from || str_starts_with($path, $from . '/')) {
                        $map[$to . substr($path, strlen($from))] = $map[$path];
                        unset($map[$path]);
                    }
                }
            }
            unset($map);
            $this->dirs[dirname($to)] = true;

            return;
        }
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

    public function fileSize(string $path): int
    {
        if (!isset($this->files[$path])) {
            throw new BrokerException("Unable to size {$path}.", 1);
        }
        return strlen($this->files[$path]);
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
        $this->modes[$path] = $mode;
    }

    public function chown(string $path, string $user, string $group): void
    {
        $this->owners[$path] = [$user, $group];
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
