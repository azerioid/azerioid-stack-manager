<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\ArchiveCipher;
use AzerioidPanel\Broker\Backup\BackupEngines;
use AzerioidPanel\Broker\Backup\PostgreSqlBackupEngine;
use AzerioidPanel\Broker\Backup\ArchiveGuard;
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

        // Reads LACMP2 and, for archives predating it, LACMP1/LCMP1. LACMP2
        // authenticates every chunk, so a tampered archive fails here rather than
        // reaching tar or mysql (A2.2).
        $format = ArchiveCipher::detect($cipher);
        $plain = ArchiveCipher::decryptBlob($cipher, $passphrase);

        $storage = $destination === '' ? 'spaces' : $destination;
        if ($action === 'backup.restore.db') {
            return $this->restoreDb($runtime, $config, $plain, $input) + [
                'key' => $key,
                'storage' => $storage,
                'format' => $format,
            ];
        }
        return $this->restoreFiles($runtime, $config, $plain, $input) + [
            'key' => $key,
            'storage' => $storage,
            'format' => $format,
        ];
    }

    /**
     * Restore a database dump through the engine that produced it (A2.4).
     *
     * The dump reaches the tool on stdin and is never written to disk: the
     * previous staging copy put decrypted database contents on the filesystem for
     * no functional reason (A2.1).
     *
     * @param array<string,mixed> $input
     */
    private function restoreDb(Runtime $runtime, Config $config, string $dump, array $input): array
    {
        $target = Validator::dbName((string) ($input['target'] ?? ''));
        $overwrite = (bool) ($input['overwrite'] ?? false);
        $driver = (new BackupEngines($config, $runtime))->for(
            isset($input['engine']) ? (string) $input['engine'] : null
        );

        if ($overwrite) {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), 'OVERWRITE');
        } elseif ($driver->targetExists($target)) {
            throw new BrokerException(
                'Target database exists. Restore into a new name, or send overwrite confirm OVERWRITE.',
                3
            );
        }

        $driver->prepareTarget($target);

        if ($driver instanceof PostgreSqlBackupEngine) {
            // pg_dump -Fc and pg_dumpall output need different tools; the payload
            // itself says which, so no metadata has to travel with the archive.
            $spec = $driver->restoreCommandFor($target, substr($dump, 0, 16));
            $tool = $spec['tool'];
        } else {
            $spec = $driver->restoreCommand($target);
            $tool = basename($spec['command'][0]);
        }

        try {
            $result = $runtime->exec($spec['command'], $dump, 1800);
        } finally {
            ($spec['cleanup'])();
        }
        if (!$result->ok()) {
            throw new BrokerException(
                trim($result->stderr) !== '' ? trim($result->stderr) : $tool . ' restore failed.',
                1
            );
        }

        return [
            'target' => $target,
            'overwrite' => $overwrite,
            'engine' => $driver->engine(),
            'tool' => $tool,
        ];
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
        // Inspect BEFORE extracting, by parsing the tar format directly (not the
        // human-readable `tar -tv` output, which differs between GNU and BSD tar
        // and is locale-dependent). tar -x runs as root here, so a tampered
        // archive could otherwise land setuid binaries, device nodes or symlinks
        // under the web root (A2.1).
        try {
            $inspected = ArchiveGuard::inspectStream($runtime->gzReader($archive));
        } catch (BrokerException $e) {
            $runtime->deleteFile($archive);
            throw $e;
        }
        $listing = array_slice($inspected['names'], 0, 200);

        $extract = $runtime->exec(
            array_merge(
                ['/usr/bin/tar', '-C', $staging],
                ArchiveGuard::safeExtractFlags(),
                ['-xzf', $archive]
            ),
            null,
            300
        );
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
