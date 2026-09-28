<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Supervisor\SupervisedUser;

/**
 * Per-vhost Docker workload settings (ADR A50): which compose service gets the site's port,
 * restart policy, bind-mounted data directories, container environment, private registry.
 *
 * The broker owns these as files, not the panel database, for the reason A47 gives for cron:
 * the thing that runs must be the thing that is true. Supervisor restarts a container without
 * asking the panel, so the environment it starts with has to be on the host already.
 *
 *   /var/lib/azerioid-docker/                 root:azerioid-supervised 0750
 *     <slug>/                                 root:azerioid-supervised 0750
 *       settings.json                         root 0600   service, restart, volumes, registry
 *       env                                   root:azerioid-supervised 0640   docker --env-file
 *       ports.yml                             root:azerioid-supervised 0640   compose override
 *
 * The rendered files are readable by azerioid-supervised because the docker CLI runs as that
 * account; not writable by it, so a container with a bind mount cannot rewrite its own
 * configuration. That the env file is readable by the shared supervised account is within
 * A38's accepted blast radius: `docker inspect` already shows every container's environment
 * to that account.
 */
final class DockerSettings
{
    public const BASE = '/var/lib/azerioid-docker';

    public const RESTART_ALWAYS = 'always';
    public const RESTART_ON_FAILURE = 'on-failure';
    public const RESTART_NEVER = 'never';

    public const MAX_VOLUMES = 5;
    public const MAX_ENV = 100;
    public const MAX_ENV_VALUE = 8192;

    public static function dir(string $domain): string
    {
        return self::BASE . '/' . self::slug($domain);
    }

    public static function envPath(string $domain): string
    {
        return self::dir($domain) . '/env';
    }

    public static function portsOverridePath(string $domain): string
    {
        return self::dir($domain) . '/ports.yml';
    }

    private static function settingsPath(string $domain): string
    {
        return self::dir($domain) . '/settings.json';
    }

    /**
     * @return array{service:?string, restart:string, volumes:list<array{host:string, container:string, readonly:bool}>, registry:?string}
     */
    public static function load(Runtime $runtime, string $domain): array
    {
        $defaults = ['service' => null, 'restart' => self::RESTART_ALWAYS, 'volumes' => [], 'registry' => null];
        $path = self::settingsPath($domain);
        if (!$runtime->fileExists($path)) {
            return $defaults;
        }
        $decoded = json_decode($runtime->readFile($path), true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return [
            'service' => isset($decoded['service']) && is_string($decoded['service']) ? $decoded['service'] : null,
            'restart' => in_array($decoded['restart'] ?? null, [self::RESTART_ALWAYS, self::RESTART_ON_FAILURE, self::RESTART_NEVER], true)
                ? $decoded['restart'] : self::RESTART_ALWAYS,
            'volumes' => array_values(array_filter(
                is_array($decoded['volumes'] ?? null) ? $decoded['volumes'] : [],
                static fn ($v): bool => is_array($v) && is_string($v['host'] ?? null) && is_string($v['container'] ?? null)
            )),
            'registry' => isset($decoded['registry']) && is_string($decoded['registry']) ? $decoded['registry'] : null,
        ];
    }

    /** @param array<string,mixed> $settings */
    public static function save(Runtime $runtime, string $domain, array $settings): void
    {
        self::ensureDir($runtime, $domain);
        $runtime->writeFile(
            self::settingsPath($domain),
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0600
        );
    }

    /** @return array<string,string> */
    public static function loadEnv(Runtime $runtime, string $domain): array
    {
        $path = self::envPath($domain);
        if (!$runtime->fileExists($path)) {
            return [];
        }
        $env = [];
        foreach (explode("\n", $runtime->readFile($path)) as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[$key] = $value;
        }

        return $env;
    }

    /** @param array<string,string> $env already validated */
    public static function saveEnv(Runtime $runtime, string $domain, array $env): void
    {
        self::ensureDir($runtime, $domain);
        $body = '';
        foreach ($env as $key => $value) {
            $body .= $key . '=' . $value . "\n";
        }
        self::writeShared($runtime, self::envPath($domain), $body);
    }

    /** Write a file the docker CLI (azerioid-supervised) reads but cannot change. */
    public static function writeShared(Runtime $runtime, string $path, string $body): void
    {
        $runtime->writeFile($path, $body, 0640);
        if ($runtime->getuid() === 0) {
            $runtime->chown($path, 'root', SupervisedUser::USERNAME);
            $runtime->chmod($path, 0640);
        }
    }

    public static function ensureDir(Runtime $runtime, string $domain): void
    {
        foreach ([self::BASE, self::dir($domain)] as $dir) {
            if (!$runtime->isDir($dir)) {
                $runtime->mkdir($dir, 0750);
            }
            if ($runtime->getuid() === 0) {
                $runtime->chown($dir, 'root', SupervisedUser::USERNAME);
                $runtime->chmod($dir, 0750);
            }
        }
    }

    public static function remove(Runtime $runtime, string $domain): void
    {
        foreach ([self::settingsPath($domain), self::envPath($domain), self::portsOverridePath($domain)] as $path) {
            if ($runtime->fileExists($path)) {
                $runtime->deleteFile($path);
            }
        }
        $dir = self::dir($domain);
        if ($runtime->isDir($dir)) {
            $runtime->exec(['/bin/rmdir', $dir], null, 10);
        }
    }

    // ------------------------------------------------------------ validation

    public static function validateService(mixed $value): string
    {
        $service = trim((string) $value);
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/', $service)) {
            throw new BrokerException('service must be a compose service name (letters, digits, "_", ".", "-").', 2);
        }

        return $service;
    }

    public static function validateRestart(mixed $value): string
    {
        $restart = strtolower(trim((string) $value));
        if (!in_array($restart, [self::RESTART_ALWAYS, self::RESTART_ON_FAILURE, self::RESTART_NEVER], true)) {
            throw new BrokerException('restart must be always, on-failure or never.', 2);
        }

        return $restart;
    }

    /** Supervisor's autorestart for a restart policy: the container runs in the foreground under it. */
    public static function autorestart(string $restart): bool|string
    {
        return match ($restart) {
            self::RESTART_NEVER => false,
            self::RESTART_ON_FAILURE => 'unexpected',
            default => true,
        };
    }

    /**
     * @return list<array{host:string, container:string, readonly:bool}>
     */
    public static function validateVolumes(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new BrokerException('volumes must be a list.', 2);
        }
        if (count($value) > self::MAX_VOLUMES) {
            throw new BrokerException('At most ' . self::MAX_VOLUMES . ' volumes.', 2);
        }
        $out = [];
        $seen = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new BrokerException('Each volume needs host and container paths.', 2);
            }
            $host = rtrim(trim((string) ($row['host'] ?? '')), '/');
            if (str_starts_with($host, '/')) {
                throw new BrokerException('Volume host path must be relative to the app directory, not ' . $host . '.', 2);
            }
            $container = rtrim(trim((string) ($row['container'] ?? '')), '/');
            if ($host === '' || !preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $host) || strlen($host) > 100
                || preg_match('#(^|/)\.\.?(/|$)#', $host)) {
                throw new BrokerException('Volume host path must be a relative directory inside the app (letters, digits, ".", "_", "-").', 2);
            }
            if (!preg_match('#^(/[A-Za-z0-9._-]+)+$#', $container) || strlen($container) > 100
                || preg_match('#(^|/)\.\.?(/|$)#', $container)) {
                throw new BrokerException('Volume container path must be an absolute path (letters, digits, ".", "_", "-").', 2);
            }
            if (isset($seen[$container])) {
                throw new BrokerException("Two volumes mount at {$container}.", 2);
            }
            $seen[$container] = true;
            $out[] = ['host' => $host, 'container' => $container, 'readonly' => (bool) ($row['readonly'] ?? false)];
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    public static function validateEnv(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            throw new BrokerException('env must be a map of NAME to value.', 2);
        }
        if (count($value) > self::MAX_ENV) {
            throw new BrokerException('At most ' . self::MAX_ENV . ' environment variables.', 2);
        }
        $out = [];
        foreach ($value as $key => $val) {
            $key = (string) $key;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $key)) {
                throw new BrokerException("Invalid environment variable name: {$key}", 2);
            }
            if (!is_scalar($val) && $val !== null) {
                throw new BrokerException("{$key}: value must be text.", 2);
            }
            $val = (string) $val;
            // A line break would end this variable and start another; NUL ends the string early.
            if (preg_match('/[\r\n\0]/', $val) || strlen($val) > self::MAX_ENV_VALUE) {
                throw new BrokerException("{$key}: value must be a single line of at most " . self::MAX_ENV_VALUE . ' bytes.', 2);
            }
            $out[$key] = $val;
        }

        return $out;
    }

    /** YAML double-quoted scalar, with "$" doubled so compose does not interpolate it. */
    public static function yamlString(string $value): string
    {
        $escaped = str_replace(['\\', '"', '$', "\t"], ['\\\\', '\\"', '$$', '\\t'], $value);

        return '"' . $escaped . '"';
    }

    private static function slug(string $domain): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($domain))), '-');
        if ($slug === '') {
            throw new BrokerException('Cannot derive a Docker settings directory for this domain.', 2);
        }

        return $slug;
    }
}
