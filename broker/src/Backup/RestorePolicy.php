<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Validator;

/**
 * The two refusals that stand between an operator and an overwritten production
 * site, in one place (B5.2).
 *
 * They used to live inline in BackupRestore, which was fine while a restore ran
 * inside the request: the operator pressed Apply and read the refusal. Now that a
 * restore is queued, a refusal discovered inside the worker would only surface on
 * the Operations page minutes later — after the operator had walked away assuming
 * the guard had let them through.
 *
 * So the panel asks first (`backup.restore.check`) and the restore asks again.
 * Both go through this class, because two copies of a guard is how one of them
 * ends up weaker than the other. The preflight is advisory only; nothing relies
 * on it having run, and BackupRestore still refuses on its own.
 */
final class RestorePolicy
{
    /**
     * Refusing here costs nothing; the alternative is dropping a dump into a
     * database that already holds someone's data.
     */
    public static function assertDb(BackupEngine $driver, string $target, bool $overwrite, string $confirm): void
    {
        $target = Validator::dbName($target);
        if ($overwrite) {
            Validator::typedConfirm($confirm, 'OVERWRITE');

            return;
        }
        if ($driver->targetExists($target)) {
            throw new BrokerException(
                'Target database exists. Restore into a new name, or send overwrite confirm OVERWRITE.',
                3
            );
        }
    }

    /**
     * Only `apply` is guarded: staging an archive to look at its listing touches
     * nothing the site serves.
     */
    public static function assertFiles(Config $config, string $site, bool $apply, bool $force, string $confirm): void
    {
        $site = Validator::siteName($site);
        if (!$apply || !in_array($site, $config->readonlyVhosts, true)) {
            return;
        }
        $confirmToken = strtoupper($site);
        if (!$force) {
            throw new BrokerException(
                'Refusing to restore over a read-only vhost without force + confirm ' . $confirmToken . '.',
                3
            );
        }
        Validator::typedConfirm($confirm, $confirmToken);
    }
}
