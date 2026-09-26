<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\BackupEngines;
use AzerioidPanel\Broker\Backup\RestorePolicy;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Answers "would this restore be refused?" without fetching or decrypting
 * anything (B5.2).
 *
 * A restore is queued now, so the panel needs the refusal before it hands the
 * work to a worker — otherwise a guard that exists to stop an operator
 * overwriting a live site turns into a failure notice they read afterwards.
 *
 * This asks no question the restore does not ask itself, through the same
 * RestorePolicy. It reads only: whether a database of that name exists, and
 * whether the site is on the read-only list. It changes nothing and needs no
 * passphrase, so a refusal costs one cheap round trip rather than a download.
 */
final class BackupRestoreCheck
{
    /**
     * @param  list<string>  $args
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $kind = strtolower(trim((string) ($args[0] ?? $input['kind'] ?? '')));
        $confirm = (string) ($input['confirm'] ?? '');

        if ($kind === 'db') {
            $driver = (new BackupEngines($config, $runtime))->for(
                isset($input['engine']) ? (string) $input['engine'] : null
            );
            RestorePolicy::assertDb(
                $driver,
                (string) ($input['target'] ?? ''),
                (bool) ($input['overwrite'] ?? false),
                $confirm
            );

            return ['kind' => 'db', 'allowed' => true, 'engine' => $driver->engine()];
        }

        if ($kind === 'files') {
            RestorePolicy::assertFiles(
                $config,
                (string) ($input['site'] ?? ''),
                (bool) ($input['apply'] ?? false),
                (bool) ($input['force'] ?? false),
                $confirm
            );

            return ['kind' => 'files', 'allowed' => true];
        }

        throw new BrokerException('backup.restore.check needs kind=db or kind=files.', 2);
    }
}
