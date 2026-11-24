<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\PosixRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A119/A120: root reads files a site controls (composer.json, package.json, cron logs) only as
 * bounded reads of regular files — never a link, a FIFO that would block, or a file too large.
 * Runs against the real PosixRuntime on temporary files.
 */
final class BoundedReadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/az-bounded-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function refused(callable $read, string $why): void
    {
        try {
            $read();
            $this->fail('expected refusal: ' . $why);
        } catch (BrokerException $e) {
            $this->assertStringContainsString('not read', $e->getMessage(), $why);
        }
    }

    public function test_regular_files_are_read_within_the_limit(): void
    {
        $rt = new PosixRuntime();
        file_put_contents($this->dir . '/package.json', '{"scripts":{"start":"node a.js"}}');
        $this->assertSame('{"scripts":{"start":"node a.js"}}', $rt->readFileBounded($this->dir . '/package.json', 1024));
        file_put_contents($this->dir . '/job.log', str_repeat('x', 100) . "\n2026-11-24T10:00:00Z EXIT 0\n");
        $this->assertSame("2026-11-24T10:00:00Z EXIT 0\n", $rt->readTail($this->dir . '/job.log', 28));
    }

    public function test_links_large_files_and_fifos_are_refused(): void
    {
        $rt = new PosixRuntime();
        file_put_contents($this->dir . '/big.json', str_repeat('x', 2048));
        $this->refused(fn () => $rt->readFileBounded($this->dir . '/big.json', 1024), 'over the limit');
        symlink('/etc/hosts', $this->dir . '/link.json');
        $this->refused(fn () => $rt->readFileBounded($this->dir . '/link.json', 1 << 20), 'a symlink');
        $this->refused(fn () => $rt->readTail($this->dir . '/link.json', 8192), 'a symlink, tail');
        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($this->dir . '/fifo.json', 0600);
            $start = microtime(true);
            $this->refused(fn () => $rt->readFileBounded($this->dir . '/fifo.json', 1 << 20), 'a FIFO');
            $this->assertLessThan(2.0, microtime(true) - $start, 'refused without blocking');
        }
    }
}
