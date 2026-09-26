<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Backup\LocalArchiveSink;
use AzerioidPanel\Broker\Backup\SpacesArchiveSink;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\SpacesClient;
use PHPUnit\Framework\TestCase;

/**
 * A2.3 — the streaming destinations. The multipart path in particular has
 * failure modes that cost money if unhandled (abandoned parts keep billing), so
 * abort behaviour is covered explicitly.
 */
final class ArchiveSinkTest extends TestCase
{
    private MemorySpacesTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MemorySpacesTransport();
        SpacesClient::$http = $this->transport->handler();
    }

    protected function tearDown(): void
    {
        SpacesClient::$http = null;
    }

    private function client(): SpacesClient
    {
        return SpacesClient::fromInput([
            'endpoint' => 'https://fra1.digitaloceanspaces.com',
            'region' => 'fra1',
            'bucket' => 'azerioid-backups',
            'access_key' => 'key',
            'secret' => 'supersecretkeyvalue',
        ]);
    }

    // ------------------------------------------------------------------- local

    public function test_local_sink_streams_through_the_runtime(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/var/lib/azerioid-panel/backups'] = true;
        $sink = new LocalArchiveSink($rt, '/var/lib/azerioid-panel/backups/a.bin');

        $sink->write('abc');
        $sink->write('def');
        $out = $sink->finish();

        $this->assertSame('abcdef', $rt->files['/var/lib/azerioid-panel/backups/a.bin']);
        $this->assertSame(6, $out['size']);
        $this->assertSame(1, $out['parts']);
    }

    public function test_local_sink_abort_removes_the_partial_file(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/var/lib/azerioid-panel/backups'] = true;
        $sink = new LocalArchiveSink($rt, '/var/lib/azerioid-panel/backups/a.bin');
        $sink->write('partial');
        $sink->abort();

        $this->assertArrayNotHasKey('/var/lib/azerioid-panel/backups/a.bin', $rt->files);
    }

    public function test_local_sink_refuses_writes_after_finish(): void
    {
        $rt = new FakeRuntime();
        $rt->dirs['/var/lib/azerioid-panel/backups'] = true;
        $sink = new LocalArchiveSink($rt, '/var/lib/azerioid-panel/backups/a.bin');
        $sink->finish();

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/already closed/');
        $sink->write('x');
    }

    // ---------------------------------------------------------------- multipart

    public function test_multipart_splits_into_parts_of_the_configured_size(): void
    {
        $part = SpacesClient::MIN_PART_BYTES;
        $sink = new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin', $part);

        // 2.5 parts worth of data.
        $sink->write(str_repeat('a', $part));
        $sink->write(str_repeat('b', $part));
        $sink->write(str_repeat('c', (int) ($part / 2)));
        $out = $sink->finish();

        $this->assertSame(3, $out['parts'], 'two full parts plus the remainder');
        $this->assertSame((int) ($part * 2.5), $out['size']);
        $this->assertSame(1, $this->transport->multipartCompleted);
    }

    public function test_reassembled_object_matches_what_was_written(): void
    {
        $part = SpacesClient::MIN_PART_BYTES;
        $sink = new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin', $part);
        $payload = random_bytes((int) ($part * 2.25));
        // Deliberately unaligned writes, as the cipher produces.
        foreach (str_split($payload, 999_983) as $piece) {
            $sink->write($piece);
        }
        $sink->finish();

        $this->assertSame(
            $payload,
            $this->transport->objects['/azerioid-backups/azerioid/db/all/x.bin'],
            'parts must reassemble in order with no gaps or duplication'
        );
    }

    public function test_small_archive_uses_a_single_part(): void
    {
        $sink = new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin');
        $sink->write('tiny');
        $out = $sink->finish();

        $this->assertSame(1, $out['parts']);
        $this->assertSame('tiny', $this->transport->objects['/azerioid-backups/azerioid/db/all/x.bin']);
    }

    /** S3 refuses a completion with zero parts, so one must always be sent. */
    public function test_zero_byte_archive_still_sends_one_part(): void
    {
        $sink = new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin');
        $out = $sink->finish();

        $this->assertSame(1, $out['parts']);
        $this->assertSame(1, $this->transport->multipartCompleted);
    }

    public function test_abort_abandons_the_upload_so_parts_stop_being_billed(): void
    {
        $sink = new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin', SpacesClient::MIN_PART_BYTES);
        $sink->write(str_repeat('a', SpacesClient::MIN_PART_BYTES));
        $this->assertNotSame([], $this->transport->multipart, 'an upload is in flight');

        $sink->abort();

        $this->assertSame(1, $this->transport->multipartAborted);
        $this->assertSame([], $this->transport->multipart);
        $this->assertArrayNotHasKey('/azerioid-backups/azerioid/db/all/x.bin', $this->transport->objects);
    }

    public function test_abort_is_idempotent(): void
    {
        $sink = new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin');
        $sink->abort();
        $sink->abort();

        $this->assertSame(1, $this->transport->multipartAborted);
    }

    public function test_refuses_a_part_size_below_the_s3_minimum(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/at least/');
        new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin', 1024);
    }

    public function test_refuses_writes_after_finish(): void
    {
        $sink = new SpacesArchiveSink($this->client(), 'azerioid/db/all/x.bin');
        $sink->write('x');
        $sink->finish();

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/already closed/');
        $sink->write('more');
    }

    public function test_complete_surfaces_an_error_document_returned_with_http_200(): void
    {
        $client = $this->client();
        $uploadId = $client->createMultipart('azerioid/db/all/x.bin');
        $etag = $client->uploadPart('azerioid/db/all/x.bin', $uploadId, 1, 'body');

        // S3 can answer 200 with <Error>; that must not read as success.
        SpacesClient::$http = static fn (): array => [
            'status' => 200,
            'body' => '<Error><Code>InternalError</Code></Error>',
            'headers' => [],
        ];

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/InternalError/');
        $client->completeMultipart('azerioid/db/all/x.bin', $uploadId, [$etag]);
    }
}
