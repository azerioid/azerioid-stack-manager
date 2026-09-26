<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

/** Streams to a file through the Runtime, so FakeRuntime can drive it in tests. */
final class LocalArchiveSink implements ArchiveSink
{
    private int $size = 0;

    /** @var (callable(string):void)|null */
    private $writer;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly string $path,
        int $mode = 0600,
    ) {
        $this->writer = $runtime->appendWriter($path, $mode);
    }

    public function write(string $chunk): void
    {
        if ($this->writer === null) {
            throw new BrokerException('Archive sink is already closed.', 1);
        }
        if ($chunk === '') {
            return;
        }
        ($this->writer)($chunk);
        $this->size += strlen($chunk);
    }

    public function finish(): array
    {
        if ($this->writer !== null) {
            ($this->writer)('');
            $this->writer = null;
        }

        return ['size' => $this->size, 'etag' => null, 'parts' => 1];
    }

    public function abort(): void
    {
        if ($this->writer !== null) {
            ($this->writer)('');
            $this->writer = null;
        }
        if ($this->runtime->fileExists($this->path)) {
            try {
                $this->runtime->deleteFile($this->path);
            } catch (BrokerException) {
                // best effort on a failure path
            }
        }
    }
}
