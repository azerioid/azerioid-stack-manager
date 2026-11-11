<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\BackupEngines;
use AzerioidPanel\Broker\Backup\PostgreSqlBackupEngine;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseManager;
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
            // audited backup-engine commands, joined by a shell pipe. The dump is
            // never written to disk in the clear and never held in this process:
            // buffering it here cost several times the database's size in memory,
            // and a memory-limit fatal cannot be caught, so the rollback below never
            // ran and a half-loaded clone was left behind. PostgreSQL keeps its A71
            // per-target unprivileged owning role.
            $backup = (new BackupEngines($config, $runtime))->for($engine);
            $dumpSpec = $backup->dumpCommand($source);
            $cleanups = [$dumpSpec['cleanup']];
            try {
                $backup->prepareTarget($target);
                if ($engine === 'mariadb') {
                    [$stages, $cleanups[]] = self::mariaDbLoad($runtime, $config, $target, $user, $password);
                } else {
                    // PostgreSQL: pg_restore of a custom-format archive (what
                    // dumpCommand's pg_dump -Fc produces) replays a TOC, not arbitrary
                    // SQL from the payload, and A71 restores under a per-target
                    // unprivileged owning role with PUBLIC revoked — the injection
                    // surface the MariaDB text path has does not apply.
                    $spec = $backup instanceof PostgreSqlBackupEngine
                        ? $backup->restoreCommandFor($target, PostgreSqlBackupEngine::CUSTOM_FORMAT_MAGIC)
                        : $backup->restoreCommand($target);
                    $cleanups[] = $spec['cleanup'];
                    $stages = [$spec['command']];
                }
                // Every word is a fixed path, a validated identifier or a generated
                // file name; pipefail makes a failed dump fail the whole clone.
                $pipeline = implode(' | ', array_map(
                    static fn (array $argv): string => implode(' ', array_map('escapeshellarg', $argv)),
                    [$dumpSpec['command'], ...$stages]
                ));
                $result = $runtime->exec(['/bin/bash', '-c', 'set -o pipefail; ' . $pipeline], null, 3600);
            } finally {
                foreach ($cleanups as $cleanup) {
                    $cleanup();
                }
            }
            if (!$result->ok()) {
                throw new BrokerException(
                    trim($result->stderr) !== '' ? trim($result->stderr) : 'Copying the database into the clone failed.',
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
     * The pipeline stages that load a MariaDB dump into the clone, run AS THE
     * CLONE'S OWN unprivileged user, plus the cleanup for their credentials file.
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
     *
     * @return array{0: list<list<string>>, 1: callable():void}
     */
    private static function mariaDbLoad(Runtime $runtime, Config $config, string $target, string $user, string $password): array
    {
        $cnf = rtrim($config->stagingDir, '/') . '/mysql-clone-' . bin2hex(random_bytes(6)) . '.cnf';
        $runtime->mkdir($config->stagingDir, 0750);
        // $user is a validated SQL identifier ([A-Za-z0-9_]) and $password is 48 hex
        // chars, so neither can break out of the [client] block, the DEFINER clause
        // or the sed replacement.
        $runtime->writeFile(
            $cnf,
            "[client]\nuser={$user}\npassword={$password}\nsocket={$config->mysqlSocket}\n",
            0600
        );

        return [
            [
                // LC_ALL=C: match bytes, so a row with invalid UTF-8 cannot hide a clause.
                // Row data is left alone: mysqldump writes each INSERT on one line and
                // never puts a DEFINER clause on it, and rewriting there silently
                // altered any stored text that merely looked like one.
                ['/usr/bin/env', 'LC_ALL=C', '/usr/bin/sed', '-E',
                    '/^INSERT INTO /!s/DEFINER=`([^`]|``)*`@`([^`]|``)*`/DEFINER=`' . $user . '`@`localhost`/g'],
                ['/usr/bin/mysql', '--defaults-extra-file=' . $cnf, '--protocol=socket', '--socket=' . $config->mysqlSocket, $target],
            ],
            static function () use ($runtime, $cnf): void {
                if ($runtime->fileExists($cnf)) {
                    $runtime->deleteFile($cnf);
                }
            },
        ];
    }
}
