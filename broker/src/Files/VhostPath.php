<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Files;

/**
 * Containment for vhost file-manager paths. String-join of `..` is not enough:
 * a symlink whose name sits inside the root can resolve outside. Callers must
 * use {@see resolveExisting()} / {@see resolveParent()} which canonicalise
 * with realpath() after the lexical join.
 */
final class VhostPath
{
    public const MAX_REL_BYTES = 4096;
    public const MAX_NAME_BYTES = 255;

    /**
     * Lexical join of $root + $rel that rejects NUL, absolute rel paths, and
     * `..` segments that climb above $root. Does not follow symlinks.
     *
     * @return string absolute path (not yet realpath'd) or null if it would escape
     */
    public static function lexicalJoin(string $root, string $rel): ?string
    {
        $root = self::normalizeRoot($root);
        if ($root === '' || str_contains($root, "\0") || str_contains($rel, "\0")) {
            return null;
        }
        $rel = str_replace('\\', '/', $rel);
        $rel = trim($rel);
        if (strlen($rel) > self::MAX_REL_BYTES) {
            return null;
        }
        if ($rel !== '' && ($rel[0] === '/' || preg_match('#^[a-zA-Z]:/#', $rel) === 1)) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            if (strlen($seg) > self::MAX_NAME_BYTES || str_contains($seg, "\0")) {
                return null;
            }
            $parts[] = $seg;
        }

        return $parts === [] ? $root : $root . '/' . implode('/', $parts);
    }

    public static function isUnder(string $rootReal, string $candidateReal): bool
    {
        $rootReal = rtrim($rootReal, '/');
        $candidateReal = rtrim($candidateReal, '/');
        if ($candidateReal === $rootReal) {
            return true;
        }

        return str_starts_with($candidateReal, $rootReal . '/');
    }

    /**
     * Resolve an existing path (file, dir, or in-root symlink) to a real path
     * still inside $root. Rejects dangling or outside-resolving symlinks.
     */
    public static function resolveExisting(string $root, string $rel): string
    {
        $rootReal = realpath(self::normalizeRoot($root));
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new VhostFileException('Vhost root is not a directory.', 2);
        }
        $joined = self::lexicalJoin($rootReal, $rel);
        if ($joined === null) {
            throw new VhostFileException('Path is outside the vhost directory.', 3);
        }
        if (!file_exists($joined) && !is_link($joined)) {
            throw new VhostFileException('Path not found.', 3);
        }
        $real = realpath($joined);
        if ($real === false) {
            throw new VhostFileException('Path is a symlink that does not resolve inside the vhost directory.', 3);
        }
        if (!self::isUnder($rootReal, $real)) {
            throw new VhostFileException('Path resolves outside the vhost directory.', 3);
        }

        return $real;
    }

    /**
     * Resolve the parent of a path that may not exist yet (write/mkdir/rename dest).
     * The parent must exist and resolve inside the root. The final basename is
     * appended without following a dangling dest symlink that points outside.
     *
     * @return array{root:string, parent:string, dest:string, name:string}
     */
    public static function resolveCreate(string $root, string $rel): array
    {
        $rootReal = realpath(self::normalizeRoot($root));
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new VhostFileException('Vhost root is not a directory.', 2);
        }
        $joined = self::lexicalJoin($rootReal, $rel);
        if ($joined === null) {
            throw new VhostFileException('Path is outside the vhost directory.', 3);
        }
        if ($joined === $rootReal) {
            throw new VhostFileException('Refusing to replace the vhost root.', 3);
        }
        $name = basename($joined);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new VhostFileException('Invalid file name.', 2);
        }
        $parentJoined = dirname($joined);
        $parentReal = realpath($parentJoined);
        if ($parentReal === false || !is_dir($parentReal)) {
            throw new VhostFileException('Parent directory not found.', 3);
        }
        if (!self::isUnder($rootReal, $parentReal)) {
            throw new VhostFileException('Parent directory resolves outside the vhost directory.', 3);
        }
        $dest = $parentReal . '/' . $name;
        if (is_link($dest)) {
            $linkReal = realpath($dest);
            if ($linkReal === false || !self::isUnder($rootReal, $linkReal)) {
                throw new VhostFileException('Refusing to write through a symlink that leaves the vhost directory.', 3);
            }
            $dest = $linkReal;
        } elseif (file_exists($dest)) {
            $existReal = realpath($dest);
            if ($existReal === false || !self::isUnder($rootReal, $existReal)) {
                throw new VhostFileException('Path resolves outside the vhost directory.', 3);
            }
            $dest = $existReal;
        }

        return [
            'root' => $rootReal,
            'parent' => $parentReal,
            'dest' => $dest,
            'name' => $name,
        ];
    }

    public static function relativeToRoot(string $rootReal, string $absReal): string
    {
        $rootReal = rtrim($rootReal, '/');
        $absReal = rtrim($absReal, '/');
        if ($absReal === $rootReal) {
            return '';
        }
        if (!self::isUnder($rootReal, $absReal)) {
            throw new VhostFileException('Path resolves outside the vhost directory.', 3);
        }

        return substr($absReal, strlen($rootReal) + 1);
    }

    public static function normalizeRoot(string $root): string
    {
        $root = str_replace('\\', '/', trim($root));
        $root = rtrim($root, '/');
        if ($root === '' || $root[0] !== '/') {
            throw new VhostFileException('Vhost root must be an absolute path.', 2);
        }

        return $root;
    }
}
