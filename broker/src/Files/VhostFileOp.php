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
    public const OPS = ['list', 'read', 'write', 'mkdir', 'rename', 'move', 'delete'];

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
