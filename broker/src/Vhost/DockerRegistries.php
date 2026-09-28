<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

/**
 * Named private-registry credentials (request #6, ADR A50).
 *
 * Stored like DNS API tokens (A21): root-only 0600 files, written from broker stdin, never
 * in argv, never logged. They are NOT written to azerioid-supervised's ~/.docker/config.json:
 * that file is base64, not encrypted, and every Docker vhost shares that account (A38), so a
 * credential there would be readable by every other Docker site's tooling. A pull that needs
 * one logs in into a throwaway DOCKER_CONFIG directory, pulls, logs out and deletes it.
 *
 * Standard registry v2 login only: Docker Hub, GHCR, GitLab, Harbor and the like. ECR is out
 * (operator decision 2026-09-28): its tokens expire every twelve hours and need the AWS API.
 */
final class DockerRegistries
{
    public const DIR = '/etc/azerioid-panel/docker-registries';

    public const DOCKER_HUB = 'docker.io';

    public static function validateName(mixed $value): string
    {
        $name = strtolower(trim((string) $value));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $name)) {
            throw new BrokerException('Registry name: lowercase letters, digits and "-", up to 32 characters.', 2);
        }

        return $name;
    }

    public static function validateHost(mixed $value): string
    {
        $host = strtolower(trim((string) $value));
        if ($host === '' || $host === 'hub.docker.com' || $host === 'index.docker.io' || $host === 'registry-1.docker.io') {
            return self::DOCKER_HUB;
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$/', $host) || strlen($host) > 253) {
            throw new BrokerException('Registry host must be a hostname, optionally with :port (e.g. ghcr.io).', 2);
        }
        if (preg_match('/\.amazonaws\.com(:[0-9]+)?$/', $host)) {
            throw new BrokerException('Amazon ECR is not supported: its tokens expire every 12 hours and need the AWS API.', 2);
        }

        return $host;
    }

    public static function validateUsername(mixed $value): string
    {
        $user = trim((string) $value);
        if ($user === '' || strlen($user) > 255 || preg_match('/[\s\0]/', $user)) {
            throw new BrokerException('Registry username is required and cannot contain spaces.', 2);
        }

        return $user;
    }

    public static function validatePassword(mixed $value): string
    {
        $password = (string) $value;
        if ($password === '' || strlen($password) > 4096 || preg_match('/[\r\n\0]/', $password)) {
            throw new BrokerException('Registry password or token is required (one line).', 2);
        }

        return $password;
    }

    /** @return list<array{name:string, host:string, username:string}> */
    public static function list(Runtime $runtime): array
    {
        $out = [];
        foreach ($runtime->glob(self::DIR . '/*.json') as $file) {
            $row = self::read($runtime, $file);
            if ($row !== null) {
                $out[] = ['name' => $row['name'], 'host' => $row['host'], 'username' => $row['username']];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return array{name:string, host:string, username:string, password:string} */
    public static function get(Runtime $runtime, string $name): array
    {
        $name = self::validateName($name);
        $row = self::read($runtime, self::path($name));
        if ($row === null) {
            throw new BrokerException("No saved registry named {$name}.", 3);
        }

        return $row;
    }

    public static function exists(Runtime $runtime, string $name): bool
    {
        return $runtime->fileExists(self::path(self::validateName($name)));
    }

    public static function save(Runtime $runtime, string $name, string $host, string $username, string $password): void
    {
        if (!$runtime->isDir(self::DIR)) {
            $runtime->mkdir(self::DIR, 0700);
        }
        $runtime->chmod(self::DIR, 0700);
        $runtime->writeFile(self::path($name), json_encode([
            'name' => $name,
            'host' => $host,
            'username' => $username,
            'password' => $password,
        ], JSON_UNESCAPED_SLASHES) . "\n", 0600);
        $runtime->chmod(self::path($name), 0600);
    }

    public static function delete(Runtime $runtime, string $name): void
    {
        $path = self::path(self::validateName($name));
        if (!$runtime->fileExists($path)) {
            throw new BrokerException("No saved registry named {$name}.", 3);
        }
        $runtime->deleteFile($path);
    }

    /** The registry an image reference points at: its first component, when that is a host. */
    public static function hostOfImage(string $image): string
    {
        $first = explode('/', $image, 2)[0];
        if (!str_contains($image, '/') || (!str_contains($first, '.') && !str_contains($first, ':') && $first !== 'localhost')) {
            return self::DOCKER_HUB;
        }

        return strtolower($first);
    }

    private static function path(string $name): string
    {
        return self::DIR . '/' . $name . '.json';
    }

    /** @return array{name:string, host:string, username:string, password:string}|null */
    private static function read(Runtime $runtime, string $file): ?array
    {
        try {
            $decoded = json_decode($runtime->readFile($file), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($decoded) || !is_string($decoded['name'] ?? null) || !is_string($decoded['password'] ?? null)) {
            return null;
        }

        return [
            'name' => $decoded['name'],
            'host' => (string) ($decoded['host'] ?? self::DOCKER_HUB),
            'username' => (string) ($decoded['username'] ?? ''),
            'password' => $decoded['password'],
        ];
    }
}
