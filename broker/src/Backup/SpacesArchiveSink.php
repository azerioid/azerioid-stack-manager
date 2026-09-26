<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Backup;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\SpacesClient;

/**
 * Streams to object storage via S3 multipart upload.
 *
 * Buffers until at least MIN_PART_BYTES is available, because S3 rejects any part
 * but the last below that size. On failure the upload is aborted so the parts
 * already stored stop being billed.
 */
final class SpacesArchiveSink implements ArchiveSink
{
    private string $buffer = '';

    private int $size = 0;

    /** @var list<string> */
    private array $etags = [];

    private ?string $uploadId = null;

    private bool $finished = false;

    public function __construct(
        private readonly SpacesClient $client,
        private readonly string $key,
        private readonly int $partBytes = 8 * 1024 * 1024,
    ) {
        if ($partBytes < SpacesClient::MIN_PART_BYTES) {
            throw new BrokerException(
                'Multipart part size must be at least ' . SpacesClient::MIN_PART_BYTES . ' bytes.',
                2
            );
        }
        $this->uploadId = $client->createMultipart($key);
    }

    public function write(string $chunk): void
    {
        if ($this->finished || $this->uploadId === null) {
            throw new BrokerException('Archive sink is already closed.', 1);
        }
        $this->buffer .= $chunk;
        while (strlen($this->buffer) >= $this->partBytes) {
            $this->flushPart(substr($this->buffer, 0, $this->partBytes));
            $this->buffer = substr($this->buffer, $this->partBytes);
        }
    }

    public function finish(): array
    {
        if ($this->uploadId === null) {
            throw new BrokerException('Archive sink is already closed.', 1);
        }
        // The final part may be under the minimum; an empty archive still needs
        // one part, because S3 refuses a completion with none.
        if ($this->buffer !== '' || $this->etags === []) {
            $this->flushPart($this->buffer);
            $this->buffer = '';
        }
        $result = $this->client->completeMultipart($this->key, $this->uploadId, $this->etags);
        $this->uploadId = null;
        $this->finished = true;

        return ['size' => $this->size, 'etag' => $result['etag'], 'parts' => $result['parts']];
    }

    public function abort(): void
    {
        if ($this->uploadId === null) {
            return;
        }
        $this->client->abortMultipart($this->key, $this->uploadId);
        $this->uploadId = null;
        $this->buffer = '';
    }

    private function flushPart(string $body): void
    {
        $this->etags[] = $this->client->uploadPart(
            $this->key,
            (string) $this->uploadId,
            count($this->etags) + 1,
            $body
        );
        $this->size += strlen($body);
    }
}
