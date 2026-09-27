<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Files;

/**
 * Decides whether a zip may be extracted into a vhost (A40, B3 / request #10).
 *
 * Extract was excluded from the File Manager on purpose when it shipped, and reopening it was
 * made conditional on this guard plus an adversarial test suite. The condition is the point: an
 * extract runs as the site's identity inside the site's own document root, so a malicious archive
 * does not need to escape to the host to do damage — writing outside the selected directory, or
 * over the site's own code, is already the whole attack.
 *
 * What a crafted zip tries, and what happens here:
 *
 *  - **Traversal and absolute paths** (`../../etc/cron.d/x`, `/etc/passwd`, `C:\…`, a name that
 *    normalises above the root). Refused by name, before anything is written. Reuses
 *    Backup\ArchiveGuard::assertSafeName so the tar and zip paths cannot drift apart on the one
 *    rule they share.
 *  - **Symlinks and hardlinks.** A zip records them as an entry whose Unix mode says link; the
 *    payload is the target path. Extracted, they point wherever the archive says, and the *next*
 *    write through that name lands outside the root. Refused outright: there is no
 *    "target inside the archive" concession here, unlike a restore of the panel's own backup
 *    (A2.1), because this archive comes from whoever uploaded it.
 *  - **setuid, setgid and sticky bits.** Nothing extracted through a web file manager should
 *    carry them.
 *  - **Device nodes, FIFOs, sockets and anything that is not a regular file or a directory.**
 *  - **Zip bombs**, in three independent ways: total uncompressed size, entry count, and
 *    per-entry compression ratio. A single entry can claim to be terabytes; the declared sizes
 *    are checked before extraction, so the refusal costs nothing.
 *  - **Names that are legal but hostile**: control characters, a bare `.` or `..` component, an
 *    empty name, or one long enough to break a path limit somewhere downstream.
 *
 * Declared metadata is trusted only to *refuse*, never to accept: the extractor still verifies
 * each written path is inside the target directory afterwards. A zip can lie about sizes, and a
 * central directory can disagree with local headers.
 */
final class ZipExtractGuard
{
    /** Refuse before extracting rather than filling a disk and apologising. */
    public const MAX_TOTAL_BYTES = 2147483648;

    public const MAX_ENTRIES = 20000;

    /**
     * A ratio above this is not compression, it is a bomb. Text and source compress ~5-10x;
     * 200x is a file of zeroes whose only purpose is to expand.
     */
    public const MAX_RATIO = 200;

    /** Below this, ratio is meaningless — a tiny file can compress absurdly well by accident. */
    private const RATIO_FLOOR_BYTES = 65536;

    public const MAX_NAME_LENGTH = 512;

    /**
     * @return array{entries:int, bytes:int, names:list<string>}
     */
    public static function inspect(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new VhostFileException('Zip support is not available in this runtime.', 3);
        }

        // CHECKCONS is not enough on its own, which a truncated archive showed: libzip opened a
        // file whose central directory had been cut off entirely, reported one entry, and
        // declared its uncompressed size as 4096 bytes. Extraction would then fail part-way and
        // leave a partial file in the site. So the end-of-central-directory record is looked for
        // first — its absence means the file is not a complete archive, whatever libzip makes of
        // the fragment.
        self::assertCompleteArchive($path);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new VhostFileException('This file is not a readable zip archive.', 2);
        }

        try {
            $count = $zip->numFiles;
            if ($count === 0) {
                throw new VhostFileException('The archive is empty.', 2);
            }
            if ($count > self::MAX_ENTRIES) {
                throw new VhostFileException(
                    'The archive has ' . $count . ' entries; the limit is ' . self::MAX_ENTRIES . '.',
                    3
                );
            }

            $total = 0;
            $names = [];
            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new VhostFileException('The archive has an unreadable entry.', 2);
                }
                $name = (string) $stat['name'];
                self::assertSafeName($name);

                $size = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new VhostFileException(
                        'The archive expands to more than '
                        . (int) (self::MAX_TOTAL_BYTES / 1048576) . ' MiB.',
                        3
                    );
                }
                if ($size >= self::RATIO_FLOOR_BYTES && $compressed > 0
                    && intdiv($size, $compressed) > self::MAX_RATIO) {
                    throw new VhostFileException(
                        'Entry ' . $name . ' expands ' . intdiv($size, $compressed)
                        . '× — refusing a compression ratio above ' . self::MAX_RATIO . '.',
                        3
                    );
                }

                self::assertRegularOrDirectory($name, self::unixMode($zip, $i));
                $names[] = $name;
            }

            return ['entries' => $count, 'bytes' => $total, 'names' => $names];
        } finally {
            $zip->close();
        }
    }

    /**
     * A complete zip ends with an end-of-central-directory record: `PK\x05\x06`, at most 65535
     * bytes of comment after it. Its absence means the file was truncated, however much of the
     * front of it looks valid.
     */
    private static function assertCompleteArchive(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new VhostFileException('The archive could not be read.', 2);
        }
        $size = (int) filesize($path);
        // 22 bytes is the smallest possible EOCD, i.e. an empty archive.
        if ($size < 22) {
            throw new VhostFileException('This file is too small to be a zip archive.', 2);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new VhostFileException('The archive could not be opened.', 2);
        }
        try {
            $window = min($size, 65557);
            fseek($handle, -$window, SEEK_END);
            $tail = (string) fread($handle, $window);
        } finally {
            fclose($handle);
        }
        if (!str_contains($tail, "PK\x05\x06")) {
            throw new VhostFileException(
                'The archive is incomplete — its central directory is missing, which usually means '
                . 'the upload was truncated.',
                2
            );
        }
    }

    /**
     * Zip stores the Unix mode in the high 16 bits of the external attributes, when it was
     * written by a Unix tool. An archive from a tool that records nothing gets 0, which is
     * treated as an ordinary file — the name rules still apply.
     */
    private static function unixMode(\ZipArchive $zip, int $index): int
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return 0;
        }
        if ($opsys !== \ZipArchive::OPSYS_UNIX) {
            return 0;
        }

        return ($attr >> 16) & 0xFFFF;
    }

    private static function assertRegularOrDirectory(string $name, int $mode): void
    {
        if ($mode === 0) {
            return;
        }

        // S_IFMT
        $type = $mode & 0170000;
        if ($type === 0120000) {
            throw new VhostFileException(
                'Entry ' . $name . ' is a symlink. Links in an uploaded archive point wherever the '
                . 'archive says, so the next write through that name lands outside this site.',
                3
            );
        }
        if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
            throw new VhostFileException(
                'Entry ' . $name . ' is not a regular file or directory (mode '
                . sprintf('%06o', $mode) . ').',
                3
            );
        }
        if (($mode & 04000) !== 0 || ($mode & 02000) !== 0 || ($mode & 01000) !== 0) {
            throw new VhostFileException(
                'Entry ' . $name . ' carries a setuid, setgid or sticky bit.',
                3
            );
        }
    }

    /**
     * Path rules, shared with the tar guard so the two cannot drift apart. The extra checks here
     * are the ones zip allows and tar does not: backslash separators from Windows tools, and
     * drive letters.
     */
    public static function assertSafeName(string $name): void
    {
        if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
            throw new VhostFileException('The archive contains an empty or over-long entry name.', 3);
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new VhostFileException('An entry name contains control characters.', 3);
        }
        if (preg_match('#^[a-zA-Z]:[\\\\/]#', $name) === 1) {
            throw new VhostFileException('Entry ' . $name . ' has an absolute Windows path.', 3);
        }
        // A backslash is a legal filename character on Unix, but an archive using it as a
        // separator would extract as one long name and defeat the component checks below.
        if (str_contains($name, '\\')) {
            throw new VhostFileException('Entry ' . $name . ' uses backslash path separators.', 3);
        }

        try {
            \AzerioidPanel\Broker\Backup\ArchiveGuard::assertSafeName($name);
        } catch (\AzerioidPanel\Broker\BrokerException $e) {
            throw new VhostFileException($e->getMessage(), 3);
        }
    }
}
