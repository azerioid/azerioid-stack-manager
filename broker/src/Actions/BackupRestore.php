<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\ArchiveCipher;
use AzerioidPanel\Broker\Backup\BackupEngines;
use AzerioidPanel\Broker\Backup\PostgreSqlBackupEngine;
use AzerioidPanel\Broker\Backup\RestorePolicy;
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
        // A47: legacy LACMP1/LCMP1 is unauthenticated AES-CBC. A backup-storage
        // writer can IV-flip the first block of a legacy DB dump into a `\!` shell
        // meta-command that the root-run mysql/psql client executes, or tamper a
        // file archive. BackupVerify's deep restore already refuses non-LACMP2 for
        // this reason; do the same for live restore. An operator can still read a
        // genuinely old archive with an explicit opt-in.
        if ($format !== 'lacmp2' && empty($input['allow_legacy_unauthenticated'])) {
            throw new BrokerException(
                'Refusing to restore an unauthenticated legacy (LACMP1/LCMP1) archive. '
                . 'Re-create the backup with the current format, or pass '
                . 'allow_legacy_unauthenticated to override from a trusted source.',
                2
            );
        }
        $plain = ArchiveCipher::decryptBlob($cipher, $passphrase);

        // A74: when the caller holds an authenticated manifest (a vhost bundle),
        // verify the decrypted part against the sha256 the manifest recorded for
        // it. Each part is AEAD-encrypted, so tampering without the passphrase
        // already fails; but a backup-storage writer can SUBSTITUTE one valid
        // same-passphrase blob for another (an older part, or a different
        // domain's) because the GCM AAD binds only the per-part header, not the
        // part's identity. The manifest checksum is the binding of which content
        // belongs to this part; enforce it.
        $expectedSha = trim((string) ($input['expected_sha256'] ?? ''));
        if ($expectedSha !== '' && !hash_equals($expectedSha, hash('sha256', $plain))) {
            throw new BrokerException(
                'Backup part does not match its manifest checksum; refusing to restore a substituted '
                . 'or corrupted part.',
                2
            );
        }

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

        // Enforced here even though the panel preflights the same rule through
        // `backup.restore.check`: the preflight is for the operator's benefit, not
        // a substitute for the guard (RestorePolicy).
        RestorePolicy::assertDb($driver, $target, $overwrite, (string) ($input['confirm'] ?? ''));

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
        RestorePolicy::assertFiles($config, $site, $apply, $force, (string) ($input['confirm'] ?? ''));

        // A47: a fresh, uniquely named staging dir per call. The old fixed path
        // (restore-<site>) was never cleaned, so symlinks left by an earlier
        // archive stayed on disk and defeated ArchiveGuard's lexical link check,
        // which assumes extraction starts from an empty root. A unique dir makes
        // every extraction start empty (as VhostBundle::restoreConfig already does).
        $staging = rtrim($config->stagingDir, '/') . '/restore-' . $site . '-' . bin2hex(random_bytes(8));
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
        // tar ran as root with --no-same-owner, so everything it wrote is root's. Give the
        // tree back to whoever owned the live one, and the top directory its mode: the
        // site's identity could not otherwise write its own files, and a 0755 top
        // directory would open the site to every other one (A49-E1).
        if ($hadLive) {
            $runtime->exec(['/usr/bin/chown', '-R', '-h', '--reference=' . $backup, $dest], null, 600);
            $runtime->exec(['/usr/bin/chmod', '--reference=' . $backup, $dest], null, 30);
        }
        // Pre-restore snapshots were never pruned, so every restore left another
        // full copy of the site next to it until the disk filled (A2.5).
        $keepSnapshots = max(0, min(20, (int) ($input['keep_snapshots'] ?? 2)));
        $prunedSnapshots = $this->pruneSnapshots($runtime, $config, $site, $keepSnapshots);

        // The staged source was moved into place; remove the now-empty staging dir
        // so it cannot accumulate or be reused by a later extraction.
        $runtime->exec(['/bin/rm', '-rf', $staging], null, 60);

        return [
            'destination' => $dest,
            'applied' => true,
            'forced_readonly' => $protected && $force,
            'preview' => $listing,
            'previous' => $hadLive ? $backup : null,
            'snapshots_kept' => $keepSnapshots,
            'snapshots_pruned' => $prunedSnapshots,
        ];
    }
    /**
     * Keep the newest $keep pre-restore snapshots for a site and remove the rest.
     *
     * Named `<site>.lacmp-pre-restore-<stamp>`, they sit beside the docroot under
     * the www root. The stamp sorts lexicographically, so newest-first is a plain
     * reverse string sort.
     *
     * @return list<string>
     */
    private function pruneSnapshots(Runtime $runtime, Config $config, string $site, int $keep): array
    {
        $base = rtrim($config->wwwRoot, '/');
        if (!$runtime->isDir($base)) {
            return [];
        }
        $prefix = $site . '.lacmp-pre-restore-';
        $found = [];
        foreach ($runtime->listDir($base) as $entry) {
            if (str_starts_with($entry, $prefix)) {
                $found[] = $entry;
            }
        }
        rsort($found, SORT_STRING);

        $deleted = [];
        foreach (array_slice($found, $keep) as $old) {
            $path = $base . '/' . $old;
            // Never step outside the www root, even though the name came from it.
            if ($runtime->resolveUnderBase($path, $base) === null) {
                continue;
            }
            $rm = $runtime->exec(['/bin/rm', '-rf', $path], null, 120);
            if ($rm->ok()) {
                $deleted[] = $old;
            }
        }

        return $deleted;
    }
}
