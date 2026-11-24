<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Backup\ArchiveSink;
use AzerioidPanel\Broker\Backup\BackupPipeline;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\PosixRuntime;
use PHPUnit\Framework\TestCase;

/** A121: a failed or overdue backup stream stops its source instead of draining it. */
final class BackupPipelineAbortTest extends TestCase
{
    public function test_a_sink_failure_aborts_the_source_and_never_finishes_it(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/tar', '-cf', '-', 'site'], 0, str_repeat('x', 4096));
        $sink = new class implements ArchiveSink {
            public bool $aborted = false;

            public function write(string $chunk): void
            {
                throw new \RuntimeException('disk full');
            }

            public function finish(): array
            {
                return ['size' => 0, 'etag' => null, 'parts' => 0];
            }

            public function abort(): void
            {
                $this->aborted = true;
            }
        };

        try {
            (new BackupPipeline($rt))->run(['/usr/bin/tar', '-cf', '-', 'site'], $sink, 'test-passphrase-123');
            $this->fail('expected the sink failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }
        $this->assertSame(['abort'], $rt->readerCalls);
        $this->assertTrue($sink->aborted);
    }

    public function test_the_deadline_stops_a_silent_child(): void
    {
        $reader = (new PosixRuntime())->execReader(['/bin/sh', '-c', 'echo $$; exec sleep 30'], null, 1);
        $pid = (int) trim(($reader['read'])(64));
        $this->assertGreaterThan(0, $pid);
        $start = microtime(true);
        try {
            while (true) {
                ($reader['read'])(64);
            }
        } catch (BrokerException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
        $this->assertLessThan(3.0, microtime(true) - $start, 'the read gave up at the deadline');
        usleep(100000);
        $this->assertFalse(posix_kill($pid, 0), 'the child was killed');
    }
}
