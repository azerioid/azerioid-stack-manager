<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

interface Runtime
{
    public function exec(array $command, ?string $stdin = null, int $timeoutSeconds = 30): ExecResult;

    public function readFile(string $path): string;

    public function writeFile(string $path, string $contents, int $mode = 0644): void;

    public function rename(string $from, string $to): void;

    public function deleteFile(string $path): void;

    public function fileExists(string $path): bool;

    public function fileSize(string $path): int;

    public function isDir(string $path): bool;

    public function mkdir(string $path, int $mode = 0755): void;

    public function listDir(string $path): array;

    public function glob(string $pattern): array;

    /** Resolve symlinks; return $path unchanged when it cannot be resolved. */
    public function realPath(string $path): string;

    public function chmod(string $path, int $mode): void;

    public function chown(string $path, string $user, string $group): void;

    /**
     * Resolve $path and confirm the result (or the deepest existing parent)
     * stays under $base. Returns the normalized path to use, or null on escape.
     */
    public function resolveUnderBase(string $path, string $base): ?string;

    public function getuid(): int;

    public function now(): string;

    public function phpVersions(): array;

    /** @return list<array<string,mixed>> */
    public function dbQuery(string $sql, array $params = []): array;

    public function dbExec(string $sql, array $params = []): int;
}
