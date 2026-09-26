<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use PHPUnit\Framework\TestCase;

/**
 * B3 / G12 — assembling a zip on the vhost side of the identity boundary.
 *
 * The behaviour that matters is the one panel PHP could not provide: a selected **directory**
 * ends up in the archive with its tree. Before this, the panel asked for one file's contents
 * at a time, so a folder silently contributed nothing.
 */
final class VhostFileZipTest extends TestCase
{
    private string $root;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }
        $this->root = sys_get_temp_dir() . '/az-zip-' . bin2hex(random_bytes(6));
        $this->out = sys_get_temp_dir() . '/az-zip-out-' . bin2hex(random_bytes(6)) . '.zip';
        mkdir($this->root . '/public/assets', 0755, true);
        file_put_contents($this->root . '/index.php', "<?php\n");
        file_put_contents($this->root . '/public/app.css', "body{}\n");
        file_put_contents($this->root . '/public/assets/app.js', "x\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->out);
        parent::tearDown();
    }

    /** @param list<string> $paths @return array<string,mixed> */
    private function zip(array $paths, ?string $out = null): array
    {
        return VhostFileOp::execute([
            'op' => 'zip', 'root' => $this->root, 'paths' => $paths, 'out' => $out ?? $this->out,
        ]);
    }

    /** @return list<string> */
    private function entries(): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->out) === true, 'the archive must be readable');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        return $names;
    }

    public function test_a_single_file_is_archived(): void
    {
        $result = $this->zip(['index.php']);

        $this->assertSame(1, $result['entries']);
        $this->assertSame(['index.php'], $this->entries());
    }

    /** The whole point of G12: this returned nothing at all before. */
    public function test_a_directory_brings_its_whole_tree(): void
    {
        $this->zip(['public']);

        $entries = $this->entries();
        $this->assertContains('public/app.css', $entries);
        $this->assertContains('public/assets/app.js', $entries);
        $this->assertContains('public/', $entries, 'the directory itself should be present');
    }

    public function test_several_selections_are_combined(): void
    {
        $this->zip(['index.php', 'public/app.css']);

        $this->assertSame(['index.php', 'public/app.css'], $this->entries());
    }

    public function test_file_contents_survive(): void
    {
        $this->zip(['index.php']);

        $zip = new \ZipArchive();
        $zip->open($this->out);
        $this->assertSame("<?php\n", $zip->getFromName('index.php'));
        $zip->close();
    }

    // --------------------------------------------------------------- refusals

    public function test_a_path_outside_the_root_is_refused(): void
    {
        $this->expectException(VhostFileException::class);
        $this->zip(['../../etc/hosts']);
    }

    /** An empty selection entry is skipped, so the run ends with nothing to archive. */
    public function test_the_vhost_root_itself_is_never_archived(): void
    {
        try {
            $this->zip(['']);
            $this->fail('expected a refusal');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('could be archived', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->out);
    }

    /** An archive written inside the site it just read is a file the site can then alter. */
    public function test_writing_the_archive_inside_the_vhost_is_refused(): void
    {
        try {
            $this->zip(['index.php'], $this->root . '/leak.zip');
            $this->fail('expected a refusal');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('inside the vhost', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->root . '/leak.zip');
    }

    public function test_a_relative_output_path_is_refused(): void
    {
        $this->expectException(VhostFileException::class);
        $this->zip(['index.php'], 'out.zip');
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException(VhostFileException::class);
        $this->zip([]);
    }

    public function test_symlinks_are_skipped_not_followed(): void
    {
        $outside = sys_get_temp_dir() . '/az-zip-outside-' . bin2hex(random_bytes(4));
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'x');
        symlink($outside, $this->root . '/public/escape');

        try {
            $this->zip(['public']);
            foreach ($this->entries() as $entry) {
                $this->assertStringNotContainsString('secret', $entry);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    /** A failure must not leave a half-written archive for the panel to stream. */
    public function test_a_refusal_leaves_no_archive_behind(): void
    {
        try {
            $this->zip(['index.php', '../../etc/hosts']);
        } catch (VhostFileException) {
            // expected
        }
        $this->assertFileDoesNotExist($this->out);
    }

    public function test_a_selection_that_yields_nothing_is_an_error_not_an_empty_zip(): void
    {
        symlink('/etc/hosts', $this->root . '/link-only');

        try {
            $this->zip(['link-only']);
            $this->fail('expected a refusal');
        } catch (VhostFileException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->out);
    }

    public function test_zip_is_a_recognised_operation(): void
    {
        $this->assertContains('zip', VhostFileOp::OPS);
    }
}
