<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\ArchiveCrypto;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Validator;

/**
 * LACMP2 — authenticated, streaming archive format (A2.2 / ADR A42).
 *
 * Replaces LACMP1, which used AES-256-CBC with no authentication and derived its
 * key from a single unsalted SHA-256 of the passphrase. That was malleable (a
 * tampered archive decrypted to attacker-influenced plaintext, which restore then
 * fed to `tar -x` and `mysql` as root) and cheap to attack offline.
 *
 * LACMP2 provides:
 *   - a real KDF with a per-archive salt (PBKDF2-SHA256 by default, Argon2id as an
 *     explicit opt-in — see "KDF default: portability first" below),
 *   - AEAD over the payload, so tampering is detected before any plaintext is
 *     used,
 *   - constant-memory streaming, so multi-GB archives no longer have to be held
 *     in PHP memory.
 *
 * ## Why chunked AES-256-GCM rather than one tag over the whole archive
 *
 * PHP's openssl binding has no incremental AEAD API: `openssl_encrypt()` takes
 * the entire plaintext in one call. A single tag over the whole archive is
 * therefore incompatible with streaming. libsodium's `secretstream` API exists
 * for exactly this, but per ADR A42 libsodium is absent on already-installed EL
 * hosts, and binding the *encryption* to it would make the format unreadable on
 * those hosts. openssl is present everywhere and AES-GCM is hardware-accelerated,
 * so the payload is framed into independently authenticated chunks.
 *
 * ## Layout
 *
 *   header (42 bytes, plaintext, authenticated as AAD on every chunk)
 *     0   6   magic "LACMP2"
 *     6   1   format version
 *     7   1   kdf id      1 = argon2id, 2 = pbkdf2-sha256
 *     8   1   aead id     1 = aes-256-gcm
 *     9   1   reserved
 *     10  4   kdf param 1 (BE u32) argon2 opslimit | pbkdf2 iterations
 *     14  4   kdf param 2 (BE u32) argon2 memlimit in MiB | pbkdf2 unused
 *     18  16  salt
 *     34  4   nonce prefix
 *     38  4   chunk size (BE u32), plaintext bytes per chunk
 *
 *   then, repeated: ciphertext ‖ tag(16)
 *     nonce_i = nonce_prefix(4) ‖ BE u64 chunk index      (12 bytes, GCM standard)
 *     aad_i   = header(42) ‖ BE u64 chunk index ‖ final flag(1)
 *
 * ## KDF default: portability first
 *
 * PBKDF2-SHA256 is the **default**, not Argon2id, even though Argon2id is
 * stronger. A backup format's first duty is to be readable: Argon2id needs
 * libsodium, which per ADR A42 is absent on already-installed EL hosts, so an
 * Argon2id archive written on Ubuntu could not be restored on such a host — and
 * "restore onto a different machine" is the scenario backups exist for. Argon2id
 * remains available as an explicit opt-in for operators who know which hosts they
 * will restore onto. An explicit request for an unavailable KDF fails loudly
 * rather than silently downgrading, so nobody believes they got protection they
 * did not get. Either way the chosen KDF and its parameters are recorded in the
 * header, so restore is never ambiguous.
 *
 * Putting the header in the AAD means any edit to the salt, KDF parameters or
 * chunk size invalidates every chunk. The chunk index prevents reordering and
 * substitution. The final flag prevents truncation: only the last chunk is
 * encrypted with flag 1, and an attacker cannot promote an earlier chunk because
 * the flag is authenticated — so a reader that insists on seeing a final-flagged
 * chunk detects a cut-short archive.
 */
final class ArchiveCipher
{
    public const MAGIC = 'LACMP2';

    public const VERSION = 1;

    public const KDF_ARGON2ID = 1;

    public const KDF_PBKDF2_SHA256 = 2;

    public const AEAD_AES_256_GCM = 1;

    public const HEADER_BYTES = 42;

    public const SALT_BYTES = 16;

    public const NONCE_PREFIX_BYTES = 4;

    public const TAG_BYTES = 16;

    public const KEY_BYTES = 32;

    public const DEFAULT_CHUNK_BYTES = 1048576;

    /** OWASP-aligned floor for PBKDF2-SHA256. */
    public const PBKDF2_ITERATIONS = 600000;

    /** Argon2id memory cost in MiB. Kept modest: backups run on 1 GB hosts. */
    public const ARGON2_MEMLIMIT_MIB = 64;

    public const ARGON2_OPSLIMIT = 3;

    public static function argon2Available(): bool
    {
        return function_exists('sodium_crypto_pwhash')
            && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')
            && defined('SODIUM_CRYPTO_PWHASH_SALTBYTES')
            && SODIUM_CRYPTO_PWHASH_SALTBYTES === self::SALT_BYTES;
    }

    /** Portable default — readable on every supported host. */
    public const KDF_DEFAULT = 'pbkdf2';

    public const KDF_NAMES = ['pbkdf2', 'argon2id'];

    /**
     * Resolve an operator KDF choice into header fields.
     *
     * An explicit 'argon2id' on a host without libsodium is an error, not a
     * silent downgrade: the operator must know they did not get Argon2id.
     *
     * @return array{kdf:int, p1:int, p2:int, name:string}
     */
    public static function resolveKdf(?string $requested = null): array
    {
        $name = strtolower(trim((string) ($requested ?? self::KDF_DEFAULT)));
        if ($name === '') {
            $name = self::KDF_DEFAULT;
        }
        if (!in_array($name, self::KDF_NAMES, true)) {
            throw new BrokerException(
                'Unknown KDF "' . $name . '". Use one of: ' . implode(', ', self::KDF_NAMES) . '.',
                2
            );
        }

        if ($name === 'argon2id') {
            if (!self::argon2Available()) {
                throw new BrokerException(
                    'Argon2id was requested but libsodium is not available on this host. '
                    . 'Install php-sodium (ADR A42), or omit the option to use the portable '
                    . 'PBKDF2-SHA256 default.',
                    3
                );
            }

            return [
                'kdf' => self::KDF_ARGON2ID,
                'p1' => self::ARGON2_OPSLIMIT,
                'p2' => self::ARGON2_MEMLIMIT_MIB,
                'name' => 'argon2id',
            ];
        }

        return [
            'kdf' => self::KDF_PBKDF2_SHA256,
            'p1' => self::PBKDF2_ITERATIONS,
            'p2' => 0,
            'name' => 'pbkdf2',
        ];
    }

    public static function deriveKey(string $passphrase, string $salt, int $kdfId, int $p1, int $p2): string
    {
        if (strlen($salt) !== self::SALT_BYTES) {
            throw new BrokerException('Archive salt must be ' . self::SALT_BYTES . ' bytes.', 3);
        }

        if ($kdfId === self::KDF_ARGON2ID) {
            if (!self::argon2Available()) {
                throw new BrokerException(
                    'This archive uses Argon2id, but libsodium is not available on this host. '
                    . 'Install php-sodium (ADR A42) and retry.',
                    3
                );
            }
            if ($p1 < 1 || $p2 < 8) {
                throw new BrokerException('Archive Argon2id parameters are out of range.', 3);
            }

            return sodium_crypto_pwhash(
                self::KEY_BYTES,
                $passphrase,
                $salt,
                $p1,
                $p2 * 1024 * 1024,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        }

        if ($kdfId === self::KDF_PBKDF2_SHA256) {
            if ($p1 < 100000) {
                throw new BrokerException('Archive PBKDF2 iteration count is below the accepted floor.', 3);
            }

            return hash_pbkdf2('sha256', $passphrase, $salt, $p1, self::KEY_BYTES, true);
        }

        throw new BrokerException('Unknown archive KDF id: ' . $kdfId, 3);
    }

    /** @return array{kdf:int,p1:int,p2:int,salt:string,noncePrefix:string,chunk:int} */
    public static function parseHeader(string $header): array
    {
        if (strlen($header) < self::HEADER_BYTES) {
            throw new BrokerException('Archive header is truncated.', 3);
        }
        if (substr($header, 0, 6) !== self::MAGIC) {
            throw new BrokerException('Not a LACMP2 archive.', 2);
        }
        $version = ord($header[6]);
        if ($version !== self::VERSION) {
            throw new BrokerException('Unsupported LACMP2 format version: ' . $version, 3);
        }
        $aead = ord($header[8]);
        if ($aead !== self::AEAD_AES_256_GCM) {
            throw new BrokerException('Unsupported archive AEAD id: ' . $aead, 3);
        }
        $p1 = unpack('N', substr($header, 10, 4))[1] ?? 0;
        $p2 = unpack('N', substr($header, 14, 4))[1] ?? 0;
        $chunk = unpack('N', substr($header, 38, 4))[1] ?? 0;
        if ($chunk < 4096 || $chunk > 64 * 1024 * 1024) {
            throw new BrokerException('Archive chunk size is out of range.', 3);
        }

        return [
            'kdf' => ord($header[7]),
            'p1' => (int) $p1,
            'p2' => (int) $p2,
            'salt' => substr($header, 18, self::SALT_BYTES),
            'noncePrefix' => substr($header, 34, self::NONCE_PREFIX_BYTES),
            'chunk' => (int) $chunk,
        ];
    }

    public static function buildHeader(
        int $kdfId,
        int $p1,
        int $p2,
        string $salt,
        string $noncePrefix,
        int $chunk,
    ): string {
        return self::MAGIC
            . chr(self::VERSION)
            . chr($kdfId)
            . chr(self::AEAD_AES_256_GCM)
            . chr(0)
            . pack('N', $p1)
            . pack('N', $p2)
            . $salt
            . $noncePrefix
            . pack('N', $chunk);
    }

    private static function nonce(string $prefix, int $index): string
    {
        return $prefix . pack('J', $index);
    }

    private static function aad(string $header, int $index, bool $final): string
    {
        return $header . pack('J', $index) . chr($final ? 1 : 0);
    }

    /**
     * Encrypt a stream. Constant memory: one chunk at a time.
     *
     * @param  callable(int):string  $read   yields up to n plaintext bytes, '' at EOF
     * @param  callable(string):void $write  consumes ciphertext
     * @return array{bytes_in:int, bytes_out:int, chunks:int, kdf:int, sha256:string}
     */
    public static function encryptStream(
        callable $read,
        callable $write,
        string $passphrase,
        int $chunkSize = self::DEFAULT_CHUNK_BYTES,
        ?string $kdfName = null,
    ): array {
        $passphrase = Validator::password($passphrase);
        ['kdf' => $kdf, 'p1' => $p1, 'p2' => $p2] = self::resolveKdf($kdfName);
        $salt = random_bytes(self::SALT_BYTES);
        $noncePrefix = random_bytes(self::NONCE_PREFIX_BYTES);
        $header = self::buildHeader($kdf, $p1, $p2, $salt, $noncePrefix, $chunkSize);
        $key = self::deriveKey($passphrase, $salt, $kdf, $p1, $p2);

        $write($header);
        $bytesIn = 0;
        $bytesOut = strlen($header);
        $chunks = 0;
        $hash = hash_init('sha256');

        // One chunk of lookahead, so the final chunk is known to be final.
        $current = self::readExactly($read, $chunkSize);
        do {
            $next = $current === '' ? '' : self::readExactly($read, $chunkSize);
            $final = $next === '';

            $tag = '';
            $cipher = openssl_encrypt(
                $current,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                self::nonce($noncePrefix, $chunks),
                $tag,
                self::aad($header, $chunks, $final),
                self::TAG_BYTES
            );
            if ($cipher === false) {
                throw new BrokerException('Archive encryption failed.', 1);
            }
            $write($cipher . $tag);
            hash_update($hash, $current);
            $bytesIn += strlen($current);
            $bytesOut += strlen($cipher) + self::TAG_BYTES;
            $chunks++;
            $current = $next;
        } while (!$final);

        return [
            'bytes_in' => $bytesIn,
            'bytes_out' => $bytesOut,
            'chunks' => $chunks,
            'kdf' => $kdf,
            'sha256' => hash_final($hash),
        ];
    }

    /**
     * Decrypt a LACMP2 stream, verifying every chunk before it is written.
     *
     * @param  callable(int):string  $read
     * @param  callable(string):void $write
     * @return array{bytes_out:int, chunks:int, kdf:int, sha256:string}
     */
    public static function decryptStream(callable $read, callable $write, string $passphrase): array
    {
        $passphrase = Validator::password($passphrase);
        $header = self::readExactly($read, self::HEADER_BYTES);
        $meta = self::parseHeader($header);
        $key = self::deriveKey($passphrase, $meta['salt'], $meta['kdf'], $meta['p1'], $meta['p2']);

        $blockSize = $meta['chunk'] + self::TAG_BYTES;
        $index = 0;
        $bytesOut = 0;
        $hash = hash_init('sha256');
        $sawFinal = false;
        $buffer = self::readExactly($read, $blockSize);

        while ($buffer !== '') {
            $next = strlen($buffer) === $blockSize ? self::readExactly($read, $blockSize) : '';
            $final = $next === '';

            if (strlen($buffer) < self::TAG_BYTES) {
                throw new BrokerException('Archive is corrupt: trailing bytes are shorter than an auth tag.', 3);
            }
            $cipher = substr($buffer, 0, -self::TAG_BYTES);
            $tag = substr($buffer, -self::TAG_BYTES);

            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                self::nonce($meta['noncePrefix'], $index),
                $tag,
                self::aad($header, $index, $final)
            );
            if ($plain === false) {
                throw new BrokerException(
                    'Archive failed authentication at chunk ' . $index
                    . ' (wrong passphrase, or the archive was truncated or modified).',
                    3
                );
            }

            $write($plain);
            hash_update($hash, $plain);
            $bytesOut += strlen($plain);
            $index++;
            $sawFinal = $final;
            $buffer = $next;
        }

        if (!$sawFinal) {
            throw new BrokerException('Archive is truncated: no final chunk was found.', 3);
        }

        return [
            'bytes_out' => $bytesOut,
            'chunks' => $index,
            'kdf' => $meta['kdf'],
            'sha256' => hash_final($hash),
        ];
    }

    /** Which format a blob is, without decrypting it. */
    public static function detect(string $prefix): string
    {
        if (str_starts_with($prefix, self::MAGIC)) {
            return 'lacmp2';
        }
        if (str_starts_with($prefix, 'LACMP1') || str_starts_with($prefix, 'LCMP1')) {
            return 'legacy';
        }

        return 'unknown';
    }

    /**
     * Read a whole blob of either format. Legacy archives are still readable so
     * existing backups can be restored; new archives are always written as
     * LACMP2 (see ArchiveCrypto for the retired format).
     */
    public static function decryptBlob(string $blob, string $passphrase): string
    {
        if (self::detect($blob) !== 'lacmp2') {
            return ArchiveCrypto::decrypt($blob, $passphrase);
        }
        $pos = 0;
        $out = '';
        self::decryptStream(
            static function (int $n) use ($blob, &$pos): string {
                $chunk = substr($blob, $pos, $n);
                $pos += strlen($chunk);

                return $chunk;
            },
            static function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
            $passphrase
        );

        return $out;
    }

    /** Convenience for small payloads; streaming callers should use encryptStream(). */
    public static function encryptBlob(string $plain, string $passphrase, ?string $kdfName = null): string
    {
        $pos = 0;
        $out = '';
        self::encryptStream(
            static function (int $n) use ($plain, &$pos): string {
                $chunk = substr($plain, $pos, $n);
                $pos += strlen($chunk);

                return $chunk;
            },
            static function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
            $passphrase,
            self::DEFAULT_CHUNK_BYTES,
            $kdfName
        );

        return $out;
    }

    /** @param callable(int):string $read */
    private static function readExactly(callable $read, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $part = $read($n - strlen($buf));
            if ($part === '') {
                break;
            }
            $buf .= $part;
        }

        return $buf;
    }
}
