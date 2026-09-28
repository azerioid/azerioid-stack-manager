<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Restore verification (B6, ADR A52): prove a database dump restores, without touching a live
 * database, by restoring it into a scratch database the panel creates, checking it holds
 * something, and dropping it.
 *
 * Refused for whole-server dumps (`mysqldump --all-databases`, `pg_dumpall`): they carry their
 * own CREATE DATABASE / \connect statements and would restore into the live databases they
 * came from, whatever target is named. MongoDB archives go through `mongorestore --dryRun`,
 * which parses the whole archive and writes nothing — restoring into another database needs
 * namespace remapping that would verify something other than the restore an operator runs.
 */
final class ScratchVerifier
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @return array{checked:string, detail:string, objects?:int, scratch?:string}
     */
    public function verify(string $plain, string $sourceName, ?string $engine): array
    {
        if ($sourceName === 'all') {
            return [
                'checked' => 'skipped',
                'detail' => 'Whole-server dumps are not restored for verification: they name their own databases and would overwrite the live ones.',
            ];
        }
        $driver = (new BackupEngines($this->config, $this->runtime))->for($engine);
        if ($driver instanceof MongoDbBackupEngine) {
            $spec = $driver->dryRunCommand();
            try {
                $result = $this->runtime->exec($spec['command'], $plain, 1800);
            } finally {
                ($spec['cleanup'])();
            }
            if (!$result->ok()) {
                throw new BrokerException('mongorestore --dryRun failed: ' . substr(trim($result->stderr), -300), 1);
            }

            return ['checked' => 'mongorestore-dry-run', 'detail' => 'mongorestore parsed the whole archive (dry run, nothing written).'];
        }

        // A single-database dump names no database. One that does would restore into it,
        // not into the scratch target — refuse rather than find out on a live database.
        if (!str_starts_with($plain, PostgreSqlBackupEngine::CUSTOM_FORMAT_MAGIC)
            && preg_match('/^\s*(USE|CREATE\s+DATABASE|DROP\s+DATABASE|\\\\connect|\\\\c)\b/mi', $plain) === 1) {
            throw new BrokerException('The dump names a database of its own; it cannot be restored into a scratch database safely.', 3);
        }

        $scratch = 'azv_verify_' . bin2hex(random_bytes(4));
        if ($driver->targetExists($scratch)) {
            throw new BrokerException('Scratch database name collided; try again.', 1);
        }
        $driver->prepareTarget($scratch);
        try {
            $spec = $driver instanceof PostgreSqlBackupEngine
                ? $driver->restoreCommandFor($scratch, substr($plain, 0, 16))
                : $driver->restoreCommand($scratch);
            try {
                $result = $this->runtime->exec($spec['command'], $plain, 1800);
            } finally {
                ($spec['cleanup'])();
            }
            if (!$result->ok()) {
                throw new BrokerException('The dump did not restore into a scratch database: ' . substr(trim($result->stderr), -300), 1);
            }
            $objects = $driver->countObjects($scratch);
            if ($objects < 1 && strlen($plain) > 4096) {
                throw new BrokerException('The dump restored but produced no tables; it may not contain what it should.', 1);
            }
        } finally {
            $driver->dropTarget($scratch);
        }

        return [
            'checked' => 'scratch-restore',
            'objects' => $objects,
            'scratch' => $scratch,
            'detail' => "Restored into scratch database {$scratch} ({$objects} tables), then dropped it.",
        ];
    }
}
