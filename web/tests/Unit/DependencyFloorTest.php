<?php

namespace Tests\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * A89: versions the lock file must not fall below, each for a published advisory that is
 * reachable in this application. A floor here fails the suite when a lock-file change
 * (a merge, a careless `composer update --prefer-lowest`) brings the affected release back.
 */
final class DependencyFloorTest extends TestCase
{
    /** @return array<string, array{0:string, 1:string}> */
    public static function floors(): array
    {
        return [
            // CVE-2026-108082: HeaderUtils::split() backtracks on a client-supplied
            // Cache-Control header before any middleware runs.
            'symfony/http-foundation' => ['symfony/http-foundation', '8.1.7'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('floors')]
    public function test_installed_version_is_at_or_above_the_floor(string $package, string $floor): void
    {
        $installed = ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');

        $this->assertTrue(
            version_compare($installed, $floor, '>='),
            "{$package} {$installed} is below the security floor {$floor}"
        );
    }
}
