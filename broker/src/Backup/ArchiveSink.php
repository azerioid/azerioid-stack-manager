<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

/**
 * Destination for a streamed archive (A2.3).
 *
 * Gives the local and remote paths one interface so the encryption pipeline does
 * not care where bytes end up, and so neither path has to hold the archive in
 * memory.
 */
interface ArchiveSink
{
    public function write(string $chunk): void;

    /** @return array{size:int, etag:?string, parts:int} */
    public function finish(): array;

    /** Release any partial state. Must be safe to call after a failure. */
    public function abort(): void;
}
