<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Files;

/**
 * All vhost file I/O. The CLI helper drops to the vhost user before calling
 * execute(), so this code never runs with root privileges against site files
 * on the live broker path.
 */
final class VhostFileOp
{
    /**
     * No archive extract/unzip in v1 — zip-slip is out of scope rather than
     * a naive ZipArchive loop.
     */
    public const OPS = ['list', 'read', 'write', 'mkdir', 'rename', 'move', 'copy', 'chmod', 'search', 'delete'];

    /**
     * @param  array<string, mixed>  $req
     * @return array<string, mixed>
     */
    public static function execute(array $req): array
    {
        $op = strtolower(trim((string) ($req['op'] ?? '')));
        if (!in_array($op, self::OPS, true)) {
            throw new VhostFileException('Unknown file operation.', 2);
        }
        $root = (string) ($req['root'] ?? '');
        $path = (string) ($req['path'] ?? '');
        $maxBytes = (int) ($req['max_bytes'] ?? 20971520);
        if ($maxBytes < 1) {
            $maxBytes = 20971520;
        }

        return match ($op) {
            'list' => self::list($root, $path),
            'read' => self::read($root, $path, $maxBytes),
            'write' => self::write($root, $path, (string) ($req['content_base64'] ?? ''), $maxBytes),
            'mkdir' => self::mkdir($root, $path),
            'rename', 'move' => self::rename($root, $path, (string) ($req['dest'] ?? '')),
            'copy' => self::copy($root, $path, (string) ($req['dest'] ?? '')),
            'chmod' => self::chmod($root, $path, (string) ($req['mode'] ?? '')),
            'search' => self::search(
                $root,
                $path,
                (string) ($req['query'] ?? ''),
                (string) ($req['contains'] ?? '')
            ),
            'delete' => self::delete($root, $path, (bool) ($req['recursive'] ?? false)),
            default => throw new VhostFileException('Unknown file operation.', 2),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function list(string $root, string $rel): array
    {
        $real = VhostPath::resolveExisting($root, $rel);
        if (!is_dir($real)) {
            throw new VhostFileException('Not a directory.', 2);
        }
        $rootReal = realpath(VhostPath::normalizeRoot($root));
        if ($rootReal === false) {
            throw new VhostFileException('Vhost root is not a directory.', 2);
        }
        $entries = [];
        $names = @scandir($real);
        if ($names === false) {
            throw new VhostFileException('Unable to list directory.', 1);
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $real . '/' . $name;
            $isLink = is_link($full);
            $escaped = false;
            $type = 'file';
            $size = 0;
            $mtime = 0;
            $mode = '';
            $targetReal = @realpath($full);
            if ($isLink && ($targetReal === false || !VhostPath::isUnder($rootReal, $targetReal))) {
                $escaped = true;
                $type = 'symlink';
            } elseif (is_dir($full) && !$escaped) {
                $type = 'dir';
            }
            if (!$escaped) {
                $stat = @lstat($full);
                if (is_array($stat)) {
                    $size = (int) ($stat['size'] ?? 0);
                    $mtime = (int) ($stat['mtime'] ?? 0);
                    $mode = substr(sprintf('%o', (int) ($stat['mode'] ?? 0)), -4);
                }
            }
            $entries[] = [
                'name' => $name,
                'type' => $type,
                'size' => $size,
                'mtime' => $mtime,
                'mode' => $mode,
                'link' => $isLink,
                'escaped' => $escaped,
            ];
        }
        usort($entries, static function (array $a, array $b): int {
            $ad = ($a['type'] ?? '') === 'dir' ? 0 : 1;
            $bd = ($b['type'] ?? '') === 'dir' ? 0 : 1;
            if ($ad !== $bd) {
                return $ad <=> $bd;
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'path' => VhostPath::relativeToRoot($rootReal, $real),
            'root' => $rootReal,
            'entries' => $entries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $root, string $rel, int $maxBytes): array
    {
        $real = VhostPath::resolveExisting($root, $rel);
        if (is_dir($real)) {
            throw new VhostFileException('Cannot read a directory as a file.', 2);
        }
        $size = filesize($real);
        if ($size === false) {
            throw new VhostFileException('Unable to read file.', 1);
        }
        if ($size > $maxBytes) {
            throw new VhostFileException('File exceeds the size limit (' . $maxBytes . ' bytes).', 2);
        }
        $bytes = file_get_contents($real);
        if ($bytes === false) {
            throw new VhostFileException('Unable to read file.', 1);
        }
        $text = !str_contains($bytes, "\0") && mb_check_encoding($bytes, 'UTF-8');

        return [
            'path' => VhostPath::relativeToRoot((string) realpath(VhostPath::normalizeRoot($root)), $real),
            'size' => strlen($bytes),
            'mtime' => (int) filemtime($real),
            'content_base64' => base64_encode($bytes),
            'text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function write(string $root, string $rel, string $b64, int $maxBytes): array
    {
        if ($b64 !== '' && !preg_match('#^[A-Za-z0-9+/]*={0,2}$#', $b64)) {
            throw new VhostFileException('Invalid file content encoding.', 2);
        }
        $bytes = $b64 === '' ? '' : base64_decode($b64, true);
        if ($bytes === false) {
            throw new VhostFileException('Invalid file content encoding.', 2);
        }
        if (strlen($bytes) > $maxBytes) {
            throw new VhostFileException('File exceeds the size limit (' . $maxBytes . ' bytes).', 2);
        }
        $resolved = VhostPath::resolveCreate($root, $rel);
        if (is_dir($resolved['dest']) && !is_link($resolved['dest'])) {
            throw new VhostFileException('Cannot overwrite a directory.', 2);
        }
        $ok = @file_put_contents($resolved['dest'], $bytes, LOCK_EX);
        if ($ok === false) {
            throw new VhostFileException('Unable to write file.', 1);
        }
        @chmod($resolved['dest'], 0660);
        clearstatcache(true, $resolved['dest']);
        $real = realpath($resolved['dest']);
        if ($real === false || !VhostPath::isUnder($resolved['root'], $real)) {
            @unlink($resolved['dest']);
            throw new VhostFileException('Write resolved outside the vhost directory; file was not kept.', 3);
        }

        return [
            'path' => VhostPath::relativeToRoot($resolved['root'], $real),
            'size' => strlen($bytes),
            'written' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mkdir(string $root, string $rel): array
    {
        $resolved = VhostPath::resolveCreate($root, $rel);
        if (file_exists($resolved['dest']) || is_link($resolved['dest'])) {
            throw new VhostFileException('Path already exists.', 3);
        }
        if (!@mkdir($resolved['dest'], 02770)) {
            throw new VhostFileException('Unable to create directory.', 1);
        }
        @chmod($resolved['dest'], 02770);
        $real = realpath($resolved['dest']);
        if ($real === false || !VhostPath::isUnder($resolved['root'], $real)) {
            @rmdir($resolved['dest']);
            throw new VhostFileException('Directory resolved outside the vhost directory; it was not kept.', 3);
        }

        return [
            'path' => VhostPath::relativeToRoot($resolved['root'], $real),
            'created' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rename(string $root, string $fromRel, string $toRel): array
    {
        $fromRel = trim($fromRel);
        if (trim($toRel) === '') {
            throw new VhostFileException('Destination path is required.', 2);
        }
        $src = VhostPath::resolveExisting($root, $fromRel);
        $rootReal = (string) realpath(VhostPath::normalizeRoot($root));
        $fromOut = VhostPath::relativeToRoot($rootReal, $src);
        if ($fromOut === '') {
            throw new VhostFileException('Refusing to rename the vhost root.', 3);
        }
        $destInfo = VhostPath::resolveCreate($root, $toRel);
        if (file_exists($destInfo['dest']) || is_link($destInfo['dest'])) {
            throw new VhostFileException('Destination already exists.', 3);
        }
        if (!@rename($src, $destInfo['dest'])) {
            throw new VhostFileException('Unable to rename or move path.', 1);
        }
        $real = realpath($destInfo['dest']);
        if ($real === false || !VhostPath::isUnder($rootReal, $real)) {
            @rename($destInfo['dest'], $src);
            throw new VhostFileException('Move resolved outside the vhost directory; it was reversed.', 3);
        }

        return [
            'from' => $fromOut,
            'path' => VhostPath::relativeToRoot($rootReal, $real),
            'renamed' => true,
        ];
    }

    /** A search must answer, or refuse, inside one request — never wander a whole disk. */
    private const SEARCH_MAX_RESULTS = 200;

    private const SEARCH_MAX_ENTRIES = 50000;

    private const SEARCH_MAX_DEPTH = 12;

    private const SEARCH_GREP_MAX_BYTES = 262144;

    /**
     * Recursive search under a directory (B3 / request #10).
     *
     * Every limit here exists because this runs inside a request an operator is waiting on,
     * against a tree whose size nobody controls — `node_modules` and `vendor` are normal.
     * Unbounded, this is a way to make the panel appear broken by typing one letter.
     *
     * Symlinked directories are **not followed**. Following them would leave the vhost root
     * through a link the site itself can create, and would loop forever on a link pointing
     * at its own parent.
     *
     * `contains` greps file content, and only of files small enough to read: the point is
     * finding a setting in a config file, not scanning uploaded video.
     *
     * @return array<string, mixed>
     */
    private static function search(string $root, string $rel, string $query, string $contains): array
    {
        $query = trim($query);
        $contains = trim($contains);
        if ($query === '' && $contains === '') {
            throw new VhostFileException('Provide something to search for.', 2);
        }
        if (strlen($query) > 255 || strlen($contains) > 255) {
            throw new VhostFileException('Search terms are limited to 255 characters.', 2);
        }

        $base = VhostPath::resolveExisting($root, trim($rel));
        if (!is_dir($base)) {
            throw new VhostFileException('Search needs a directory to start from.', 2);
        }
        $rootReal = (string) realpath(VhostPath::normalizeRoot($root));

        $results = [];
        $scanned = 0;
        $truncated = false;
        $queue = [[$base, 0]];

        while ($queue !== []) {
            [$dir, $depth] = array_shift($queue);
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (++$scanned > self::SEARCH_MAX_ENTRIES) {
                    $truncated = true;
                    break 2;
                }
                $full = $dir . '/' . $entry;
                // Never follow a link: it can leave the root, and a link to its own parent
                // would loop until the request timed out.
                if (is_link($full)) {
                    continue;
                }
                $isDir = is_dir($full);
                $real = realpath($full);
                if ($real === false || !VhostPath::isUnder($rootReal, $real)) {
                    continue;
                }

                if (self::matches($entry, $real, $isDir, $query, $contains)) {
                    $results[] = [
                        'path' => VhostPath::relativeToRoot($rootReal, $real),
                        'type' => $isDir ? 'dir' : 'file',
                        'size' => $isDir ? null : (int) @filesize($real),
                    ];
                    if (count($results) >= self::SEARCH_MAX_RESULTS) {
                        $truncated = true;
                        break 2;
                    }
                }

                if ($isDir && $depth < self::SEARCH_MAX_DEPTH) {
                    $queue[] = [$full, $depth + 1];
                }
            }
        }

        return [
            'path' => VhostPath::relativeToRoot($rootReal, $base),
            'query' => $query,
            'contains' => $contains,
            'results' => $results,
            'count' => count($results),
            // Said out loud, because a silently capped result list reads as "not there".
            'truncated' => $truncated,
            'limit' => self::SEARCH_MAX_RESULTS,
        ];
    }

    private static function matches(string $name, string $real, bool $isDir, string $query, string $contains): bool
    {
        if ($query !== '' && stripos($name, $query) === false) {
            return false;
        }
        if ($contains === '') {
            return true;
        }
        // A content search is about files; a directory has no content to match.
        if ($isDir) {
            return false;
        }
        $size = @filesize($real);
        if ($size === false || $size > self::SEARCH_GREP_MAX_BYTES) {
            return false;
        }
        $body = @file_get_contents($real);

        return is_string($body) && stripos($body, $contains) !== false;
    }

    /**
     * Permissions, by preset (B3 / request #10).
     *
     * Presets rather than a free-text mode field, because the numbers operators reach for
     * when something does not work are 777 and 666, and both hand every other identity on
     * the host write access to this site's files — which is the separation A25 exists to
     * provide. A numeric mode is still accepted for the cases presets do not cover, but it
     * goes through the same refusals.
     *
     * Refused in every form:
     *  - **setuid, setgid and sticky bits.** Nothing the File Manager does should be able
     *    to create a setuid file, and an operator who genuinely needs one is not reaching
     *    for a web panel to do it.
     *  - **group- or world-writable.** Files here are owned by the site's own identity
     *    (A25); making them writable by others is how one site ends up able to edit
     *    another's code, and PHP-FPM will refuse to run a world-writable script anyway.
     *  - **owner without read.** A mode that locks the site out of its own file is never
     *    what was meant, and the operator then cannot fix it from here either.
     *  - **symlinks**, since chmod follows them and would change the target's mode.
     *
     * @return array<string, mixed>
     */
    private static function chmod(string $root, string $rel, string $mode): array
    {
        $target = VhostPath::resolveExisting($root, trim($rel));
        $rootReal = (string) realpath(VhostPath::normalizeRoot($root));
        $out = VhostPath::relativeToRoot($rootReal, $target);
        if ($out === '') {
            throw new VhostFileException('Refusing to change permissions on the vhost root.', 3);
        }
        // Checked at the *unresolved* path on purpose. resolveExisting() returns the
        // realpath, so by the time we have $target the link has already been followed and
        // is_link($target) is always false — the guard would be dead code that reads as if
        // it worked. chmod follows the link too, so without this the operator clicks a
        // link and changes the mode of whatever it points at.
        $raw = rtrim($rootReal, '/') . '/' . trim(trim($rel), '/');
        if (is_link($raw)) {
            throw new VhostFileException('Refusing to change permissions on a symlink; chmod follows it to the target.', 3);
        }
        $isDir = is_dir($target);
        $octal = self::resolveMode($mode, $isDir);

        if (!@chmod($target, $octal)) {
            throw new VhostFileException('Unable to change permissions.', 1);
        }

        return [
            'path' => $out,
            'mode' => sprintf('%04o', $octal),
            'type' => $isDir ? 'dir' : 'file',
            'chmod' => true,
        ];
    }

    /**
     * Presets name an intent; the numbers behind them differ for directories, because a
     * directory without its execute bit cannot be entered and 644 on a folder is the
     * classic way to make a site's whole tree unreadable.
     */
    private static function resolveMode(string $mode, bool $isDir): int
    {
        $mode = strtolower(trim($mode));
        $presets = [
            'default' => $isDir ? 0755 : 0644,
            'private' => $isDir ? 0700 : 0600,
            'executable' => $isDir ? 0755 : 0755,
        ];
        if ($mode === '') {
            throw new VhostFileException(
                'A mode is required: one of ' . implode(', ', array_keys($presets)) . ', or an octal mode.',
                2
            );
        }
        if (isset($presets[$mode])) {
            return $presets[$mode];
        }
        if (preg_match('/^0?[0-7]{3}$/', $mode) !== 1) {
            throw new VhostFileException(
                'Mode must be one of ' . implode(', ', array_keys($presets))
                . ', or three octal digits (no setuid, setgid or sticky bit).',
                2
            );
        }
        $octal = (int) octdec($mode);
        if (($octal & 0022) !== 0) {
            throw new VhostFileException(
                'Refusing a group- or world-writable mode: files here belong to this site\'s own identity, '
                . 'and making them writable by others lets another site edit this one\'s code.',
                3
            );
        }
        if (($octal & 0400) === 0) {
            throw new VhostFileException('Refusing a mode the owner cannot read: the site could not use its own file.', 3);
        }
        if ($isDir && ($octal & 0100) === 0) {
            throw new VhostFileException('Refusing a directory mode without the owner execute bit: the directory could not be entered.', 3);
        }

        return $octal;
    }

    /**
     * Copy a file within the vhost (B3 / request #10).
     *
     * Files only. A recursive directory copy can duplicate a site's whole tree and fill
     * the disk from a single click, and the containment check would have to be repeated
     * for every entry as it is created — so it waits for the compress/extract work, where
     * bounded output is the whole subject.
     *
     * The destination is checked after the copy as well as before, the same way rename
     * does: a path that resolves outside the root gets the copy removed rather than left
     * behind.
     *
     * @return array<string, mixed>
     */
    private static function copy(string $root, string $fromRel, string $toRel): array
    {
        if (trim($toRel) === '') {
            throw new VhostFileException('Destination path is required.', 2);
        }
        $src = VhostPath::resolveExisting($root, trim($fromRel));
        $rootReal = (string) realpath(VhostPath::normalizeRoot($root));
        $fromOut = VhostPath::relativeToRoot($rootReal, $src);
        if ($fromOut === '') {
            throw new VhostFileException('Refusing to copy the vhost root.', 3);
        }
        if (is_dir($src)) {
            throw new VhostFileException('Copying a directory is not supported yet; copy files individually.', 3);
        }
        if (is_link($src)) {
            throw new VhostFileException('Refusing to copy a symlink.', 3);
        }
        $destInfo = VhostPath::resolveCreate($root, $toRel);
        if (file_exists($destInfo['dest']) || is_link($destInfo['dest'])) {
            throw new VhostFileException('Destination already exists.', 3);
        }
        if (!@copy($src, $destInfo['dest'])) {
            throw new VhostFileException('Unable to copy file.', 1);
        }
        $real = realpath($destInfo['dest']);
        if ($real === false || !VhostPath::isUnder($rootReal, $real)) {
            @unlink($destInfo['dest']);
            throw new VhostFileException('Copy resolved outside the vhost directory; it was removed.', 3);
        }

        return [
            'from' => $fromOut,
            'path' => VhostPath::relativeToRoot($rootReal, $real),
            'copied' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function delete(string $root, string $rel, bool $recursive): array
    {
        $rootReal = realpath(VhostPath::normalizeRoot($root));
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new VhostFileException('Vhost root is not a directory.', 2);
        }
        $joined = VhostPath::lexicalJoin($rootReal, $rel);
        if ($joined === null) {
            throw new VhostFileException('Path is outside the vhost directory.', 3);
        }
        if ($joined === $rootReal) {
            throw new VhostFileException('Refusing to delete the vhost root.', 3);
        }
        // Unlink the directory entry itself. Following a symlink here would
        // delete the target (possibly outside the vhost, or a sibling file).
        if (is_link($joined)) {
            $relOut = VhostPath::relativeToRoot($rootReal, dirname($joined));
            $name = basename($joined);
            $relOut = $relOut === '' ? $name : $relOut . '/' . $name;
            if (!@unlink($joined)) {
                throw new VhostFileException('Unable to delete symlink.', 1);
            }

            return ['path' => $relOut, 'deleted' => true];
        }
        if (!file_exists($joined)) {
            throw new VhostFileException('Path not found.', 3);
        }
        $real = realpath($joined);
        if ($real === false || !VhostPath::isUnder($rootReal, $real)) {
            throw new VhostFileException('Path resolves outside the vhost directory.', 3);
        }
        $relOut = VhostPath::relativeToRoot($rootReal, $real);
        if (is_dir($real)) {
            if (!$recursive) {
                $left = @scandir($real);
                $count = is_array($left) ? count(array_diff($left, ['.', '..'])) : 0;
                if ($count > 0) {
                    throw new VhostFileException('Directory is not empty; pass recursive to delete it.', 2);
                }
                if (!@rmdir($real)) {
                    throw new VhostFileException('Unable to delete directory.', 1);
                }
            } else {
                self::rmTreeContained($rootReal, $real);
            }
        } elseif (!@unlink($joined)) {
            throw new VhostFileException('Unable to delete file.', 1);
        }

        return [
            'path' => $relOut,
            'deleted' => true,
        ];
    }

    private static function rmTreeContained(string $rootReal, string $dir): void
    {
        $real = realpath($dir);
        if ($real === false || !VhostPath::isUnder($rootReal, $real) || $real === $rootReal) {
            throw new VhostFileException('Path resolves outside the vhost directory.', 3);
        }
        $items = @scandir($real);
        if ($items === false) {
            throw new VhostFileException('Unable to delete directory.', 1);
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $real . '/' . $name;
            if (is_link($full) || !is_dir($full)) {
                $target = is_link($full) ? @realpath($full) : realpath($full);
                if (is_link($full) && ($target === false || !VhostPath::isUnder($rootReal, $target))) {
                    if (!@unlink($full)) {
                        throw new VhostFileException('Unable to delete symlink.', 1);
                    }
                    continue;
                }
                if (!@unlink($full)) {
                    throw new VhostFileException('Unable to delete file.', 1);
                }
            } else {
                self::rmTreeContained($rootReal, $full);
            }
        }
        if (!@rmdir($real)) {
            throw new VhostFileException('Unable to delete directory.', 1);
        }
    }
}
