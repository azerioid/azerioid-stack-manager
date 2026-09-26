<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Pre-update snapshots of the panel database (A3 / R4).
 *
 * Self-update rolls the *code* back on failure but never the *schema*: the
 * rollback path re-ran `artisan migrate`, which is forward-only. If a migration
 * succeeded and a later step failed, the panel ended up on old code against a new
 * schema — the one combination nothing tests.
 *
 * The panel database is a single SQLite file, so a snapshot is nearly free. It is
 * taken immediately before migrations and restored on the rollback path instead of
 * re-running migrations.
 *
 * `sqlite3 .backup` is used rather than a file copy. The panel runs SQLite in WAL
 * mode (a live host has panel.sqlite-wal and -shm alongside the database), and
 * SQLite documents that copying a database file while it may be written to is
 * unsafe — with WAL you would have to copy the -wal as well, and even then the
 * pair can be caught mid-checkpoint. In practice a checkpointed database usually
 * copies cleanly, which is precisely what makes relying on it a bad bet for a
 * rollback point: it works until the one time it matters. `.backup` uses the
 * online backup API and is consistent by construction, needs no reasoning about
 * sidecar files, and costs nothing extra. sqlite3 is installed by bootstrap, so it
 * is always present.
 */
final class PanelDbSnapshot
{
    private const SQLITE_BIN = '/usr/bin/sqlite3';

    /** Enough to cover a few bad updates in a row without hoarding copies. */
    public const KEEP = 5;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /**
     * @return ?string Snapshot path, or null when there is no database to snapshot
     *                 (a fresh install mid-bootstrap has none yet).
     */
    public function create(string $operationId, ?OperationLogger $log = null): ?string
    {
        $db = $this->config->panelDbPath;
        if (!$this->runtime->fileExists($db)) {
            $log?->warn('No panel database at ' . $db . '; skipping pre-update snapshot.');

            return null;
        }
        if (!$this->runtime->fileExists(self::SQLITE_BIN)) {
            // Refuse rather than fall back to a copy whose consistency we cannot
            // guarantee: an operator who believes they have a rollback point and
            // does not is worse off than one who is told to install sqlite3.
            throw new BrokerException(
                'sqlite3 is not installed, so a consistent pre-update snapshot of the panel '
                . 'database cannot be taken. Install sqlite3 and retry.',
                3
            );
        }

        $dir = rtrim($this->config->panelDbSnapshotDir, '/');
        $this->runtime->mkdir($dir, 0700);
        $path = $dir . '/' . gmdate('Ymd\THis\Z') . '-' . $operationId . '.sqlite';

        $result = $this->runtime->exec(
            [self::SQLITE_BIN, $db, ".backup '" . $path . "'"],
            null,
            300
        );
        if (!$result->ok() || !$this->runtime->fileExists($path)) {
            throw new BrokerException(
                'Pre-update snapshot of the panel database failed: '
                . (trim($result->stderr) !== '' ? trim($result->stderr) : 'sqlite3 .backup produced no file'),
                1
            );
        }
        // Contains sessions, encrypted secrets and the audit trail.
        $this->runtime->chmod($path, 0600);

        $log?->info('Panel database snapshot: ' . $path . ' (' . $this->runtime->fileSize($path) . ' bytes)');

        return $path;
    }

    /**
     * Put a snapshot back. Used on the rollback path, where re-running forward
     * migrations cannot undo what the failed update applied.
     */
    public function restore(string $path, ?OperationLogger $log = null): void
    {
        if (!$this->runtime->fileExists($path)) {
            throw new BrokerException('Panel database snapshot is missing: ' . $path, 3);
        }
        $db = $this->config->panelDbPath;

        // WAL and SHM belong to the database being replaced; leaving them behind
        // would let SQLite replay a newer log over the restored file.
        $this->runtime->writeFile($db, $this->runtime->readFile($path), 0660);
        foreach ([$db . '-wal', $db . '-shm'] as $side) {
            if ($this->runtime->fileExists($side)) {
                $this->runtime->deleteFile($side);
            }
        }

        $webUser = $this->config->webUser;
        $this->runtime->exec(['/usr/bin/chown', $webUser . ':' . $webUser, $db], null, 30);

        $log?->info('Panel database restored from ' . $path . '; schema is back to its pre-update state.');
    }

    /**
     * Keep the newest self::KEEP snapshots.
     *
     * @return list<string> removed paths
     */
    public function prune(int $keep = self::KEEP): array
    {
        $dir = rtrim($this->config->panelDbSnapshotDir, '/');
        if (!$this->runtime->isDir($dir)) {
            return [];
        }
        $files = [];
        foreach ($this->runtime->listDir($dir) as $name) {
            if (str_ends_with($name, '.sqlite')) {
                $files[] = $name;
            }
        }
        // Names begin with a UTC stamp, so newest-first is a reverse string sort.
        rsort($files, SORT_STRING);

        $removed = [];
        foreach (array_slice($files, max(0, $keep)) as $old) {
            $path = $dir . '/' . $old;
            try {
                $this->runtime->deleteFile($path);
                $removed[] = $path;
            } catch (BrokerException) {
                // best effort; a stale snapshot is not worth failing an update over
            }
        }

        return $removed;
    }
}
