<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use PHPUnit\Framework\TestCase;

/**
 * B3 / request #10 — recursive search.
 *
 * This runs inside a request an operator is waiting on, against a tree whose size nobody
 * controls: `node_modules` and `vendor` are normal. So the tests are mostly about limits and
 * about not following links — unbounded, a search is a way to make the panel appear broken
 * by typing one letter.
 */
final class VhostFileSearchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/az-search-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public/assets', 0755, true);
        mkdir($this->root . '/storage/logs', 0755, true);
        file_put_contents($this->root . '/index.php', "<?php // entry\n");
        file_put_contents($this->root . '/.env', "APP_KEY=base64:secret\nDB_HOST=127.0.0.1\n");
        file_put_contents($this->root . '/public/app.css', "body{color:red}\n");
        file_put_contents($this->root . '/public/assets/app.js', "console.log('hi')\n");
        file_put_contents($this->root . '/storage/logs/laravel.log', "production.ERROR: boom\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function search(string $query, string $from = '', string $contains = ''): array
    {
        return VhostFileOp::execute([
            'op' => 'search', 'root' => $this->root, 'path' => $from,
            'query' => $query, 'contains' => $contains,
        ]);
    }

    /** @return list<string> */
    private function paths(array $result): array
    {
        return array_column($result['results'], 'path');
    }

    // ------------------------------------------------------------------ finding

    public function test_a_name_is_found_at_any_depth(): void
    {
        $paths = $this->paths($this->search('app'));

        $this->assertContains('public/app.css', $paths);
        $this->assertContains('public/assets/app.js', $paths);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $this->assertContains('index.php', $this->paths($this->search('INDEX')));
    }

    public function test_a_directory_is_a_result_too(): void
    {
        $results = $this->search('assets')['results'];

        $this->assertSame('dir', $results[0]['type']);
        $this->assertNull($results[0]['size']);
    }

    public function test_a_search_can_start_from_a_subdirectory(): void
    {
        $paths = $this->paths($this->search('app', 'public/assets'));

        $this->assertSame(['public/assets/app.js'], $paths);
    }

    public function test_dotfiles_are_searchable(): void
    {
        $this->assertContains('.env', $this->paths($this->search('env')));
    }

    // ------------------------------------------------------------ content search

    public function test_content_can_be_searched(): void
    {
        $paths = $this->paths($this->search('', '', 'production.ERROR'));

        $this->assertSame(['storage/logs/laravel.log'], $paths);
    }

    public function test_name_and_content_together_narrow_the_result(): void
    {
        $this->assertSame([], $this->paths($this->search('app', '', 'production.ERROR')));
        $this->assertSame(['public/app.css'], $this->paths($this->search('app', '', 'color')));
    }

    /** A directory has no content to match. */
    public function test_a_content_search_returns_no_directories(): void
    {
        foreach ($this->search('', '', 'body')['results'] as $row) {
            $this->assertSame('file', $row['type']);
        }
    }

    /** The point is finding a setting in a config file, not scanning uploaded video. */
    public function test_a_large_file_is_not_grepped(): void
    {
        file_put_contents($this->root . '/big.bin', str_repeat('needle ', 60000));

        $this->assertSame([], $this->paths($this->search('', '', 'needle')));
    }

    // -------------------------------------------------------------------- limits

    public function test_the_result_count_is_capped_and_says_so(): void
    {
        for ($i = 0; $i < 260; $i++) {
            file_put_contents($this->root . '/storage/logs/hit-' . $i . '.txt', 'x');
        }

        $result = $this->search('hit-');

        $this->assertSame(200, $result['count']);
        $this->assertTrue($result['truncated'], 'a silently capped list reads as "not there"');
        $this->assertSame(200, $result['limit']);
    }

    public function test_an_untruncated_search_says_that_too(): void
    {
        $this->assertFalse($this->search('index')['truncated']);
    }

    public function test_depth_is_bounded(): void
    {
        $deep = $this->root;
        for ($i = 0; $i < 15; $i++) {
            $deep .= '/d' . $i;
        }
        mkdir($deep, 0755, true);
        file_put_contents($deep . '/buried.txt', 'x');

        $this->assertSame([], $this->paths($this->search('buried')));
    }

    // --------------------------------------------------------------- containment

    /**
     * Following a link would leave the vhost root through something the site itself can
     * create, and a link to its own parent would loop until the request timed out.
     */
    public function test_a_symlinked_directory_is_not_followed(): void
    {
        $outside = sys_get_temp_dir() . '/az-search-outside-' . bin2hex(random_bytes(4));
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'x');
        symlink($outside, $this->root . '/public/escape');

        try {
            $paths = $this->paths($this->search('secret'));
            $this->assertSame([], $paths);
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function test_a_self_referential_link_does_not_hang_the_search(): void
    {
        symlink($this->root, $this->root . '/public/loop');

        $result = $this->search('index');

        $this->assertContains('index.php', $this->paths($result));
    }

    public function test_searching_outside_the_root_is_refused(): void
    {
        $this->expectException(VhostFileException::class);
        $this->search('passwd', '../../etc');
    }

    public function test_a_file_cannot_be_the_starting_point(): void
    {
        try {
            $this->search('x', 'index.php');
            $this->fail('expected a refusal');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('directory', $e->getMessage());
        }
    }

    public function test_an_empty_search_is_refused(): void
    {
        try {
            $this->search('');
            $this->fail('expected a refusal');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('search for', $e->getMessage());
        }
    }

    public function test_search_is_a_recognised_operation(): void
    {
        $this->assertContains('search', VhostFileOp::OPS);
    }
}
