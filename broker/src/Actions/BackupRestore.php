<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\ArchiveCrypto;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;
use AzerioidPanel\Broker\Validator;

final class BackupRestore
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $passphrase = Validator::password((string) ($input['passphrase'] ?? ''));
        $destination = strtolower(trim((string) ($input['destination'] ?? 'spaces')));
        $keyArg = (string) ($args[0] ?? $input['key'] ?? '');

        if ($destination === 'local') {
            $key = Validator::localBackupPath($keyArg, $config->localBackupDir, $runtime);
            $cipher = $runtime->readFile($key);
        } elseif ($destination === 'spaces' || $destination === '') {
            $key = Validator::objectKey($keyArg);
            $client = SpacesClient::fromInput($input['spaces'] ?? []);
            $cipher = $client->get($key);
        } else {
            throw new BrokerException('destination must be spaces or local.', 2);
        }

        $plain = ArchiveCrypto::decrypt($cipher, $passphrase);

        $storage = $destination === '' ? 'spaces' : $destination;
        if ($action === 'backup.restore.db') {
            return $this->restoreDb($runtime, $config, $plain, $input) + [
                'key' => $key,
                'storage' => $storage,
            ];
        }
        return $this->restoreFiles($runtime, $config, $plain, $input) + [
            'key' => $key,
            'storage' => $storage,
        ];
    }

    /** @param array<string,mixed> $input */
    private function restoreDb(Runtime $runtime, Config $config, string $sql, array $input): array
    {
        $target = Validator::dbName((string) ($input['target'] ?? ''));
        $overwrite = (bool) ($input['overwrite'] ?? false);
        if ($overwrite) {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), 'OVERWRITE');
        }
        $existing = $runtime->dbQuery('SHOW DATABASES LIKE ?', [$target]);
        if ($existing !== [] && !$overwrite) {
            throw new BrokerException('Target database exists. Restore into a new name, or send overwrite confirm OVERWRITE.', 3);
        }
        if ($existing === []) {
            $runtime->dbExec('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $cnf = rtrim($config->stagingDir, '/') . '/mysql-restore.cnf';
        $runtime->mkdir($config->stagingDir, 0750);
        $runtime->writeFile($cnf, "[client]\nuser={$config->mysqlUser}\npassword={$config->mysqlPassword}\nsocket={$config->mysqlSocket}\n", 0600);
        $sqlFile = rtrim($config->stagingDir, '/') . '/restore.sql';
        $runtime->writeFile($sqlFile, $sql, 0600);
        try {
            $result = $runtime->exec([
                '/usr/bin/mysql',
                '--defaults-extra-file=' . $cnf,
                $target,
            ], $sql, 300);
        } finally {
            $runtime->deleteFile($cnf);
            $runtime->deleteFile($sqlFile);
        }
        if (!$result->ok()) {
            throw new BrokerException(trim($result->stderr) !== '' ? trim($result->stderr) : 'mysql restore failed.', 1);
        }
        return ['target' => $target, 'overwrite' => $overwrite];
    }

    /** @param array<string,mixed> $input */
    private function restoreFiles(Runtime $runtime, Config $config, string $tgz, array $input): array
    {
        $site = Validator::siteName((string) ($input['site'] ?? ''));
        $force = (bool) ($input['force'] ?? false);
        $apply = (bool) ($input['apply'] ?? false);
        $protected = in_array($site, $config->readonlyVhosts, true);
        $confirmToken = strtoupper($site);
        if ($protected && $apply && !$force) {
            throw new BrokerException(
                'Refusing to restore over a read-only vhost without force + confirm ' . $confirmToken . '.',
                3
            );
        }
        if ($protected && $apply && $force) {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), $confirmToken);
        }

        $staging = rtrim($config->stagingDir, '/') . '/restore-' . $site;
        $runtime->mkdir($staging, 0750);
        $archive = $staging . '.tgz';
        $runtime->writeFile($archive, $tgz, 0600);
        $listingResult = $runtime->exec(['/usr/bin/tar', '-tzf', $archive], null, 60);
        $listing = array_slice(array_values(array_filter(explode("\n", trim($listingResult->stdout)))), 0, 200);
        $extract = $runtime->exec(['/usr/bin/tar', '-C', $staging, '-xzf', $archive], null, 120);
        $runtime->deleteFile($archive);
        if (!$extract->ok()) {
            throw new BrokerException(trim($extract->stderr) !== '' ? trim($extract->stderr) : 'tar extract to staging failed.', 1);
        }
        if (!$apply) {
            return [
                'staged' => $staging,
                'preview' => $listing,
                'applied' => false,
            ];
        }
        $dest = rtrim($config->wwwRoot, '/') . '/' . $site;
        if ($runtime->resolveUnderBase($dest, $config->wwwRoot) === null) {
            throw new BrokerException('Destination escaped www root.', 3);
        }
        $source = $runtime->isDir($staging . '/' . $site) ? $staging . '/' . $site : $staging;
        $stamp = preg_replace('/[^0-9TZ]/', '', $runtime->now()) ?: gmdate('YmdHis');
        $backup = $dest . '.lacmp-pre-restore-' . $stamp;
        $hadLive = $runtime->isDir($dest) || $runtime->fileExists($dest);
        if ($hadLive) {
            $aside = $runtime->exec(['/bin/mv', $dest, $backup], null, 30);
            if (!$aside->ok()) {
                throw new BrokerException('Could not move the live tree aside before restore.', 1);
            }
        }
        $moved = $runtime->exec(['/bin/mv', $source, $dest], null, 30);
        if (!$moved->ok()) {
            if ($hadLive) {
                $runtime->exec(['/bin/mv', $backup, $dest], null, 30);
            }
            throw new BrokerException(trim($moved->stderr) !== '' ? trim($moved->stderr) : 'Failed to move staged files into place.', 1);
        }
        return [
            'destination' => $dest,
            'applied' => true,
            'forced_readonly' => $protected && $force,
            'preview' => $listing,
            'previous' => $hadLive ? $backup : null,
        ];
    }
}
