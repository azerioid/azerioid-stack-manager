<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\ZipExtractGuard;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial suite for zip extraction (A40).
 *
 * Extract was excluded from the File Manager on purpose, and reopening it was made conditional on
 * this suite existing. So these tests are the licence for the feature, not documentation of it:
 * every archive below is built byte by byte to do something specific, and the assertion is that it
 * is refused *and* that nothing was written.
 *
 * The threat model is not "escape to the host". An extract already runs as the site's identity
 * inside the site's own root, so writing outside the chosen directory — or over the site's own
 * code — is the whole attack.
 */
final class ZipExtractGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }
        $this->dir = sys_get_temp_dir() . '/az-zipguard-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /**
     * @param  array<string,string>  $entries  name => content
     * @param  array<string,int>  $modes  name => unix mode, set as external attributes
     */
    private function archive(array $entries, array $modes = []): string
    {
        $path = $this->dir . '/a-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        foreach ($modes as $name => $mode) {
            $index = $zip->locateName($name);
            if ($index !== false) {
                $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, $mode << 16);
            }
        }
        $zip->close();

        return $path;
    }

    private function assertRefused(string $path, string $why): void
    {
        try {
            ZipExtractGuard::inspect($path);
            $this->fail('expected a refusal: ' . $why);
        } catch (VhostFileException $e) {
            $this->assertNotSame('', $e->getMessage(), $why);
        }
    }

    // ------------------------------------------------------------- the happy path

    public function test_an_ordinary_archive_is_accepted(): void
    {
        $result = ZipExtractGuard::inspect($this->archive([
            'index.php' => "<?php\n",
            'public/app.css' => "body{}\n",
        ]));

        $this->assertSame(2, $result['entries']);
        $this->assertContains('index.php', $result['names']);
    }

    public function test_a_directory_entry_is_accepted(): void
    {
        $result = ZipExtractGuard::inspect($this->archive(['public/' => '', 'public/a.txt' => 'x']));

        $this->assertSame(2, $result['entries']);
    }

    // ------------------------------------------------------------------ traversal

    public function test_a_climbing_entry_is_refused(): void
    {
        $this->assertRefused($this->archive(['../escaped.php' => 'x']), 'parent traversal');
    }

    public function test_a_deeply_climbing_entry_is_refused(): void
    {
        $this->assertRefused($this->archive(['a/b/../../../../etc/cron.d/x' => 'x']), 'deep traversal');
    }

    public function test_an_absolute_unix_path_is_refused(): void
    {
        $this->assertRefused($this->archive(['/etc/passwd' => 'x']), 'absolute path');
    }

    public function test_an_absolute_windows_path_is_refused(): void
    {
        $this->assertRefused($this->archive(['C:/windows/system32/x' => 'x']), 'drive-qualified path');
    }

    /** A backslash archive would extract as one long name and defeat component checks. */
    public function test_backslash_separators_are_refused(): void
    {
        $this->assertRefused($this->archive(['a\\..\\..\\escaped' => 'x']), 'backslash separators');
    }

    /**
     * libzip converts a control byte in a stored name to a printable character (0x01 becomes
     * U+263A), so by the time the guard sees the name the hostile byte is already gone. The check
     * in the guard stays as defence for a name that arrives another way, but the honest assertion
     * here is what actually happens: the name is harmless and the entry is accepted.
     */
    public function test_a_control_byte_in_a_name_is_neutralised_by_the_zip_library(): void
    {
        $result = ZipExtractGuard::inspect($this->archive(["ev\x01il.php" => 'x']));

        $this->assertSame(1, $result['entries']);
        $this->assertStringNotContainsString("\x01", $result['names'][0]);
    }

    public function test_an_over_long_name_is_refused(): void
    {
        $this->assertRefused($this->archive([str_repeat('a', 600) . '.php' => 'x']), 'over-long name');
    }

    // ---------------------------------------------------------------- entry types

    /**
     * The one that matters most. A link extracted into the root points wherever the archive says,
     * and the next write through that name lands outside the site.
     */
    public function test_a_symlink_entry_is_refused(): void
    {
        $this->assertRefused(
            $this->archive(['link' => '/etc/passwd'], ['link' => 0120777]),
            'symlink entry'
        );
    }

    public function test_a_symlink_pointing_inside_the_archive_is_still_refused(): void
    {
        // A restore of the panel's own backup allows this (A2.1, for `artisan storage:link`).
        // An uploaded archive does not get that concession: it did not come from us.
        $this->assertRefused(
            $this->archive(['public/storage' => '../storage/app/public'], ['public/storage' => 0120777]),
            'in-archive symlink from an uploaded file'
        );
    }

    public function test_a_setuid_entry_is_refused(): void
    {
        $this->assertRefused($this->archive(['shell' => 'x'], ['shell' => 0104755]), 'setuid bit');
    }

    public function test_a_setgid_entry_is_refused(): void
    {
        $this->assertRefused($this->archive(['shell' => 'x'], ['shell' => 0102755]), 'setgid bit');
    }

    public function test_a_sticky_entry_is_refused(): void
    {
        $this->assertRefused($this->archive(['dir' => 'x'], ['dir' => 0101755]), 'sticky bit');
    }

    public function test_a_device_node_entry_is_refused(): void
    {
        $this->assertRefused($this->archive(['dev' => ''], ['dev' => 0020666]), 'character device');
    }

    public function test_a_fifo_entry_is_refused(): void
    {
        $this->assertRefused($this->archive(['pipe' => ''], ['pipe' => 0010644]), 'FIFO');
    }

    /** An archive from a tool that records no Unix mode must still be usable. */
    public function test_an_entry_with_no_recorded_mode_is_accepted(): void
    {
        $result = ZipExtractGuard::inspect($this->archive(['plain.txt' => 'x']));

        $this->assertSame(1, $result['entries']);
    }

    // --------------------------------------------------------------------- bombs

    public function test_an_absurd_compression_ratio_is_refused(): void
    {
        // 1 MiB of zeroes compresses far beyond the ratio ceiling.
        $this->assertRefused($this->archive(['bomb' => str_repeat("\0", 1048576)]), 'compression bomb');
    }

    /** A small file compressing well is normal and must not be mistaken for a bomb. */
    public function test_a_small_highly_compressible_file_is_accepted(): void
    {
        $result = ZipExtractGuard::inspect($this->archive(['small' => str_repeat('a', 4096)]));

        $this->assertSame(1, $result['entries']);
    }

    public function test_too_many_entries_is_refused(): void
    {
        $entries = [];
        for ($i = 0; $i <= ZipExtractGuard::MAX_ENTRIES; $i++) {
            $entries['f' . $i] = 'x';
        }

        $this->assertRefused($this->archive($entries), 'entry count');
    }

    // ------------------------------------------------------------- malformed input

    public function test_a_file_that_is_not_a_zip_is_refused(): void
    {
        $path = $this->dir . '/not.zip';
        file_put_contents($path, "just text\n");

        $this->assertRefused($path, 'not an archive');
    }

    public function test_a_truncated_archive_is_refused(): void
    {
        $good = $this->archive(['index.php' => str_repeat('x', 4096)]);
        $bytes = (string) file_get_contents($good);
        // Half the file, not a fixed 200 bytes: a one-entry archive of repeated characters
        // compresses to well under that, so a fixed cut copied the whole thing and the test
        // passed while truncating nothing.
        $truncated = $this->dir . '/truncated.zip';
        file_put_contents($truncated, substr($bytes, 0, intdiv(strlen($bytes), 2)));
        $this->assertLessThan(strlen($bytes), (int) filesize($truncated), 'the fixture must be shorter');

        $this->assertRefused($truncated, 'truncated archive');
    }

    public function test_an_empty_archive_is_refused(): void
    {
        $this->assertRefused($this->archive([]), 'empty archive');
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->assertRefused($this->dir . '/nope.zip', 'missing file');
    }

    /**
     * The guard reads declared metadata, which an archive can lie about. It is trusted only to
     * refuse, never to accept — the extractor must still verify each written path afterwards.
     * This test pins the contract so that expectation is not quietly dropped later.
     */
    public function test_inspection_reports_what_it_saw_for_the_extractor_to_verify(): void
    {
        $result = ZipExtractGuard::inspect($this->archive(['a.txt' => 'aa', 'b/c.txt' => 'bbb']));

        $this->assertSame(['a.txt', 'b/c.txt'], $result['names']);
        $this->assertSame(5, $result['bytes']);
    }
}
