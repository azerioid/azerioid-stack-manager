<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use PHPUnit\Framework\TestCase;

/**
 * B3 / request #10 — copying a file inside a vhost.
 *
 * Runs against a real temporary tree rather than a fake, because the whole risk of this
 * operation is in what `realpath` and `copy` do with a path, not in what a fake would
 * agree to. The copy itself is one line; every test here is about containment, or about
 * refusing something whose consequences an operator would not see until later.
 */
final class VhostFileCopyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/az-copy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public/assets', 0755, true);
        file_put_contents($this->root . '/index.php', "<?php // site\n");
        file_put_contents($this->root . '/public/app.css', "body{}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function copy(string $from, string $to): array
    {
        return VhostFileOp::execute([
            'op' => 'copy', 'root' => $this->root, 'path' => $from, 'dest' => $to,
        ]);
    }

    private function expectRefusal(string $from, string $to, string $because): void
    {
        try {
            $this->copy($from, $to);
            $this->fail('expected a refusal: ' . $because);
        } catch (VhostFileException $e) {
            $this->assertNotSame('', $e->getMessage(), $because);
        }
    }

    // ------------------------------------------------------------- happy path

    public function test_a_file_is_copied_and_the_original_stays(): void
    {
        $result = $this->copy('index.php', 'public/index.php');

        $this->assertTrue($result['copied']);
        $this->assertSame('public/index.php', $result['path']);
        $this->assertFileExists($this->root . '/public/index.php');
        $this->assertFileExists($this->root . '/index.php');
        $this->assertSame(
            file_get_contents($this->root . '/index.php'),
            file_get_contents($this->root . '/public/index.php')
        );
    }

    public function test_the_copy_reports_where_it_came_from(): void
    {
        $this->assertSame('public/app.css', $this->copy('public/app.css', 'public/assets/app.css')['from']);
    }

    /**
     * A leading slash is *not* quietly stripped here: VhostPath treats it as an absolute
     * path and refuses it, for the source exactly as for the destination. The File Manager
     * trims what the operator types before calling, which is where that convenience
     * belongs — the broker's job is to refuse anything it cannot prove is inside the root.
     */
    public function test_a_leading_slash_is_refused_not_quietly_stripped(): void
    {
        $this->expectRefusal('/public/app.css', 'public/app2.css', 'an absolute-looking source');
        $this->assertFileDoesNotExist($this->root . '/public/app2.css');
    }

    // ------------------------------------------------------------- containment

    public function test_a_destination_climbing_out_of_the_root_is_refused(): void
    {
        $this->expectRefusal('index.php', '../escaped.php', 'a climbing destination');
        $this->assertFileDoesNotExist(dirname($this->root) . '/escaped.php');
    }

    public function test_an_absolute_destination_is_refused(): void
    {
        $this->expectRefusal('index.php', '/etc/cron.d/evil', 'an absolute destination');
        $this->assertFileDoesNotExist('/etc/cron.d/evil');
    }

    public function test_a_source_outside_the_root_is_refused(): void
    {
        $this->expectRefusal('../../etc/passwd', 'passwd.txt', 'a climbing source');
        $this->assertFileDoesNotExist($this->root . '/passwd.txt');
    }

    /**
     * The dangerous shape: a directory inside the vhost that points outside it. Writing
     * through it would land a file anywhere the link points while every path involved
     * still looks relative.
     */
    public function test_a_destination_inside_a_symlinked_directory_is_refused(): void
    {
        $outside = sys_get_temp_dir() . '/az-copy-outside-' . bin2hex(random_bytes(4));
        mkdir($outside, 0755, true);
        symlink($outside, $this->root . '/public/link');

        try {
            $this->expectRefusal('index.php', 'public/link/landed.php', 'a symlinked destination directory');
            $this->assertFileDoesNotExist($outside . '/landed.php');
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function test_copying_a_symlink_itself_is_refused(): void
    {
        symlink('/etc/hosts', $this->root . '/hosts-link');

        $this->expectRefusal('hosts-link', 'hosts-copy', 'a symlink source');
        $this->assertFileDoesNotExist($this->root . '/hosts-copy');
    }

    // --------------------------------------------------------------- refusals

    public function test_the_vhost_root_cannot_be_copied(): void
    {
        $this->expectRefusal('', 'clone', 'the root itself');
    }

    /** Overwriting silently is how a site loses a file nobody asked to lose. */
    public function test_an_existing_destination_is_refused_rather_than_overwritten(): void
    {
        $before = file_get_contents($this->root . '/public/app.css');

        $this->expectRefusal('index.php', 'public/app.css', 'an existing destination');

        $this->assertSame($before, file_get_contents($this->root . '/public/app.css'));
    }

    /**
     * A recursive copy can duplicate a site's whole tree from one click, and the
     * containment check would have to repeat for every entry as it is created. It waits
     * for the compress/extract work, where bounded output is the subject.
     */
    public function test_copying_a_directory_is_refused_with_a_reason(): void
    {
        try {
            $this->copy('public', 'public-copy');
            $this->fail('expected a refusal for a directory');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('not supported yet', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root . '/public-copy');
    }

    public function test_a_missing_destination_argument_is_refused(): void
    {
        $this->expectRefusal('index.php', '', 'no destination');
    }

    public function test_a_missing_source_is_refused(): void
    {
        $this->expectRefusal('nope.php', 'nope-copy.php', 'a source that does not exist');
    }

    public function test_copy_is_a_recognised_operation(): void
    {
        $this->assertContains('copy', VhostFileOp::OPS);
    }
}
