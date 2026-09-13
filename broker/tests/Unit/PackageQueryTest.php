<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Component\PackageQuery;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

final class PackageQueryTest extends TestCase
{
    public function test_list_installed_matching_apt_versioned_postgresql(): void
    {
        $rt = new FakeRuntime();
        $rt->script(
            ['/usr/bin/dpkg-query', '-W', '-f=${Package} ${Status}\n'],
            0,
            "postgresql-17 install ok installed\n"
            . "postgresql-client-17 install ok installed\n"
            . "libpq5 install ok installed\n"
            . "caddy install ok installed\n"
        );

        $matched = PackageQuery::listInstalledMatching($rt, '/^postgresql/', 'apt');
        $this->assertSame(['postgresql-17', 'postgresql-client-17'], $matched);
        $this->assertTrue(PackageQuery::anyNameMatching($rt, '^postgresql-[0-9]+$', 'apt'));
        $this->assertFalse(PackageQuery::anyNameMatching($rt, '^mariadb-', 'apt'));
    }
}
