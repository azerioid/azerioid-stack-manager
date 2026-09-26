<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

/**
 * Per-engine backup and restore commands (A2.4).
 *
 * `backup.db` used to hardcode mysqldump, so PostgreSQL and MongoDB — both
 * first-class managed components with their own per-database access control
 * (A23) — could not be backed up at all. This mirrors the existing
 * Database\DatabaseDriver shape so each engine contributes only its own argv.
 *
 * Credentials never travel on argv (A23). Each engine writes a short-lived
 * credentials file and returns a cleanup closure; the file has to outlive the
 * spawn, because the child reads it after fork.
 */
interface BackupEngine
{
    public function engine(): string;

    public function isConfigured(): bool;

    /** Does this engine support dumping every database in one archive? */
    public function supportsDumpAll(): bool;

    /**
     * Command that writes the dump to stdout.
     *
     * @return array{command:list<string>, env:array<string,string>, cleanup:callable():void, name:string}
     */
    public function dumpCommand(string $database): array;

    /**
     * Command that restores from stdin into $target.
     *
     * @return array{command:list<string>, env:array<string,string>, cleanup:callable():void}
     */
    public function restoreCommand(string $target): array;

    /** True when $target already holds data, so restore must be confirmed. */
    public function targetExists(string $target): bool;

    /** Create $target if the engine needs it to exist before restore. */
    public function prepareTarget(string $target): void;
}
