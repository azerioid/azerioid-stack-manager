<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use PHPUnit\Framework\TestCase;

/**
 * Extraction itself (A40). ZipExtractGuardTest covers which archives are refused; this covers what
 * happens on disk when one is accepted — and what is left behind when extraction stops half way.
 */
final class VhostFileExtractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }
        $this->root = sys_get_temp_dir() . '/az-extract-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/uploads', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @param array<string,string> $entries */
    private function archiveAt(string $rel, array $entries): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->root . '/' . $rel, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /** @return array<string,mixed> */
    private function extract(string $rel, string $dest = ''): array
    {
        return VhostFileOp::execute([
            'op' => 'extract', 'root' => $this->root, 'path' => $rel, 'dest' => $dest,
        ]);
    }

    public function test_an_archive_extracts_beside_itself_by_default(): void
    {
        $this->archiveAt('uploads/site.zip', ['index.php' => "<?php\n", 'css/app.css' => "body{}\n"]);

        $result = $this->extract('uploads/site.zip');

        $this->assertTrue($result['extracted']);
        $this->assertSame('uploads', $result['destination']);
        $this->assertFileExists($this->root . '/uploads/index.php');
        $this->assertFileExists($this->root . '/uploads/css/app.css');
        $this->assertSame("<?php\n", file_get_contents($this->root . '/uploads/index.php'));
    }

    public function test_a_destination_can_be_chosen(): void
    {
        mkdir($this->root . '/public', 0755);
        $this->archiveAt('uploads/site.zip', ['a.txt' => 'a']);

        $this->extract('uploads/site.zip', 'public');

        $this->assertFileExists($this->root . '/public/a.txt');
    }

    /** Modes come from the panel, not from attacker-controlled archive metadata. */
    public function test_extracted_files_get_panel_chosen_modes(): void
    {
        $this->archiveAt('uploads/site.zip', ['a.txt' => 'a', 'sub/b.txt' => 'b']);

        $this->extract('uploads/site.zip');

        clearstatcache();
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->root . '/uploads/a.txt')), -4));
        $this->assertSame('0755', substr(sprintf('%o', fileperms($this->root . '/uploads/sub')), -4));
    }

    /** An archive quietly replacing a site's own code is the accident to prevent. */
    public function test_an_existing_file_is_never_overwritten(): void
    {
        file_put_contents($this->root . '/uploads/index.php', "ORIGINAL\n");
        $this->archiveAt('uploads/site.zip', ['index.php' => "REPLACED\n"]);

        try {
            $this->extract('uploads/site.zip');
            $this->fail('expected a refusal');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('Refusing to overwrite', $e->getMessage());
        }
        $this->assertSame("ORIGINAL\n", file_get_contents($this->root . '/uploads/index.php'));
    }

    /**
     * A partial tree is harder to reason about than none: the operator cannot tell which files
     * came from the archive.
     */
    public function test_a_failure_part_way_leaves_nothing_behind(): void
    {
        file_put_contents($this->root . '/uploads/second.txt', "ORIGINAL\n");
        // first.txt extracts, then second.txt collides and stops the run.
        $this->archiveAt('uploads/site.zip', ['first.txt' => 'one', 'second.txt' => 'two']);

        try {
            $this->extract('uploads/site.zip');
            $this->fail('expected a refusal');
        } catch (VhostFileException) {
        }

        $this->assertFileDoesNotExist($this->root . '/uploads/first.txt', 'the earlier entry must be rolled back');
        $this->assertSame("ORIGINAL\n", file_get_contents($this->root . '/uploads/second.txt'));
    }

    public function test_a_hostile_archive_is_refused_and_writes_nothing(): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->root . '/uploads/evil.zip', \ZipArchive::CREATE);
        $zip->addFromString('../../escaped.php', 'x');
        $zip->close();

        try {
            $this->extract('uploads/evil.zip');
            $this->fail('expected a refusal');
        } catch (VhostFileException) {
        }

        $this->assertFileDoesNotExist(dirname($this->root) . '/escaped.php');
    }

    public function test_extracting_a_directory_is_refused(): void
    {
        $this->expectException(VhostFileException::class);
        $this->extract('uploads');
    }

    public function test_a_destination_outside_the_root_is_refused(): void
    {
        $this->archiveAt('uploads/site.zip', ['a.txt' => 'a']);

        $this->expectException(VhostFileException::class);
        $this->extract('uploads/site.zip', '../../tmp');
    }

    public function test_a_missing_destination_directory_is_refused(): void
    {
        $this->archiveAt('uploads/site.zip', ['a.txt' => 'a']);

        $this->expectException(VhostFileException::class);
        $this->extract('uploads/site.zip', 'not-there');
    }

    public function test_extracting_through_a_symlinked_archive_is_refused(): void
    {
        $this->archiveAt('uploads/site.zip', ['a.txt' => 'a']);
        symlink($this->root . '/uploads/site.zip', $this->root . '/uploads/alias.zip');

        $this->expectException(VhostFileException::class);
        $this->extract('uploads/alias.zip');
    }

    public function test_extract_is_a_recognised_operation(): void
    {
        $this->assertContains('extract', VhostFileOp::OPS);
    }
}
