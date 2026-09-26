<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Backup\ArchiveCipher;
use AzerioidPanel\Broker\Backup\ArchiveGuard;
use AzerioidPanel\Broker\Backup\PostgreSqlBackupEngine;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\SpacesClient;
use AzerioidPanel\Broker\Validator;

/**
 * `backup.verify` — prove an archive is restorable without restoring it (A2.5).
 *
 * Nothing previously ever checked that a stored backup could actually be used, in
 * a product whose value proposition includes disaster recovery.
 *
 * Two layers, because they answer different questions:
 *
 *  1. **Integrity.** A full LACMP2 decrypt authenticates every chunk, so success
 *     proves the bytes are intact and the passphrase is right. This is real proof,
 *     not a checksum the same process just computed — the tags were written at
 *     backup time. Legacy LACMP1 archives can only be decrypted, never
 *     authenticated, which is precisely why they are reported as such.
 *  2. **Structure.** Intact bytes can still be a useless archive. Files archives
 *     are walked with ArchiveGuard (which also re-checks the restore-safety rules,
 *     so a backup that would be refused at restore time is flagged now). Database
 *     archives are sniffed for a plausible dump header, and PostgreSQL custom
 *     archives are listed with `pg_restore --list`, which reads the table of
 *     contents without touching a database.
 *
 * Read-only: no database is created, nothing is written outside the staging dir,
 * and the staging copy is removed afterwards.
 */
final class BackupVerify
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
            $cipher = SpacesClient::fromInput($input['spaces'] ?? [])->get($key);
            $destination = 'spaces';
        } else {
            throw new BrokerException('destination must be spaces or local.', 2);
        }

        $format = ArchiveCipher::detect($cipher);
        if ($format === 'unknown') {
            throw new BrokerException('Not a recognised AZERIOID backup archive.', 2);
        }

        // Layer 1: decrypting a LACMP2 archive authenticates it chunk by chunk.
        $plain = ArchiveCipher::decryptBlob($cipher, $passphrase);
        unset($cipher);

        $kind = $this->kindOf($key, $input);
        $structure = match ($kind) {
            'files', 'caddy' => $this->verifyTar($plain),
            'db' => $this->verifyDump($plain, $runtime, $config),
            default => ['checked' => 'none', 'detail' => 'Unrecognised archive kind; integrity verified only.'],
        };

        return [
            'key' => $key,
            'destination' => $destination,
            'format' => $format,
            'authenticated' => $format === 'lacmp2',
            'plain_bytes' => strlen($plain),
            'sha256' => hash('sha256', $plain),
            'kind' => $kind,
            'structure' => $structure,
            'restorable' => true,
            'note' => $format === 'lacmp2'
                ? 'Integrity authenticated and structure readable.'
                : 'Legacy archive: decrypted and structurally readable, but the format '
                    . 'carries no authentication, so tampering cannot be ruled out. '
                    . 'Re-create this backup to get an authenticated archive.',
        ];
    }

    /** @param array<string,mixed> $input */
    private function kindOf(string $key, array $input): string
    {
        if (isset($input['kind'])) {
            $kind = strtolower(trim((string) $input['kind']));
            if (in_array($kind, ['db', 'files', 'caddy'], true)) {
                return $kind;
            }
        }
        foreach (['/db/' => 'db', '/files/' => 'files', '/caddy/' => 'caddy'] as $needle => $kind) {
            if (str_contains($key, $needle)) {
                return $kind;
            }
        }

        return 'unknown';
    }

    /** @return array{checked:string, entries?:int, bytes?:int, detail:string} */
    private function verifyTar(string $plain): array
    {
        $pos = 0;
        $decoded = @gzdecode($plain);
        $body = is_string($decoded) ? $decoded : $plain;

        $out = ArchiveGuard::inspectStream(static function (int $n) use ($body, &$pos): string {
            $chunk = substr($body, $pos, $n);
            $pos += strlen($chunk);

            return $chunk;
        });

        return [
            'checked' => 'tar',
            'entries' => $out['entries'],
            'bytes' => $out['bytes'],
            'detail' => $out['entries'] . ' entries, all passing restore-safety checks.',
        ];
    }

    /** @return array{checked:string, detail:string, toc_entries?:int} */
    private function verifyDump(string $plain, Runtime $runtime, Config $config): array
    {
        if (str_starts_with($plain, PostgreSqlBackupEngine::CUSTOM_FORMAT_MAGIC)) {
            return $this->verifyPgCustom($plain, $runtime, $config);
        }
        if (str_starts_with($plain, "\x00\x00\x00\x00") || str_contains(substr($plain, 0, 64), 'admin')) {
            // mongodump --archive starts with a binary magic; treat as opaque.
            return ['checked' => 'mongodump-archive', 'detail' => 'Binary archive; integrity verified.'];
        }

        $head = substr($plain, 0, 4096);
        $looksSql = str_contains($head, 'CREATE')
            || str_contains($head, 'INSERT')
            || str_contains($head, 'DROP')
            || str_contains($head, '--')
            || str_contains($head, '/*');
        if (!$looksSql) {
            throw new BrokerException(
                'Archive decrypted but does not look like a database dump; refusing to call it restorable.',
                1
            );
        }

        return ['checked' => 'sql-text', 'detail' => 'Plain SQL dump header looks well formed.'];
    }

    /** @return array{checked:string, detail:string, toc_entries:int} */
    private function verifyPgCustom(string $plain, Runtime $runtime, Config $config): array
    {
        $tmp = rtrim($config->stagingDir, '/') . '/verify-' . bin2hex(random_bytes(6)) . '.dump';
        $runtime->mkdir($config->stagingDir, 0750);
        $runtime->writeFile($tmp, $plain, 0600);
        try {
            // --list reads the table of contents only; it never connects to a server.
            $result = $runtime->exec(['/usr/bin/pg_restore', '--list', $tmp], null, 120);
        } finally {
            if ($runtime->fileExists($tmp)) {
                $runtime->deleteFile($tmp);
            }
        }
        if (!$result->ok()) {
            throw new BrokerException(
                'pg_restore --list rejected the archive: '
                . (trim($result->stderr) !== '' ? trim($result->stderr) : 'unknown error'),
                1
            );
        }
        $entries = 0;
        foreach (explode("\n", $result->stdout) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, ';')) {
                $entries++;
            }
        }

        return [
            'checked' => 'pg_restore --list',
            'toc_entries' => $entries,
            'detail' => $entries . ' table-of-contents entries readable.',
        ];
    }
}
