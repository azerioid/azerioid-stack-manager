<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\Runtime;

final class PackageQuery
{
    public static function isInstalled(Runtime $runtime, string $package, string $pkgMgr): bool
    {
        $package = trim($package);
        if ($package === '') {
            return false;
        }

        if ($pkgMgr === 'apt') {
            $result = $runtime->exec([
                '/usr/bin/dpkg-query',
                '-W',
                '-f=${Status}',
                $package,
            ]);
            return $result->ok() && str_contains($result->stdout, 'install ok installed');
        }

        $result = $runtime->exec(['/usr/bin/rpm', '-q', $package]);
        return $result->ok();
    }

    /** @param list<string> $packages */
    public static function anyInstalled(Runtime $runtime, array $packages, string $pkgMgr): bool
    {
        foreach ($packages as $package) {
            if (self::isInstalled($runtime, $package, $pkgMgr)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $packages */
    public static function allInstalled(Runtime $runtime, array $packages, string $pkgMgr): bool
    {
        if ($packages === []) {
            return false;
        }
        foreach ($packages as $package) {
            if (!self::isInstalled($runtime, $package, $pkgMgr)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when any installed package name matches $regex (full-match).
     * Used for versioned stacks (e.g. Debian postgresql-17) where the metapackage may be gone.
     */
    public static function anyNameMatching(Runtime $runtime, string $regex, string $pkgMgr): bool
    {
        return self::listInstalledMatching($runtime, $regex, $pkgMgr) !== [];
    }

    /**
     * @return list<string>
     */
    public static function listInstalledMatching(Runtime $runtime, string $regex, string $pkgMgr): array
    {
        $regex = trim($regex);
        if ($regex === '') {
            return [];
        }
        // Anchor callers may pass ^...$; accept either.
        $pattern = $regex;
        if ($pattern[0] !== '/') {
            $pattern = '/' . $pattern . '/';
        }

        $names = [];
        if ($pkgMgr === 'apt') {
            $result = $runtime->exec(['/usr/bin/dpkg-query', '-W', '-f=${Package} ${Status}\n']);
            if (!$result->ok()) {
                return [];
            }
            foreach (preg_split('/\r\n|\r|\n/', $result->stdout) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, 'install ok installed')) {
                    continue;
                }
                $name = trim(explode(' ', $line, 2)[0]);
                if ($name !== '' && preg_match($pattern, $name) === 1) {
                    $names[] = $name;
                }
            }
        } else {
            $result = $runtime->exec(['/usr/bin/rpm', '-qa', '--qf', '%{NAME}\n']);
            if (!$result->ok()) {
                return [];
            }
            foreach (preg_split('/\r\n|\r|\n/', $result->stdout) ?: [] as $name) {
                $name = trim($name);
                if ($name !== '' && preg_match($pattern, $name) === 1) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
