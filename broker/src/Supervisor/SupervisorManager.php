<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Supervisor;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class SupervisorManager
{
    public const CONF_PREFIX = 'azerioid-';
    public const CONF_DIR = '/etc/supervisor/conf.d';

    /** @var list<string> */
    private const FORBIDDEN_RUN_USERS = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp',
        'proxy', 'www-data', 'backup', 'list', 'irc', 'gnats', 'nobody', 'systemd-network',
        'systemd-resolve', 'messagebus', 'sshd', 'caddy', 'apache', 'nginx', 'mysql', 'postgres',
        'mongodb', 'redis', 'memcached', 'supervisor',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function assertInstalled(): void
    {
        $managed = ManagedManifest::load($this->runtime, $this->config->managedComponentsPath);
        if (!$managed->has('supervisor')) {
            throw new BrokerException('Supervisor is not installed. Install it from Components first.', 3);
        }
    }

    /**
     * @return array{programs: list<array<string, mixed>>}
     */
    public function listPrograms(): array
    {
        $this->assertInstalled();
        $meta = $this->loadMetadata();
        $programs = [];
        foreach ($meta['programs'] as $name => $row) {
            $programs[] = array_merge($row, [
                'name' => $name,
                'supervisor_name' => self::CONF_PREFIX . $name,
                'status' => $this->programStatus(self::CONF_PREFIX . $name),
            ]);
        }
        usort($programs, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return ['programs' => $programs];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $name): array
    {
        $name = Validator::supervisorProgramName($name);
        $this->assertProgramExists($name);

        return [
            'name' => $name,
            'supervisor_name' => self::CONF_PREFIX . $name,
            'status' => $this->programStatus(self::CONF_PREFIX . $name),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $this->assertInstalled();
        SupervisedUser::ensure($this->runtime);

        $name = Validator::supervisorProgramName((string) ($input['name'] ?? ''));
        $meta = $this->loadMetadata();
        if (isset($meta['programs'][$name])) {
            throw new BrokerException("Supervisor program {$name} already exists.", 2);
        }

        $spec = $this->buildSpec($name, $input, null);
        $vhost = $this->optionalVhostDomain($input);
        if ($vhost !== null) {
            $spec['vhost_domain'] = $vhost;
            $this->grantDirectoryAccess($spec['directory']);
        }

        $this->writeProgramConfig($name, $spec, null);

        $meta['programs'][$name] = $this->metadataRow($name, $spec);
        $this->saveMetadata($meta);

        return ['created' => true, 'name' => $name, 'program' => $meta['programs'][$name]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $name, array $input): array
    {
        $name = Validator::supervisorProgramName($name);
        $this->assertInstalled();
        $meta = $this->loadMetadata();
        if (!isset($meta['programs'][$name])) {
            throw new BrokerException("Supervisor program {$name} not found.", 2);
        }

        $before = $meta['programs'][$name];
        $spec = $this->buildSpec($name, $input, $before);
        if (array_key_exists('vhost_domain', $input)) {
            $spec['vhost_domain'] = $this->optionalVhostDomain($input);
        } else {
            $spec['vhost_domain'] = $before['vhost_domain'] ?? null;
        }
        if ($spec['vhost_domain'] !== null) {
            $this->grantDirectoryAccess($spec['directory']);
        }

        $this->writeProgramConfig($name, $spec, $before);

        $meta['programs'][$name] = $this->metadataRow($name, $spec);
        $this->saveMetadata($meta);

        return ['updated' => true, 'name' => $name, 'before' => $before, 'after' => $meta['programs'][$name]];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $name, bool $stopFirst = true): array
    {
        $name = Validator::supervisorProgramName($name);
        $this->assertInstalled();
        $meta = $this->loadMetadata();
        if (!isset($meta['programs'][$name])) {
            throw new BrokerException("Supervisor program {$name} not found.", 2);
        }

        $confPath = $this->confPath($name);
        $backup = $this->runtime->fileExists($confPath) ? $this->runtime->readFile($confPath) : null;

        if ($stopFirst) {
            $this->supervisorctl(['stop', self::CONF_PREFIX . $name], allowMissing: true);
        }
        $this->supervisorctl(['remove', self::CONF_PREFIX . $name], allowMissing: true);

        if ($this->runtime->fileExists($confPath)) {
            $this->runtime->deleteFile($confPath);
        }
        $this->supervisorctl(['reread'], allowFailure: false);
        $update = $this->supervisorctl(['update'], allowFailure: true);

        unset($meta['programs'][$name]);
        $this->saveMetadata($meta);

        return [
            'deleted' => true,
            'name' => $name,
            'update' => trim($update->stdout . "\n" . $update->stderr),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function control(string $name, string $verb): array
    {
        $name = Validator::supervisorProgramName($name);
        $this->assertProgramExists($name);
        $allowed = ['start', 'stop', 'restart'];
        if (!in_array($verb, $allowed, true)) {
            throw new BrokerException('Invalid supervisor control verb.', 2);
        }

        $result = $this->supervisorctl([$verb, self::CONF_PREFIX . $name]);

        return [
            'name' => $name,
            'action' => $verb,
            'output' => trim($result->stdout . "\n" . $result->stderr),
            'status' => $this->programStatus(self::CONF_PREFIX . $name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function logs(string $name, int $lines = 100): array
    {
        $name = Validator::supervisorProgramName($name);
        $row = $this->assertProgramExists($name);
        $stdoutPath = (string) ($row['log_stdout'] ?? '');
        $stderrPath = (string) ($row['log_stderr'] ?? '');

        return [
            'name' => $name,
            'stdout' => $this->tailFile($stdoutPath, $lines),
            'stderr' => $this->tailFile($stderrPath, $lines),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function programsForVhost(string $domain): array
    {
        $domain = Validator::domain($domain);
        $meta = $this->loadMetadata();
        $out = [];
        foreach ($meta['programs'] as $name => $row) {
            if (($row['vhost_domain'] ?? null) === $domain) {
                $out[] = array_merge($row, ['name' => $name]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function buildSpec(string $name, array $input, ?array $existing): array
    {
        $command = array_key_exists('command', $input)
            ? Validator::supervisorCommand((string) $input['command'])
            : (string) ($existing['command'] ?? '');
        if ($command === '') {
            throw new BrokerException('command is required.', 2);
        }

        $directory = array_key_exists('directory', $input)
            ? Validator::supervisedDirectory((string) $input['directory'], $this->config->wwwRoot, $this->runtime)
            : (string) ($existing['directory'] ?? '');
        if ($directory === '') {
            throw new BrokerException('directory (working directory) is required.', 2);
        }

        $autostart = array_key_exists('autostart', $input)
            ? self::boolInput($input['autostart'])
            : (bool) ($existing['autostart'] ?? true);
        $autorestart = array_key_exists('autorestart', $input)
            ? self::boolInput($input['autorestart'])
            : (bool) ($existing['autorestart'] ?? true);

        if (array_key_exists('user', $input) || array_key_exists('run_as', $input)) {
            $requested = strtolower(trim((string) ($input['user'] ?? $input['run_as'] ?? '')));
            self::rejectRunUser($requested !== '' ? $requested : 'root');
        }

        return [
            'command' => $command,
            'directory' => $directory,
            'user' => SupervisedUser::USERNAME,
            'autostart' => $autostart,
            'autorestart' => $autorestart,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>|null  $previousMeta
     */
    private function writeProgramConfig(string $name, array $spec, ?array $previousMeta): void
    {
        $confPath = $this->confPath($name);
        $previousContents = $this->runtime->fileExists($confPath) ? $this->runtime->readFile($confPath) : null;
        $contents = $this->renderConfig($name, $spec);

        $this->runtime->writeFile($confPath, $contents, 0644);

        $reread = $this->supervisorctl(['reread']);
        if (!$reread->ok() || str_contains(strtolower($reread->stdout . $reread->stderr), 'error')) {
            if ($previousContents !== null) {
                $this->runtime->writeFile($confPath, $previousContents, 0644);
            } else {
                $this->runtime->deleteFile($confPath);
            }
            $this->supervisorctl(['reread'], allowFailure: true);
            throw new BrokerException(
                'Supervisor rejected the program config; rolled back. ' . trim($reread->stderr . "\n" . $reread->stdout),
                1
            );
        }

        $update = $this->supervisorctl(['update', self::CONF_PREFIX . $name], allowFailure: true);
        if (!$update->ok()) {
            if ($previousContents !== null) {
                $this->runtime->writeFile($confPath, $previousContents, 0644);
            } else {
                $this->runtime->deleteFile($confPath);
            }
            $this->supervisorctl(['reread'], allowFailure: true);
            $this->supervisorctl(['update'], allowFailure: true);
            throw new BrokerException(
                'Supervisor failed to apply the program; rolled back. ' . trim($update->stderr . "\n" . $update->stdout),
                1
            );
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function renderConfig(string $name, array $spec): string
    {
        $supervisorName = self::CONF_PREFIX . $name;
        $stdout = SupervisedUser::LOG_DIR . '/' . $name . '.stdout.log';
        $stderr = SupervisedUser::LOG_DIR . '/' . $name . '.stderr.log';
        $autostart = ($spec['autostart'] ?? true) ? 'true' : 'false';
        $autorestart = ($spec['autorestart'] ?? true) ? 'true' : 'false';
        $user = SupervisedUser::USERNAME;
        self::rejectRunUser($user);

        return <<<INI
; AZERIOID Stack Manager — managed supervisor program (do not edit manually)
[program:{$supervisorName}]
command={$spec['command']}
directory={$spec['directory']}
user={$user}
autostart={$autostart}
autorestart={$autorestart}
stdout_logfile={$stdout}
stderr_logfile={$stderr}
stdout_logfile_maxbytes=5MB
stderr_logfile_maxbytes=5MB
stopasgroup=true
killasgroup=true

INI;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function metadataRow(string $name, array $spec): array
    {
        return [
            'command' => $spec['command'],
            'directory' => $spec['directory'],
            'user' => SupervisedUser::USERNAME,
            'autostart' => (bool) ($spec['autostart'] ?? true),
            'autorestart' => (bool) ($spec['autorestart'] ?? true),
            'vhost_domain' => $spec['vhost_domain'] ?? null,
            'log_stdout' => SupervisedUser::LOG_DIR . '/' . $name . '.stdout.log',
            'log_stderr' => SupervisedUser::LOG_DIR . '/' . $name . '.stderr.log',
            'updated_at' => $this->runtime->now(),
        ];
    }

    /**
     * @return array{programs: array<string, array<string, mixed>>}
     */
    private function loadMetadata(): array
    {
        $path = $this->metadataPath();
        if (!$this->runtime->fileExists($path)) {
            return ['programs' => []];
        }
        $raw = $this->runtime->readFile($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['programs']) || !is_array($decoded['programs'])) {
            return ['programs' => []];
        }

        return ['programs' => $decoded['programs']];
    }

    /**
     * @param  array{programs: array<string, array<string, mixed>>}  $meta
     */
    private function saveMetadata(array $meta): void
    {
        $path = $this->metadataPath();
        $this->runtime->mkdir(dirname($path), 0750);
        $this->runtime->writeFile($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0640);
    }

    private function metadataPath(): string
    {
        return dirname($this->config->managedComponentsPath) . '/supervised-programs.json';
    }

    private function confPath(string $name): string
    {
        [$dir, $ext] = $this->confDirAndExt();
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0755);
        }

        return $dir . '/' . self::CONF_PREFIX . $name . $ext;
    }

    /** @return array{0:string,1:string} */
    private function confDirAndExt(): array
    {
        // EL: /etc/supervisord.conf includes supervisord.d/*.ini
        if ($this->runtime->fileExists('/etc/supervisord.conf') && $this->runtime->isDir('/etc/supervisord.d')) {
            return ['/etc/supervisord.d', '.ini'];
        }

        return [self::CONF_DIR, '.conf'];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertProgramExists(string $name): array
    {
        $this->assertInstalled();
        $meta = $this->loadMetadata();
        if (!isset($meta['programs'][$name])) {
            throw new BrokerException("Supervisor program {$name} not found.", 2);
        }

        return $meta['programs'][$name];
    }

    /**
     * @param  array<int, string>  $args
     */
    private function supervisorctl(array $args, bool $allowFailure = false, bool $allowMissing = false): \AzerioidPanel\Broker\ExecResult
    {
        $cmd = array_merge(['/usr/bin/supervisorctl'], $args);
        $result = $this->runtime->exec($cmd, null, 60);
        if (!$allowFailure && !$result->ok()) {
            $msg = trim($result->stderr . "\n" . $result->stdout);
            if ($allowMissing && (str_contains(strtolower($msg), 'not running') || str_contains(strtolower($msg), 'unreachable'))) {
                return $result;
            }
            throw new BrokerException('supervisorctl failed: ' . $msg, 1);
        }

        return $result;
    }

    /**
     * @return array{state: string, raw: string}
     */
    private function programStatus(string $supervisorName): array
    {
        $result = $this->supervisorctl(['status', $supervisorName], allowFailure: true, allowMissing: true);
        $raw = trim($result->stdout . "\n" . $result->stderr);
        if ($raw === '' || str_contains(strtolower($raw), 'no such process')) {
            return ['state' => 'unknown', 'raw' => $raw];
        }
        if (preg_match('/\b(RUNNING|STOPPED|STARTING|BACKOFF|EXITED|FATAL|UNKNOWN)\b/', $raw, $m)) {
            return ['state' => strtolower($m[1]), 'raw' => $raw];
        }

        return ['state' => $result->ok() ? 'running' : 'stopped', 'raw' => $raw];
    }

    private function tailFile(string $path, int $lines): string
    {
        if ($path === '' || !$this->runtime->fileExists($path)) {
            return '';
        }
        $result = $this->runtime->exec(['/usr/bin/tail', '-n', (string) $lines, $path], null, 15);
        if (!$result->ok()) {
            return trim($result->stderr);
        }

        return trim($result->stdout);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function optionalVhostDomain(array $input): ?string
    {
        if (!array_key_exists('vhost_domain', $input)) {
            return null;
        }
        $raw = trim((string) $input['vhost_domain']);
        if ($raw === '') {
            return null;
        }

        return Validator::domain($raw);
    }

    public static function rejectRunUser(string $user): void
    {
        $user = strtolower(trim($user));
        if ($user === '' || in_array($user, self::FORBIDDEN_RUN_USERS, true)) {
            throw new BrokerException('Refusing privileged or disallowed run-as user for supervisor programs.', 3);
        }
        if ($user !== SupervisedUser::USERNAME) {
            throw new BrokerException(
                'Only the dedicated supervised user (' . SupervisedUser::USERNAME . ') may run panel-managed processes.',
                3
            );
        }
    }

    private static function boolInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new BrokerException('Expected a boolean value.', 2),
        };
    }

    private function grantDirectoryAccess(string $directory): void
    {
        if ($this->runtime->getuid() !== 0) {
            return;
        }
        $user = SupervisedUser::USERNAME;
        $this->runtime->exec([
            '/usr/bin/setfacl', '-m', 'u:' . $user . ':rwx',
            '-m', 'd:u:' . $user . ':rwx',
            $directory,
        ], null, 15);
    }
}
