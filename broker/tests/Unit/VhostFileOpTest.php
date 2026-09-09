<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use AzerioidPanel\Broker\Files\VhostPath;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

final class VhostFileOpTest extends TestCase
{
    private string $root;
    private string $other;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/az-fm-' . bin2hex(random_bytes(4));
        $this->root = $base . '/vhost';
        $this->other = $base . '/other-vhost';
        mkdir($this->root, 0770, true);
        mkdir($this->other, 0770, true);
        file_put_contents($this->root . '/index.php', "<?php echo 'ok';\n");
        mkdir($this->root . '/public', 0770);
        file_put_contents($this->root . '/public/hello.txt', "hello\n");
        file_put_contents($this->other . '/secret.php', "<?php echo 'secret';\n");
    }

    protected function tearDown(): void
    {
        $this->rmTree(dirname($this->root));
        parent::tearDown();
    }

    public function test_list_read_write_rename_delete_round_trip(): void
    {
        $list = VhostFileOp::execute(['op' => 'list', 'root' => $this->root, 'path' => '']);
        $names = array_column($list['entries'], 'name');
        $this->assertContains('index.php', $names);
        $this->assertContains('public', $names);

        $read = VhostFileOp::execute(['op' => 'read', 'root' => $this->root, 'path' => 'public/hello.txt']);
        $this->assertSame("hello\n", base64_decode((string) $read['content_base64'], true));
        $this->assertTrue($read['text']);

        $write = VhostFileOp::execute([
            'op' => 'write',
            'root' => $this->root,
            'path' => 'public/new.txt',
            'content_base64' => base64_encode('created'),
        ]);
        $this->assertTrue($write['written']);
        $this->assertSame('created', file_get_contents($this->root . '/public/new.txt'));

        $ren = VhostFileOp::execute([
            'op' => 'rename',
            'root' => $this->root,
            'path' => 'public/new.txt',
            'dest' => 'public/renamed.txt',
        ]);
        $this->assertSame('public/renamed.txt', $ren['path']);
        $this->assertFileExists($this->root . '/public/renamed.txt');
        $this->assertFileDoesNotExist($this->root . '/public/new.txt');

        $del = VhostFileOp::execute([
            'op' => 'delete',
            'root' => $this->root,
            'path' => 'public/renamed.txt',
        ]);
        $this->assertTrue($del['deleted']);
        $this->assertFileDoesNotExist($this->root . '/public/renamed.txt');
    }

    public function test_path_traversal_read_write_delete_are_rejected(): void
    {
        foreach (['../../../etc/passwd', '../../other-vhost/secret.php', '/etc/passwd'] as $attack) {
            try {
                VhostFileOp::execute(['op' => 'read', 'root' => $this->root, 'path' => $attack]);
                $this->fail('read should reject ' . $attack);
            } catch (VhostFileException $e) {
                $this->assertSame(3, $e->errorCode);
            }
            try {
                VhostFileOp::execute([
                    'op' => 'write',
                    'root' => $this->root,
                    'path' => $attack,
                    'content_base64' => base64_encode('pwn'),
                ]);
                $this->fail('write should reject ' . $attack);
            } catch (VhostFileException $e) {
                $this->assertTrue(in_array($e->errorCode, [2, 3], true));
            }
            try {
                VhostFileOp::execute(['op' => 'delete', 'root' => $this->root, 'path' => $attack]);
                $this->fail('delete should reject ' . $attack);
            } catch (VhostFileException $e) {
                $this->assertTrue(in_array($e->errorCode, [2, 3], true));
            }
        }
        $this->assertSame("<?php echo 'secret';\n", file_get_contents($this->other . '/secret.php'));
        $this->assertFileExists('/etc/passwd');
    }

    public function test_symlink_escape_read_and_write_are_rejected(): void
    {
        symlink('/etc', $this->root . '/etc-link');
        symlink($this->other, $this->root . '/sibling');

        $list = VhostFileOp::execute(['op' => 'list', 'root' => $this->root, 'path' => '']);
        $byName = [];
        foreach ($list['entries'] as $row) {
            $byName[$row['name']] = $row;
        }
        $this->assertTrue($byName['etc-link']['escaped']);
        $this->assertTrue($byName['sibling']['escaped']);

        try {
            VhostFileOp::execute(['op' => 'read', 'root' => $this->root, 'path' => 'etc-link/passwd']);
            $this->fail('read through /etc symlink should fail');
        } catch (VhostFileException $e) {
            $this->assertSame(3, $e->errorCode);
        }
        try {
            VhostFileOp::execute(['op' => 'read', 'root' => $this->root, 'path' => 'sibling/secret.php']);
            $this->fail('read through sibling-vhost symlink should fail');
        } catch (VhostFileException $e) {
            $this->assertSame(3, $e->errorCode);
        }
        try {
            VhostFileOp::execute([
                'op' => 'write',
                'root' => $this->root,
                'path' => 'etc-link/azerioid-pwn',
                'content_base64' => base64_encode('nope'),
            ]);
            $this->fail('write through /etc symlink should fail');
        } catch (VhostFileException $e) {
            $this->assertSame(3, $e->errorCode);
        }
        $this->assertFileDoesNotExist('/etc/azerioid-pwn');
        $this->assertSame("<?php echo 'secret';\n", file_get_contents($this->other . '/secret.php'));

        // Deleting the escaped symlink removes the link, not the target.
        VhostFileOp::execute(['op' => 'delete', 'root' => $this->root, 'path' => 'etc-link']);
        $this->assertFalse(is_link($this->root . '/etc-link'));
        $this->assertDirectoryExists('/etc');
    }

    public function test_rename_and_move_dest_outside_vhost_is_rejected(): void
    {
        try {
            VhostFileOp::execute([
                'op' => 'rename',
                'root' => $this->root,
                'path' => 'index.php',
                'dest' => '../../../tmp/azerioid-escape.php',
            ]);
            $this->fail('rename dest outside should fail');
        } catch (VhostFileException $e) {
            $this->assertSame(3, $e->errorCode);
        }
        try {
            VhostFileOp::execute([
                'op' => 'move',
                'root' => $this->root,
                'path' => 'public/hello.txt',
                'dest' => '../../other-vhost/stolen.txt',
            ]);
            $this->fail('move dest outside should fail');
        } catch (VhostFileException $e) {
            $this->assertSame(3, $e->errorCode);
        }
        $this->assertFileExists($this->root . '/index.php');
        $this->assertFileExists($this->root . '/public/hello.txt');
        $this->assertFileDoesNotExist($this->other . '/stolen.txt');
    }

    public function test_no_archive_extract_operation(): void
    {
        $this->assertNotContains('extract', VhostFileOp::OPS);
        $this->assertNotContains('unzip', VhostFileOp::OPS);
        $this->assertArrayNotHasKey('vhost.files.extract', Kernel::ACTIONS);
        $this->assertArrayNotHasKey('vhost.files.unzip', Kernel::ACTIONS);
        try {
            VhostFileOp::execute(['op' => 'extract', 'root' => $this->root, 'path' => 'x.zip']);
            $this->fail('extract must not exist');
        } catch (VhostFileException $e) {
            $this->assertSame(2, $e->errorCode);
        }
    }

    public function test_lexical_join_rejects_dotdot_climb(): void
    {
        $this->assertNull(VhostPath::lexicalJoin($this->root, '../../../etc/passwd'));
        $this->assertNull(VhostPath::lexicalJoin($this->root, '/etc/passwd'));
        $this->assertNotNull(VhostPath::lexicalJoin($this->root, 'public/../index.php'));
    }

    public function test_in_root_symlink_can_be_read(): void
    {
        symlink($this->root . '/public/hello.txt', $this->root . '/alias.txt');
        $read = VhostFileOp::execute(['op' => 'read', 'root' => $this->root, 'path' => 'alias.txt']);
        $this->assertSame("hello\n", base64_decode((string) $read['content_base64'], true));
    }

    public function test_size_limit_rejects_oversized_write(): void
    {
        try {
            VhostFileOp::execute([
                'op' => 'write',
                'root' => $this->root,
                'path' => 'big.bin',
                'content_base64' => base64_encode(str_repeat('a', 100)),
                'max_bytes' => 16,
            ]);
            $this->fail('oversized write should fail');
        } catch (VhostFileException $e) {
            $this->assertSame(2, $e->errorCode);
        }
        $this->assertFileDoesNotExist($this->root . '/big.bin');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isLink() || $file->isFile()) {
                @unlink($file->getPathname());
            } else {
                @rmdir($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
