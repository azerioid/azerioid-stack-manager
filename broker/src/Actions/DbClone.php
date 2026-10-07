<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\BackupEngines;
use AzerioidPanel\Broker\Backup\PostgreSqlBackupEngine;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseManager;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * A79 (increment 2): clone a database to a new name for staging. The source is
 * dumped and loaded into a freshly provisioned target database owned by its own
 * user with a generated password — the clone never shares a user or grant with
 * the source. The load goes through the audited backup-restore path, so
 * PostgreSQL keeps its A71 per-target unprivileged owning role.
 *
 * MariaDB/PostgreSQL only: a single-database SQL dump carries no USE/CREATE so it
 * loads cleanly under a new name. MongoDB's restore path restores a dump under
 * its original namespace (no --nsFrom/--nsTo rename), so a Mongo clone would need
 * new restore code — refused for now, as a non-FPM source is in increment 1.
 */
final class DbClone
{
    /** @return array<string,mixed> */
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $manager = new DatabaseManager($config, $runtime);
        $engine = $manager->resolveEngine((string) ($input['engine'] ?? ''));
        $source = Validator::dbName((string) ($args[0] ?? ($input['source'] ?? '')));
        $target = Validator::dbName((string) ($args[1] ?? ($input['target'] ?? '')));
        $user = Validator::userName((string) ($input['user'] ?? $target));

        return self::run($runtime, $config, $engine, $source, $target, $user);
    }

    /**
     * Provision $target, dump $source into it, return the clone's credentials
     * (the generated password is returned once — it is never persisted here).
     *
     * @return array{engine:string,source:string,target:string,user:string,password:string}
     */
    public static function run(Runtime $runtime, Config $config, string $engine, string $source, string $target, string $user): array
    {
        if ($engine === 'mongodb') {
            throw new BrokerException(
                'Cloning a MongoDB database is not supported yet — clone a MariaDB or PostgreSQL database.',
                3
            );
        }
        if ($source === $target) {
            throw new BrokerException('The clone must use a different database name than the source.', 2);
        }

        $manager = new DatabaseManager($config, $runtime);
        $driver = $manager->driver($engine);
        $names = array_column($driver->list(), 'name');
        if (!in_array($source, $names, true)) {
            throw new BrokerException("Source database {$source} was not found.", 2);
        }
        if (in_array($target, $names, true)) {
            throw new BrokerException("A database named {$target} already exists; choose a new name for the clone.", 3);
        }

        // 48 hex chars (192 bits) — satisfies Validator::password (16–128, no NUL/LF).
        $password = bin2hex(random_bytes(24));
        $driver->add($target, $user, $password);

        try {
            // Dump the source and load it straight into the fresh target through the
            // audited backup-engine path. dumpCommand/restoreCommand stream plain
            // text (unlike DatabaseDriver::dump, which gzips to a file), so the dump
            // is piped in memory — never written to disk in the clear — and
            // PostgreSQL keeps its A71 per-target unprivileged owning role.
            $backup = (new BackupEngines($config, $runtime))->for($engine);
            $dumpSpec = $backup->dumpCommand($source);
            try {
                $dumped = $runtime->exec($dumpSpec['command'], null, 1800);
            } finally {
                ($dumpSpec['cleanup'])();
            }
            if (!$dumped->ok() || $dumped->stdout === '') {
                throw new BrokerException(
                    trim($dumped->stderr) !== '' ? trim($dumped->stderr) : 'Dumping the source database failed.',
                    1
                );
            }
            $plain = $dumped->stdout;

            $backup->prepareTarget($target);
            if ($engine === 'mariadb') {
                $result = self::loadMariaDb($runtime, $config, $target, $user, $password, $plain);
            } else {
                // PostgreSQL: pg_restore of a custom-format archive replays a TOC, not
                // arbitrary SQL from the payload, and A71 restores under a per-target
                // unprivileged owning role with PUBLIC revoked — the injection surface
                // the MariaDB text path has does not apply.
                $spec = $backup instanceof PostgreSqlBackupEngine
                    ? $backup->restoreCommandFor($target, substr($plain, 0, 16))
                    : $backup->restoreCommand($target);
                try {
                    $result = $runtime->exec($spec['command'], $plain, 1800);
                } finally {
                    ($spec['cleanup'])();
                }
            }
            if (!$result->ok()) {
                throw new BrokerException(
                    trim($result->stderr) !== '' ? trim($result->stderr) : 'Loading the dump into the clone failed.',
                    1
                );
            }
        } catch (\Throwable $e) {
            // Roll the half-provisioned clone back so a failed clone leaves nothing.
            try {
                $driver->delete($target, $user);
            } catch (\Throwable) {
            }
            throw $e;
        }

        return [
            'engine' => $engine,
            'source' => $source,
            'target' => $target,
            'user' => $user,
            'password' => $password,
        ];
    }

    /**
     * Load a MariaDB dump into the clone, run AS THE CLONE'S OWN unprivileged user.
     *
     * The dump's object definitions and row data are controlled by whoever owns the
     * source database — which may be a lower-trust application, not the admin running
     * the clone. Loading as root would let crafted content (or a parser differential
     * in the DEFINER rewrite below) execute as root. As the clone user the load can
     * only touch the clone's own database.
     *
     * The DEFINER rewrite binds every view/routine/trigger to the clone user, so a
     * SQL SECURITY DEFINER object cannot reach back into the source database. The two
     * defenses compose: any DEFINER the rewrite misses is some *other* user, which the
     * clone user has no privilege to create — so a missed rewrite fails the load
     * closed (and the clone is rolled back) rather than installing a cross-tenant
     * object.
     */
    private static function loadMariaDb(Runtime $runtime, Config $config, string $target, string $user, string $password, string $dump): ExecResult
    {
        // $user is a validated SQL identifier and $password is 48 hex chars, so
        // neither can break out of the [client] block or the DEFINER clause.
        $dump = (string) preg_replace(
            '/DEFINER=`(?:[^`]|``)*`@`(?:[^`]|``)*`/',
            'DEFINER=`' . $user . '`@`localhost`',
            $dump
        );

        $cnf = rtrim($config->stagingDir, '/') . '/mysql-clone-' . bin2hex(random_bytes(6)) . '.cnf';
        $runtime->mkdir($config->stagingDir, 0750);
        $runtime->writeFile(
            $cnf,
            "[client]\nuser={$user}\npassword={$password}\nsocket={$config->mysqlSocket}\n",
            0600
        );
        try {
            return $runtime->exec(
                ['/usr/bin/mysql', '--defaults-extra-file=' . $cnf, '--protocol=socket', '--socket=' . $config->mysqlSocket, $target],
                $dump,
                1800
            );
        } finally {
            if ($runtime->fileExists($cnf)) {
                $runtime->deleteFile($cnf);
            }
        }
    }
}
