<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Panel\Semver;
use PHPUnit\Framework\TestCase;

final class SemverTest extends TestCase
{
    public function test_normalizes_and_rejects_invalid(): void
    {
        $this->assertSame('v0.2.1', Semver::normalize('0.2.1'));
        $this->assertSame('v0.2.1', Semver::normalize('v0.2.1'));
        $this->assertNull(Semver::normalize('v0.2'));
        $this->assertNull(Semver::normalize('latest'));
        $this->assertNull(Semver::normalize('v0.2.1-beta'));
    }

    public function test_sort_is_real_semver_not_lexical(): void
    {
        $sorted = Semver::sortDescending([
            'v0.2.9',
            'v0.2.10',
            'v0.9.0',
            'v0.10.0',
            'v0.2.2',
            'not-a-tag',
            'v0.2.1',
        ]);

        $this->assertSame(
            ['v0.10.0', 'v0.9.0', 'v0.2.10', 'v0.2.9', 'v0.2.2', 'v0.2.1'],
            $sorted
        );
        $this->assertSame('v0.10.0', Semver::latest($sorted));
        $this->assertGreaterThan(0, Semver::compare('v0.2.10', 'v0.2.9'));
        $this->assertGreaterThan(0, Semver::compare('v0.10.0', 'v0.9.0'));
    }

    public function test_suggest_close_match(): void
    {
        $this->assertSame('v0.2.1', Semver::suggest('v0.2.11', ['v0.2.1', 'v0.3.0']));
    }
}
