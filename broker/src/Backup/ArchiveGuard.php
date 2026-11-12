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

    /** pax keywords accepted (A103); everything else is refused. */
    private const PAX_ALLOWED = ['path', 'linkpath', 'mtime', 'atime', 'ctime', 'uid', 'gid', 'uname', 'gname', 'comment', 'charset'];

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
     * @param  bool  $anySymlinkTarget  a site clone (A103) keeps symlinks as they are —
     *         `artisan storage:link` writes an absolute one — and the clone's A72 sweep
     *         quarantines a link that escapes its tree. A clone stream carries no
     *         hardlinks at all (it is made with --hard-dereference), so one is refused.
     * @return array{entries:int, bytes:int, names:list<string>}
     */
    public static function inspectStream(
        callable $read,
        int $maxTotalBytes = self::MAX_TOTAL_BYTES,
        int $maxEntries = self::MAX_ENTRIES,
        bool $anySymlinkTarget = false,
    ): array {
        $names = [];
        $symlinks = [];
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
                // A103: a site copy is made in GNU format, which never writes pax
                // headers; one in the stream is crafted.
                if ($anySymlinkTarget) {
                    throw new BrokerException('Archive rejected: pax header in a site copy.', 3);
                }
                $payload = $read($dataBlocks * self::BLOCK);
                $pax = self::paxRecords(substr($payload, 0, $size));
                // Only keywords that change nothing this parser reads. tar also takes
                // the member's size (data layout), sparse maps and names from pax
                // (`size`, `GNU.sparse.*`) — any of those would make tar read a
                // different archive than the one inspected.
                foreach (array_keys($pax) as $key) {
                    if (!in_array($key, self::PAX_ALLOWED, true)) {
                        throw new BrokerException('Archive rejected: pax keyword "' . addcslashes($key, "\0..\37") . '" is not accepted.', 3);
                    }
                }
                // A global header's path/linkpath would apply to every later entry;
                // no backup this panel writes has one.
                if ($typeflag === 'g' && (isset($pax['path']) || isset($pax['linkpath']))) {
                    throw new BrokerException('Archive rejected: global pax path override.', 3);
                }
                if ($typeflag !== 'g') {
                    if (isset($pax['path'])) {
                        $pendingLongName = $pax['path'];
                    }
                    // tar uses a pax linkpath over the header's link name; check that one.
                    if (isset($pax['linkpath'])) {
                        $pendingLinkName = $pax['linkpath'];
                    }
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
            $clean = self::normalised($name);
            if ($isLink) {
                $target = $pendingLinkName ?? self::parseLinkName($header);
                if ($anySymlinkTarget && $typeflag === self::TYPE_HARDLINK) {
                    throw new BrokerException('Archive rejected: hardlink in a site copy (' . $name . ').', 3);
                }
                if (!$anySymlinkTarget) {
                    self::assertSafeLinkTarget($name, $target, $typeflag);
                } elseif ($target === '' || str_contains($target, "\0")) {
                    throw new BrokerException('Archive rejected: symlink with an empty target (' . $name . ').', 3);
                }
                // A hardlink resolves its target path at extraction time: one through (or
                // to) a symlink would link a file outside the tree into it.
                if ($typeflag === self::TYPE_HARDLINK) {
                    $t = self::normalised($target);
                    if (isset($symlinks[$t]) || self::belowSymlink($t, $symlinks)) {
                        throw new BrokerException('Archive rejected: hardlink through a symlink (' . $name . ' -> ' . $target . ').', 3);
                    }
                }
            }
            $pendingLinkName = null;

            // A103: no entry may be extracted through a symlink an earlier entry made —
            // tar writing `link/file` after `link -> /elsewhere` would write elsewhere.
            // tar never archives a path below a symlink, so only a crafted stream has one.
            if (self::belowSymlink($clean, $symlinks)) {
                throw new BrokerException('Archive rejected: entry below a symlink (' . $name . ').', 3);
            }
            // Once a symlink, always: a later entry of the same name does not make the
            // path safe to write through (tar may keep an existing directory link).
            if ($typeflag === self::TYPE_SYMLINK) {
                $symlinks[$clean] = true;
            }

            $mode = self::parseMode($header);
            if (($mode & 04000) !== 0) {
                throw new BrokerException('Archive rejected: setuid entry (' . $name . ').', 3);
            }
            // setgid on a directory only makes new files inherit its group — every panel
            // docroot is 2770 (A25/A49), so refusing it refused every site restore. On a
            // file it is a privilege grant, and stays refused.
            if (($mode & 02000) !== 0 && $typeflag !== '5') {
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

    /**
     * pax records ("<len> <key>=<value>\n"), read by their length prefix as tar
     * does; a later record for the same key wins.
     *
     * @return array<string, string>
     */
    private static function paxRecords(string $data): array
    {
        $out = [];
        $pos = 0;
        $end = strlen($data);
        while ($pos < $end && preg_match('/\G(\d+) /', $data, $m, 0, $pos) === 1) {
            $len = (int) $m[1];
            $record = substr($data, $pos, $len);
            if ($len <= strlen($m[0]) || strlen($record) !== $len || !str_ends_with($record, "\n")) {
                throw new BrokerException('Archive rejected: malformed pax header.', 3);
            }
            $kv = substr($record, strlen($m[0]), -1);
            $eq = strpos($kv, '=');
            if ($eq === false) {
                throw new BrokerException('Archive rejected: malformed pax header.', 3);
            }
            $out[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
            $pos += $len;
        }
        if (trim(substr($data, $pos), "\0") !== '') {
            throw new BrokerException('Archive rejected: malformed pax header.', 3);
        }

        return $out;
    }

    /** A member name as tar resolves it: separators collapsed, `.` segments dropped. */
    private static function normalised(string $name): string
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', $name)), static fn (string $s): bool => $s !== '' && $s !== '.');

        return implode('/', $parts);
    }

    /** @param array<string, true> $symlinks */
    private static function belowSymlink(string $clean, array $symlinks): bool
    {
        for ($p = strrpos($clean, '/'); $p !== false; $p = strrpos(substr($clean, 0, $p), '/')) {
            if (isset($symlinks[substr($clean, 0, $p)])) {
                return true;
            }
        }

        return false;
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
        // Only POSIX ustar ("ustar\0") has a prefix field; GNU tar's own format
        // ("ustar  \0") keeps other data there and tar does not join it (A103).
        $magic = substr($header, 257, 6);
        if ($magic === "ustar\0") {
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
