<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Backup\ArchiveGuard;
use AzerioidPanel\Broker\BrokerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A2.1 — restore runs `tar -x` as root, so every dangerous entry must be
 * refused before extraction. Fail closed.
 *
 * Archives are synthesised byte-for-byte rather than shelled out to `tar`, so
 * the suite proves the header parser against the on-disk format itself and runs
 * identically on GNU and BSD hosts.
 */
final class ArchiveGuardTest extends TestCase
{
    // ----------------------------------------------------------- tar builders

    /** Build one 512-byte ustar header. */
    private static function header(
        string $name,
        int $size = 0,
        string $typeflag = '0',
        int $mode = 0644,
        string $prefix = '',
        string $linkname = '',
    ): string {
        $h = str_repeat("\0", 512);
        $put = static function (string $buf, int $off, string $val) : string {
            return substr_replace($buf, $val, $off, strlen($val));
        };
        $h = $put($h, 0, substr($name, 0, 100));
        $h = $put($h, 100, sprintf('%07o', $mode) . "\0");
        $h = $put($h, 108, sprintf('%07o', 0) . "\0");
        $h = $put($h, 116, sprintf('%07o', 0) . "\0");
        $h = $put($h, 124, sprintf('%011o', $size) . "\0");
        $h = $put($h, 136, sprintf('%011o', 1790000000) . "\0");
        $h = $put($h, 156, $typeflag);
        if ($linkname !== '') {
            $h = $put($h, 157, substr($linkname, 0, 100));
        }
        $h = $put($h, 257, "ustar\0" . '00');
        if ($prefix !== '') {
            $h = $put($h, 345, substr($prefix, 0, 155));
        }
        // checksum: spaces during computation, then octal
        $h = $put($h, 148, str_repeat(' ', 8));
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }

        return $put($h, 148, sprintf('%06o', $sum) . "\0 ");
    }

    private static function entry(
        string $name,
        string $body = '',
        string $typeflag = '0',
        int $mode = 0644,
        string $prefix = '',
        string $linkname = '',
    ): string {
        $out = self::header($name, strlen($body), $typeflag, $mode, $prefix, $linkname);
        if ($body !== '') {
            $out .= str_pad($body, (int) (ceil(strlen($body) / 512) * 512), "\0");
        }

        return $out;
    }

    private static function archive(string ...$entries): string
    {
        return implode('', $entries) . str_repeat("\0", 1024);
    }

    /** @return callable(int):string */
    private static function reader(string $tar): callable
    {
        $pos = 0;

        return static function (int $n) use ($tar, &$pos): string {
            $out = substr($tar, $pos, $n);
            $pos += strlen($out);

            return $out;
        };
    }

    private static function inspect(string $tar, int $maxBytes = ArchiveGuard::MAX_TOTAL_BYTES): array
    {
        return ArchiveGuard::inspectStream(self::reader($tar), $maxBytes);
    }

    // ------------------------------------------------------------- happy path

    public function test_accepts_a_plain_archive(): void
    {
        $out = self::inspect(self::archive(
            self::entry('site/index.php', 'hello'),
            self::entry('site/sub', '', '5', 0755),
        ));

        $this->assertSame(2, $out['entries']);
        $this->assertSame(5, $out['bytes']);
        $this->assertSame(['site/index.php', 'site/sub'], $out['names']);
    }

    public function test_accepts_names_with_spaces(): void
    {
        $out = self::inspect(self::archive(self::entry('site/my file name.txt', 'x')));

        $this->assertSame(['site/my file name.txt'], $out['names']);
    }

    public function test_accepts_ustar_prefix_split_paths(): void
    {
        $out = self::inspect(self::archive(
            self::entry('deep/file.txt', 'x', '0', 0644, 'site/very/long/prefix')
        ));

        $this->assertSame(['site/very/long/prefix/deep/file.txt'], $out['names']);
    }

    public function test_accepts_typeflag_nul_as_regular_file(): void
    {
        $out = self::inspect(self::archive(self::entry('site/legacy.txt', 'x', "\0")));

        $this->assertSame(['site/legacy.txt'], $out['names']);
    }

    // --------------------------------------------------- dangerous entry types

    #[DataProvider('dangerousTypes')]
    public function test_rejects_dangerous_type(string $typeflag, string $expect): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expect, '/') . '/i');
        self::inspect(self::archive(
            self::entry('site/ok.txt', 'x'),
            self::entry('site/bad', '', $typeflag),
        ));
    }

    public static function dangerousTypes(): array
    {
        return [
            'char device' => ['3', 'character device'],
            'block device' => ['4', 'block device'],
            'fifo' => ['6', 'FIFO'],
            'contiguous' => ['7', 'contiguous file'],
            'unknown' => ['Z', 'unclassifiable'],
        ];
    }

    // ------------------------------------------------------------ setuid/setgid

    public function test_rejects_setuid(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/setuid/');
        self::inspect(self::archive(self::entry('site/rootshell', 'x', '0', 04755)));
    }

    public function test_rejects_setgid(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/setgid/');
        self::inspect(self::archive(self::entry('site/gidshell', 'x', '0', 02755)));
    }

    public function test_rejects_setuid_without_exec_bit(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/setuid/');
        self::inspect(self::archive(self::entry('site/odd', 'x', '0', 04644)));
    }

    // ---------------------------------------------------------- path traversal

    #[DataProvider('dangerousNames')]
    public function test_rejects_dangerous_name(string $name, string $expect): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expect, '/') . '/i');
        self::inspect(self::archive(self::entry($name, 'x')));
    }

    public static function dangerousNames(): array
    {
        return [
            'absolute' => ['/etc/cron.d/pwn', 'absolute path'],
            'traversal' => ['site/../../etc/cron.d/pwn', 'path traversal'],
            'bare traversal' => ['../escape', 'path traversal'],
            'backslash traversal' => ['site\\..\\..\\escape', 'path traversal'],
            'drive qualified' => ['C:/windows/evil', 'drive-qualified'],
        ];
    }

    public function test_rejects_traversal_hidden_in_ustar_prefix(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/path traversal/');
        self::inspect(self::archive(self::entry('f.txt', 'x', '0', 0644, '../../etc')));
    }

    public function test_rejects_traversal_hidden_in_gnu_long_name(): void
    {
        $long = "site/../../etc/cron.d/pwn";
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/path traversal/');
        self::inspect(self::archive(
            self::entry('././@LongLink', $long, 'L'),
            self::entry('placeholder', 'x'),
        ));
    }

    public function test_rejects_traversal_hidden_in_pax_path(): void
    {
        $path = '../../etc/cron.d/pwn';
        $record = strlen("0 path=$path\n");
        // pax records are "<len> path=<value>\n" where len counts itself
        for ($len = $record; ; $len++) {
            $candidate = "$len path=$path\n";
            if (strlen($candidate) === $len) {
                break;
            }
        }
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/path traversal/');
        self::inspect(self::archive(
            self::entry('PaxHeader', $candidate, 'x'),
            self::entry('placeholder', 'x'),
        ));
    }

    public function test_gnu_long_name_is_used_for_the_following_entry(): void
    {
        $long = 'site/' . str_repeat('a', 120) . '/file.txt';
        $out = self::inspect(self::archive(
            self::entry('././@LongLink', $long, 'L'),
            self::entry('truncated-name', 'x'),
        ));

        $this->assertSame([$long], $out['names']);
    }

    // ------------------------------------------------------------------- limits

    public function test_rejects_tar_bomb_by_total_size(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/uncompressed size exceeds/');
        self::inspect(
            self::archive(self::entry('site/big', str_repeat('x', 4096))),
            1024
        );
    }

    public function test_rejects_entry_count_over_the_cap(): void
    {
        $entries = [];
        for ($i = 0; $i < 6; $i++) {
            $entries[] = self::entry("site/f{$i}.txt", 'x');
        }
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/more than 5 entries/');
        ArchiveGuard::inspectStream(
            self::reader(self::archive(...$entries)),
            ArchiveGuard::MAX_TOTAL_BYTES,
            5
        );
    }

    public function test_rejects_empty_archive(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/no entries/');
        self::inspect(str_repeat("\0", 1024));
    }

    public function test_rejects_truncated_header(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/truncated header/');
        self::inspect(substr(self::header('site/a.txt', 5), 0, 300));
    }

    public function test_rejects_truncated_entry_data(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/truncated entry data/');
        // header claims 5 bytes of payload, but no data block follows
        self::inspect(self::header('site/a.txt', 5));
    }

    public function test_rejects_malformed_octal_size(): void
    {
        $h = self::header('site/a.txt', 0);
        $h = substr_replace($h, '99999999999' . "\0", 124, 12);
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/malformed octal size/');
        self::inspect($h . str_repeat("\0", 1024));
    }

    public function test_reads_gnu_base256_size(): void
    {
        $h = self::header('site/a.txt', 0);
        $raw = "\x80" . str_repeat("\0", 10) . "\x05";
        $h = substr_replace($h, $raw, 124, 12);
        $this->assertSame(5, ArchiveGuard::parseSize($h));
    }

    // ------------------------------------------------------------------- flags

    // -------------------------------------------------------------------- links

    /** `artisan storage:link` produces exactly this; it must survive restore. */
    public function test_accepts_laravel_storage_symlink(): void
    {
        $out = self::inspect(self::archive(
            self::entry('site/public/storage', '', '2', 0777, '', '../storage/app/public'),
        ));

        $this->assertSame(['site/public/storage'], $out['names']);
    }

    public function test_accepts_symlink_inside_the_same_directory(): void
    {
        $out = self::inspect(self::archive(
            self::entry('site/current', '', '2', 0777, '', 'releases/v1'),
        ));

        $this->assertSame(['site/current'], $out['names']);
    }

    public function test_accepts_hardlink_to_an_in_archive_path(): void
    {
        $out = self::inspect(self::archive(
            self::entry('site/a.txt', 'x'),
            self::entry('site/b.txt', '', '1', 0644, '', 'site/a.txt'),
        ));

        $this->assertSame(['site/a.txt', 'site/b.txt'], $out['names']);
    }

    #[DataProvider('escapingLinks')]
    public function test_rejects_escaping_link(string $name, string $target, string $type, string $expect): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expect, '/') . '/i');
        self::inspect(self::archive(self::entry($name, '', $type, 0777, '', $target)));
    }

    public static function escapingLinks(): array
    {
        return [
            'absolute symlink' => ['site/evil', '/etc/shadow', '2', 'absolute path'],
            'absolute hardlink' => ['site/evil', '/etc/shadow', '1', 'absolute path'],
            'symlink climbing out' => ['site/public/evil', '../../../etc/shadow', '2', 'escapes the archive root'],
            'symlink climbing out from root' => ['site/evil', '../../etc/shadow', '2', 'escapes the archive root'],
            'hardlink climbing out' => ['site/evil', '../../etc/shadow', '1', 'escapes the archive root'],
            'empty target' => ['site/evil', '', '2', 'empty target'],
        ];
    }

    public function test_rejects_escaping_link_hidden_in_gnu_long_link(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/escapes the archive root/');
        self::inspect(self::archive(
            self::entry('././@LongLink', '../../../../etc/shadow', 'K'),
            self::entry('site/evil', '', '2', 0777, '', 'harmless'),
        ));
    }

        public function test_safe_extract_flags_disable_ownership_and_permission_restore(): void
    {
        $flags = ArchiveGuard::safeExtractFlags();

        $this->assertContains('--no-same-owner', $flags);
        $this->assertContains('--no-same-permissions', $flags);
        $this->assertContains('--no-overwrite-dir', $flags);
    }

    // ------------------------------------------- real gzip round-trip via file

    public function test_inspect_gz_file_streams_a_real_gzip(): void
    {
        $tar = self::archive(
            self::entry('site/index.php', 'hello'),
            self::entry('site/sub', '', '5', 0755),
        );
        $path = tempnam(sys_get_temp_dir(), 'azguard');
        $this->assertIsString($path);
        file_put_contents($path, gzencode($tar));
        try {
            $out = ArchiveGuard::inspectGzFile($path);
            $this->assertSame(['site/index.php', 'site/sub'], $out['names']);
        } finally {
            @unlink($path);
        }
    }

    public function test_inspect_gz_file_rejects_a_setuid_entry_end_to_end(): void
    {
        $tar = self::archive(self::entry('site/rootshell', 'x', '0', 04755));
        $path = tempnam(sys_get_temp_dir(), 'azguard');
        $this->assertIsString($path);
        file_put_contents($path, gzencode($tar));
        try {
            $this->expectException(BrokerException::class);
            $this->expectExceptionMessageMatches('/setuid/');
            ArchiveGuard::inspectGzFile($path);
        } finally {
            @unlink($path);
        }
    }
}
