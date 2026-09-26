<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

/**
 * Streams a producer process through LACMP2 encryption into a sink (A2.3).
 *
 * Replaces the previous model, where the whole dump or tarball was read into a
 * PHP string, encrypted into a second full copy, and only then written. That put
 * a hard ceiling on backup size — on a 1 GB host it failed well before the disk
 * did — and it is why R3c listed streaming as a blocker.
 *
 * Nothing here holds more than one chunk plus, for the remote sink, one multipart
 * part. The plaintext SHA-256 is computed on the way past so integrity can be
 * recorded without a second read.
 */
final class BackupPipeline
{
    public function __construct(private readonly Runtime $runtime)
    {
    }

    /**
     * Run $command, encrypt its stdout, and write it to $sink.
     *
     * @param  list<string>  $command
     * @return array{size:int, etag:?string, parts:int, bytes_in:int, chunks:int, kdf:int, sha256:string}
     */
    public function run(
        array $command,
        ArchiveSink $sink,
        string $passphrase,
        ?string $kdfName = null,
        ?string $cwd = null,
        int $timeoutSeconds = 3600,
    ): array {
        $proc = $this->runtime->execReader($command, $cwd, $timeoutSeconds);

        try {
            $meta = ArchiveCipher::encryptStream(
                $proc['read'],
                static function (string $chunk) use ($sink): void {
                    $sink->write($chunk);
                },
                $passphrase,
                ArchiveCipher::DEFAULT_CHUNK_BYTES,
                $kdfName
            );
        } catch (\Throwable $e) {
            // Reap the child so it cannot linger, then discard partial output.
            try {
                ($proc['finish'])();
            } catch (\Throwable) {
                // ignore
            }
            $sink->abort();
            throw $e;
        }

        $result = ($proc['finish'])();
        if (!$result->ok()) {
            $sink->abort();
            throw new BrokerException(
                'Backup source command failed: ' . self::detail($result->stderr, $command),
                1
            );
        }

        // A source that exits 0 having produced nothing is almost always a
        // misconfiguration (empty dump, missing site) rather than a real backup.
        if ($meta['bytes_in'] === 0) {
            $sink->abort();
            throw new BrokerException('Backup source produced no data; refusing to store an empty archive.', 1);
        }

        $stored = $sink->finish();

        return $stored + [
            'bytes_in' => $meta['bytes_in'],
            'chunks' => $meta['chunks'],
            'kdf' => $meta['kdf'],
            'sha256' => $meta['sha256'],
        ];
    }

    /** @param list<string> $command */
    private static function detail(string $stderr, array $command): string
    {
        $stderr = trim($stderr);
        if ($stderr !== '') {
            return substr($stderr, 0, 400);
        }

        return basename($command[0] ?? 'command') . ' exited non-zero with no diagnostics.';
    }
}
