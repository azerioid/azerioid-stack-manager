<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;

/**
 * Pre-extraction inspection of a tar archive (A2.1).
 *
 * Restore runs `tar -x` as **root**. GNU tar strips leading `/` and refuses
 * `..` by default, but it happily restores setuid bits, device nodes, and
 * symlinks/hardlinks recorded in the archive — and by default as the stored
 * uid/gid. Combined with archives living in remote object storage, a tampered
 * archive is a path to root-owned or setuid content under a docroot.
 *
 * This parses the **tar format itself** rather than the human-readable output of
 * `tar -tv`. That output is not a stable interface: GNU tar prints
 * `-rw-r--r-- 0/0 1234 2026-09-26 00:00 name` while bsdtar prints
 * `-rw-r--r-- 0 501 20 1234 Sep 26 05:05 name`, and both are affected by locale
 * and quoting style. Misparsing would reject every legitimate archive and break
 * restore entirely, which is worse than the risk being defended against. Header
 * offsets are fixed by POSIX, so this is deterministic on every host.
 *
 * Fails closed: anything not positively recognised as a regular file or
 * directory is rejected.
 */
final class ArchiveGuard
{
    public const BLOCK = 512;

    /** Hard ceiling on entries, to bound work and refuse absurd archives. */
    public const MAX_ENTRIES = 200_000;

    /** Hard ceiling on total uncompressed bytes (tar-bomb guard). */
    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024 * 1024;

    /** typeflag values we accept. */
    private const TYPE_FILE = ['0', "\0", ''];

    private const TYPE_DIR = '5';

    private const TYPE_HARDLINK = '1';

    private const TYPE_SYMLINK = '2';

    /** Typeflags refused outright — none of these belong in a site backup. */
    private const REJECTED_TYPES = [
        '3' => 'character device',
        '4' => 'block device',
        '6' => 'FIFO',
        '7' => 'contiguous file',
    ];

    /**
     * Inspect a gzipped tar by streaming it — never loads the archive into memory.
     *
     * @return array{entries:int, bytes:int, names:list<string>}
     */
    public static function inspectGzFile(
        string $path,
        int $maxTotalBytes = self::MAX_TOTAL_BYTES,
        int $maxEntries = self::MAX_ENTRIES,
    ): array {
        $fh = @gzopen($path, 'rb');
        if ($fh === false) {
            throw new BrokerException('Archive rejected: cannot open for inspection.', 3);
        }
        try {
            return self::inspectStream(
                static function (int $n) use ($fh): string {
                    $buf = '';
                    while (strlen($buf) < $n) {
                        $part = gzread($fh, $n - strlen($buf));
                        if ($part === false || $part === '') {
                            break;
                        }
                        $buf .= $part;
                    }

                    return $buf;
                },
                $maxTotalBytes,
                $maxEntries
            );
        } finally {
            gzclose($fh);
        }
    }

    /**
     * @param  callable(int):string  $read  returns exactly n bytes, or fewer at EOF
     * @return array{entries:int, bytes:int, names:list<string>}
     */
    public static function inspectStream(
        callable $read,
        int $maxTotalBytes = self::MAX_TOTAL_BYTES,
        int $maxEntries = self::MAX_ENTRIES,
    ): array {
        $names = [];
        $entries = 0;
        $bytes = 0;
        $emptyRun = 0;
        $pendingLongName = null;
        $pendingLinkName = null;

        while (true) {
            $header = $read(self::BLOCK);
            if ($header === '') {
                break;
            }
            if (strlen($header) < self::BLOCK) {
                throw new BrokerException('Archive rejected: truncated header block.', 3);
            }
            if (trim($header, "\0") === '') {
                // Two consecutive zero blocks mark end of archive.
                if (++$emptyRun >= 2) {
                    break;
                }
                continue;
            }
            $emptyRun = 0;

            $typeflag = substr($header, 156, 1);
            $size = self::parseSize($header);
            $dataBlocks = (int) ceil($size / self::BLOCK);

            // GNU long name / long link: the real name lives in the data payload.
            if ($typeflag === 'L' || $typeflag === 'K') {
                $payload = $read($dataBlocks * self::BLOCK);
                $value = rtrim(substr($payload, 0, $size), "\0");
                if ($typeflag === 'L') {
                    $pendingLongName = $value;
                } else {
                    $pendingLinkName = $value;
                }
                continue;
            }
            // pax extended headers: skip the payload, but do not trust a name we
            // did not read. The following real header still carries a name, and
            // any path override we ignore would only ever be *more* specific, so
            // refuse rather than risk validating the wrong string.
            if ($typeflag === 'x' || $typeflag === 'X' || $typeflag === 'g') {
                $payload = $read($dataBlocks * self::BLOCK);
                if (preg_match('/\d+ path=([^\n]*)\n/', substr($payload, 0, $size), $m) === 1) {
                    $pendingLongName = $m[1];
                }
                continue;
            }

            $name = $pendingLongName ?? self::parseName($header);
            $pendingLongName = null;

            $entries++;
            if ($entries > $maxEntries) {
                throw new BrokerException('Archive rejected: more than ' . $maxEntries . ' entries.', 3);
            }

            if (isset(self::REJECTED_TYPES[$typeflag])) {
                throw new BrokerException(
                    'Archive rejected: contains a ' . self::REJECTED_TYPES[$typeflag] . ' entry (' . $name . ').',
                    3
                );
            }
            $isFile = in_array($typeflag, self::TYPE_FILE, true);
            $isDir = $typeflag === self::TYPE_DIR;
            $isLink = $typeflag === self::TYPE_SYMLINK || $typeflag === self::TYPE_HARDLINK;
            if (!$isFile && !$isDir && !$isLink) {
                throw new BrokerException(
                    'Archive rejected: unclassifiable entry type "' . addcslashes($typeflag, "\0..\37") . '" (' . $name . ').',
                    3
                );
            }

            // Links are allowed only while they stay inside the archive. A site
            // backup legitimately contains them — `artisan storage:link` leaves
            // public/storage -> ../storage/app/public, and rejecting that would
            // make most Laravel sites unrestorable. A link escaping the root is
            // a different matter: extracted as root it can expose or clobber
            // host files through the docroot.
            if ($isLink) {
                $target = $pendingLinkName ?? self::parseLinkName($header);
                self::assertSafeLinkTarget($name, $target, $typeflag);
            }
            $pendingLinkName = null;

            $mode = self::parseMode($header);
            if (($mode & 04000) !== 0) {
                throw new BrokerException('Archive rejected: setuid entry (' . $name . ').', 3);
            }
            if (($mode & 02000) !== 0) {
                throw new BrokerException('Archive rejected: setgid entry (' . $name . ').', 3);
            }

            self::assertSafeName($name);

            $bytes += $size;
            if ($bytes > $maxTotalBytes) {
                throw new BrokerException(
                    'Archive rejected: uncompressed size exceeds the ' . $maxTotalBytes . ' byte limit.',
                    3
                );
            }

            $names[] = rtrim($name, '/') !== '' ? rtrim($name, '/') : $name;

            if ($dataBlocks > 0) {
                $skipped = $read($dataBlocks * self::BLOCK);
                if (strlen($skipped) < $dataBlocks * self::BLOCK) {
                    throw new BrokerException('Archive rejected: truncated entry data (' . $name . ').', 3);
                }
            }
        }

        if ($entries === 0) {
            throw new BrokerException('Archive rejected: no entries found.', 3);
        }

        return ['entries' => $entries, 'bytes' => $bytes, 'names' => $names];
    }

    public static function assertSafeName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new BrokerException('Archive rejected: empty or NUL-bearing entry name.', 3);
        }
        if (str_starts_with($name, '/')) {
            throw new BrokerException('Archive rejected: absolute path entry (' . $name . ').', 3);
        }
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $name) === 1) {
            throw new BrokerException('Archive rejected: drive-qualified path entry (' . $name . ').', 3);
        }
        foreach (preg_split('#[/\\\\]#', $name) ?: [] as $segment) {
            if ($segment === '..') {
                throw new BrokerException('Archive rejected: path traversal entry (' . $name . ').', 3);
            }
        }
    }

    public static function parseLinkName(string $header): string
    {
        return rtrim(substr($header, 157, 100), "\0");
    }

    /**
     * A link may not resolve outside the archive root.
     *
     * The target is resolved lexically against the link's own directory, so
     * `public/storage -> ../storage/app/public` is accepted while
     * `public/x -> ../../../etc/shadow` is not.
     */
    public static function assertSafeLinkTarget(string $name, string $target, string $typeflag): void
    {
        $kind = $typeflag === self::TYPE_HARDLINK ? 'hardlink' : 'symlink';
        if ($target === '' || str_contains($target, "\0")) {
            throw new BrokerException('Archive rejected: ' . $kind . ' with an empty target (' . $name . ').', 3);
        }
        if (str_starts_with($target, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $target) === 1) {
            throw new BrokerException(
                'Archive rejected: ' . $kind . ' to an absolute path (' . $name . ' -> ' . $target . ').',
                3
            );
        }

        // Hardlink targets in tar are recorded relative to the archive root;
        // symlink targets are relative to the link's own directory.
        $base = $typeflag === self::TYPE_HARDLINK ? '' : self::lexicalDirname($name);
        $combined = $base === '' ? $target : $base . '/' . $target;

        $depth = 0;
        foreach (preg_split('#[/\\\\]#', $combined) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (--$depth < 0) {
                    throw new BrokerException(
                        'Archive rejected: ' . $kind . ' escapes the archive root (' . $name . ' -> ' . $target . ').',
                        3
                    );
                }
                continue;
            }
            $depth++;
        }
    }

    private static function lexicalDirname(string $name): string
    {
        $normalised = str_replace('\\', '/', rtrim($name, '/'));
        $pos = strrpos($normalised, '/');

        return $pos === false ? '' : substr($normalised, 0, $pos);
    }

    /** ustar splits long paths across prefix (345..499) and name (0..99). */
    public static function parseName(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        $magic = substr($header, 257, 5);
        if ($magic === 'ustar') {
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
        }

        return $name;
    }

    public static function parseMode(string $header): int
    {
        return self::octal(substr($header, 100, 8), 'mode');
    }

    /** Size is octal, or GNU base-256 when the high bit of the first byte is set. */
    public static function parseSize(string $header): int
    {
        $raw = substr($header, 124, 12);
        if ($raw === '') {
            throw new BrokerException('Archive rejected: missing size field.', 3);
        }
        if ((ord($raw[0]) & 0x80) !== 0) {
            $value = 0;
            // Two's-complement base-256; only non-negative sizes are meaningful.
            $bytes = substr($raw, 1);
            for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
                $value = ($value << 8) | ord($bytes[$i]);
                if ($value < 0) {
                    throw new BrokerException('Archive rejected: size field overflow.', 3);
                }
            }

            return $value;
        }

        return self::octal($raw, 'size');
    }

    private static function octal(string $field, string $label): int
    {
        $clean = trim($field, " \0");
        if ($clean === '') {
            return 0;
        }
        if (preg_match('/^[0-7]+$/', $clean) !== 1) {
            throw new BrokerException('Archive rejected: malformed octal ' . $label . ' field.', 3);
        }

        return (int) octdec($clean);
    }

    /** Flags that stop tar honouring archive-recorded ownership and permissions. */
    public static function safeExtractFlags(): array
    {
        return ['--no-same-owner', '--no-same-permissions', '--no-overwrite-dir'];
    }
}
