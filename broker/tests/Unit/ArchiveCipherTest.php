<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\ArchiveCrypto;
use AzerioidPanel\Broker\Backup\ArchiveCipher;
use AzerioidPanel\Broker\BrokerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A2.2 — LACMP2 must detect every form of tampering before any plaintext is
 * handed back, and must still read the retired LACMP1/LCMP1 archives.
 */
final class ArchiveCipherTest extends TestCase
{
    private const PASS = 'correct-horse-battery-staple';

    // ------------------------------------------------------------- round trips

    #[DataProvider('payloads')]
    public function test_round_trip(string $label, string $plain): void
    {
        $blob = ArchiveCipher::encryptBlob($plain, self::PASS);

        $this->assertSame('lacmp2', ArchiveCipher::detect($blob), $label);
        $this->assertSame($plain, ArchiveCipher::decryptBlob($blob, self::PASS), $label);
    }

    public static function payloads(): array
    {
        return [
            'empty' => ['empty', ''],
            'tiny' => ['tiny', 'x'],
            'text' => ['text', str_repeat('hello world ', 100)],
            'binary' => ['binary', random_bytes(5000)],
        ];
    }

    public function test_round_trip_spanning_many_chunks(): void
    {
        $plain = random_bytes(40000);
        $out = '';
        $pos = 0;
        $meta = ArchiveCipher::encryptStream(
            static function (int $n) use ($plain, &$pos): string {
                $c = substr($plain, $pos, $n);
                $pos += strlen($c);

                return $c;
            },
            static function (string $c) use (&$out): void {
                $out .= $c;
            },
            self::PASS,
            4096
        );

        $this->assertSame(10, $meta['chunks'], 'ceil(40000/4096) = 10');
        $this->assertSame(40000, $meta['bytes_in']);
        $this->assertSame(hash('sha256', $plain), $meta['sha256']);
        $this->assertSame($plain, self::decrypt($out));
    }

    public function test_exact_chunk_multiple_still_emits_a_final_chunk(): void
    {
        // 8192 = exactly two 4096 chunks; a naive reader would never see a final flag.
        $plain = random_bytes(8192);
        $blob = self::encrypt($plain, 4096);

        $this->assertSame($plain, self::decrypt($blob));
    }

    public function test_ciphertext_differs_across_runs_for_identical_input(): void
    {
        $a = ArchiveCipher::encryptBlob('same input', self::PASS);
        $b = ArchiveCipher::encryptBlob('same input', self::PASS);

        $this->assertNotSame($a, $b, 'per-archive salt and nonce prefix must randomise output');
    }

    // ------------------------------------------------------------- KDF handling

    /** The default must be portable, even on a host that *could* do Argon2id. */
    public function test_default_kdf_is_pbkdf2_for_portability(): void
    {
        $blob = ArchiveCipher::encryptBlob('x', self::PASS);
        $meta = ArchiveCipher::parseHeader(substr($blob, 0, ArchiveCipher::HEADER_BYTES));

        $this->assertSame(ArchiveCipher::KDF_PBKDF2_SHA256, $meta['kdf']);
        $this->assertSame(ArchiveCipher::PBKDF2_ITERATIONS, $meta['p1']);
        $this->assertSame(ArchiveCipher::SALT_BYTES, strlen($meta['salt']));
    }

    public function test_argon2id_is_available_as_an_explicit_opt_in(): void
    {
        if (!ArchiveCipher::argon2Available()) {
            $this->markTestSkipped('libsodium/Argon2id not available on this host.');
        }
        $blob = ArchiveCipher::encryptBlob('x', self::PASS, 'argon2id');
        $meta = ArchiveCipher::parseHeader(substr($blob, 0, ArchiveCipher::HEADER_BYTES));

        $this->assertSame(ArchiveCipher::KDF_ARGON2ID, $meta['kdf']);
        $this->assertSame('x', ArchiveCipher::decryptBlob($blob, self::PASS));
    }

    public function test_an_explicit_unavailable_kdf_fails_loudly_instead_of_downgrading(): void
    {
        if (ArchiveCipher::argon2Available()) {
            // Cannot simulate absence here; the guard is covered by deriveKey().
            $this->expectNotToPerformAssertions();

            return;
        }
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/Argon2id was requested but libsodium is not available/');
        ArchiveCipher::encryptBlob('x', self::PASS, 'argon2id');
    }

    public function test_rejects_an_unknown_kdf_name(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/Unknown KDF/');
        ArchiveCipher::resolveKdf('bcrypt');
    }

    public function test_resolve_kdf_defaults_to_pbkdf2(): void
    {
        foreach ([null, '', '  ', 'pbkdf2', 'PBKDF2'] as $input) {
            $this->assertSame(
                'pbkdf2',
                ArchiveCipher::resolveKdf($input === null ? null : (string) $input)['name'],
                var_export($input, true)
            );
        }
    }

    /** An Argon2id archive must still be readable where sodium exists. */
    public function test_argon2id_archive_round_trips_where_supported(): void
    {
        if (!ArchiveCipher::argon2Available()) {
            $this->markTestSkipped('libsodium/Argon2id not available on this host.');
        }
        $plain = random_bytes(9000);
        $blob = ArchiveCipher::encryptBlob($plain, self::PASS, 'argon2id');

        $this->assertSame($plain, ArchiveCipher::decryptBlob($blob, self::PASS));
    }

    public function test_pbkdf2_path_round_trips_regardless_of_sodium(): void
    {
        $salt = random_bytes(ArchiveCipher::SALT_BYTES);
        $key = ArchiveCipher::deriveKey(
            self::PASS,
            $salt,
            ArchiveCipher::KDF_PBKDF2_SHA256,
            ArchiveCipher::PBKDF2_ITERATIONS,
            0
        );

        $this->assertSame(ArchiveCipher::KEY_BYTES, strlen($key));
        $this->assertSame(
            $key,
            ArchiveCipher::deriveKey(self::PASS, $salt, ArchiveCipher::KDF_PBKDF2_SHA256, ArchiveCipher::PBKDF2_ITERATIONS, 0),
            'derivation must be deterministic'
        );
    }

    public function test_salt_changes_the_derived_key(): void
    {
        $a = ArchiveCipher::deriveKey(self::PASS, str_repeat("\1", 16), ArchiveCipher::KDF_PBKDF2_SHA256, 100000, 0);
        $b = ArchiveCipher::deriveKey(self::PASS, str_repeat("\2", 16), ArchiveCipher::KDF_PBKDF2_SHA256, 100000, 0);

        $this->assertNotSame($a, $b);
    }

    public function test_refuses_a_pbkdf2_iteration_count_below_the_floor(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/below the accepted floor/');
        ArchiveCipher::deriveKey(self::PASS, random_bytes(16), ArchiveCipher::KDF_PBKDF2_SHA256, 1000, 0);
    }

    public function test_refuses_an_unknown_kdf_id(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/Unknown archive KDF/');
        ArchiveCipher::deriveKey(self::PASS, random_bytes(16), 99, 1, 1);
    }

    // ------------------------------------------------------------- tamper cases

    public function test_wrong_passphrase_is_rejected(): void
    {
        $blob = ArchiveCipher::encryptBlob('secret', self::PASS);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');
        ArchiveCipher::decryptBlob($blob, 'this-is-the-wrong-passphrase');
    }

    public function test_flipping_one_ciphertext_bit_is_rejected(): void
    {
        $blob = ArchiveCipher::encryptBlob(str_repeat('a', 2000), self::PASS);
        $at = ArchiveCipher::HEADER_BYTES + 10;
        $blob[$at] = chr(ord($blob[$at]) ^ 0x01);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');
        ArchiveCipher::decryptBlob($blob, self::PASS);
    }

    public function test_flipping_one_tag_bit_is_rejected(): void
    {
        $blob = ArchiveCipher::encryptBlob('payload', self::PASS);
        $at = strlen($blob) - 3;
        $blob[$at] = chr(ord($blob[$at]) ^ 0x80);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');
        ArchiveCipher::decryptBlob($blob, self::PASS);
    }

    #[DataProvider('headerFields')]
    public function test_editing_an_authenticated_header_field_is_rejected(int $offset): void
    {
        $blob = ArchiveCipher::encryptBlob('payload', self::PASS);
        $blob[$offset] = chr(ord($blob[$offset]) ^ 0x01);

        $this->expectException(BrokerException::class);
        ArchiveCipher::decryptBlob($blob, self::PASS);
    }

    public static function headerFields(): array
    {
        return [
            'salt' => [20],
            'nonce prefix' => [35],
        ];
    }

    public function test_truncating_the_final_chunk_is_rejected(): void
    {
        $blob = self::encrypt(random_bytes(12000), 4096);
        // Drop the last full block, so the archive ends on a non-final chunk.
        $truncated = substr($blob, 0, ArchiveCipher::HEADER_BYTES + 2 * (4096 + ArchiveCipher::TAG_BYTES));

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/failed authentication|truncated/');
        self::decrypt($truncated);
    }

    public function test_dropping_trailing_bytes_is_rejected(): void
    {
        $blob = ArchiveCipher::encryptBlob(str_repeat('z', 3000), self::PASS);

        $this->expectException(BrokerException::class);
        ArchiveCipher::decryptBlob(substr($blob, 0, -20), self::PASS);
    }

    public function test_reordering_two_chunks_is_rejected(): void
    {
        $blob = self::encrypt(random_bytes(12000), 4096);
        $block = 4096 + ArchiveCipher::TAG_BYTES;
        $h = ArchiveCipher::HEADER_BYTES;
        $c0 = substr($blob, $h, $block);
        $c1 = substr($blob, $h + $block, $block);
        $rest = substr($blob, $h + 2 * $block);
        $swapped = substr($blob, 0, $h) . $c1 . $c0 . $rest;

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');
        self::decrypt($swapped);
    }

    public function test_duplicating_a_chunk_is_rejected(): void
    {
        $blob = self::encrypt(random_bytes(12000), 4096);
        $block = 4096 + ArchiveCipher::TAG_BYTES;
        $h = ArchiveCipher::HEADER_BYTES;
        $c0 = substr($blob, $h, $block);
        $spliced = substr($blob, 0, $h) . $c0 . substr($blob, $h);

        $this->expectException(BrokerException::class);
        self::decrypt($spliced);
    }

    public function test_splicing_a_chunk_from_another_archive_is_rejected(): void
    {
        $a = self::encrypt(random_bytes(12000), 4096);
        $b = self::encrypt(random_bytes(12000), 4096);
        $block = 4096 + ArchiveCipher::TAG_BYTES;
        $h = ArchiveCipher::HEADER_BYTES;
        $spliced = substr($a, 0, $h + $block) . substr($b, $h + $block);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/failed authentication/');
        self::decrypt($spliced);
    }

    // ------------------------------------------------------------ header checks

    public function test_rejects_a_foreign_magic(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/Not a LACMP2 archive/');
        ArchiveCipher::parseHeader('NOPE!!' . str_repeat("\0", 40));
    }

    public function test_rejects_a_future_format_version(): void
    {
        $blob = ArchiveCipher::encryptBlob('x', self::PASS);
        $blob[6] = chr(99);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/Unsupported LACMP2 format version/');
        ArchiveCipher::parseHeader(substr($blob, 0, ArchiveCipher::HEADER_BYTES));
    }

    public function test_rejects_an_unknown_aead_id(): void
    {
        $blob = ArchiveCipher::encryptBlob('x', self::PASS);
        $blob[8] = chr(42);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/Unsupported archive AEAD/');
        ArchiveCipher::parseHeader(substr($blob, 0, ArchiveCipher::HEADER_BYTES));
    }

    public function test_rejects_an_absurd_chunk_size(): void
    {
        $header = ArchiveCipher::buildHeader(
            ArchiveCipher::KDF_PBKDF2_SHA256,
            600000,
            0,
            random_bytes(16),
            random_bytes(4),
            16
        );

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/chunk size is out of range/');
        ArchiveCipher::parseHeader($header);
    }

    public function test_rejects_a_truncated_header(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/header is truncated/');
        ArchiveCipher::parseHeader('LACMP2' . "\1");
    }

    // ---------------------------------------------------- legacy compatibility

    public function test_still_reads_a_legacy_lacmp1_archive(): void
    {
        $legacy = ArchiveCrypto::encrypt('legacy payload', 'abcdefghijklmnopqrst');

        $this->assertSame('legacy', ArchiveCipher::detect($legacy));
        $this->assertSame('legacy payload', ArchiveCipher::decryptBlob($legacy, 'abcdefghijklmnopqrst'));
    }

    public function test_detects_unknown_formats(): void
    {
        $this->assertSame('unknown', ArchiveCipher::detect('random bytes here'));
    }

    public function test_new_archives_are_never_written_in_the_legacy_format(): void
    {
        $blob = ArchiveCipher::encryptBlob('x', self::PASS);

        $this->assertStringStartsWith('LACMP2', $blob);
        $this->assertStringStartsNotWith('LACMP1', $blob);
    }

    // ------------------------------------------------------------------ helpers

    private static function encrypt(string $plain, int $chunk): string
    {
        $out = '';
        $pos = 0;
        ArchiveCipher::encryptStream(
            static function (int $n) use ($plain, &$pos): string {
                $c = substr($plain, $pos, $n);
                $pos += strlen($c);

                return $c;
            },
            static function (string $c) use (&$out): void {
                $out .= $c;
            },
            self::PASS,
            $chunk
        );

        return $out;
    }

    private static function decrypt(string $blob): string
    {
        $out = '';
        $pos = 0;
        ArchiveCipher::decryptStream(
            static function (int $n) use ($blob, &$pos): string {
                $c = substr($blob, $pos, $n);
                $pos += strlen($c);

                return $c;
            },
            static function (string $c) use (&$out): void {
                $out .= $c;
            },
            self::PASS
        );

        return $out;
    }
}
