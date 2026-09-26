<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use PHPUnit\Framework\TestCase;

/**
 * B3 / request #10 — permissions.
 *
 * Against a real tree, because the thing being tested is what the filesystem ends up
 * holding. The refusals are the substance: the modes an operator reaches for when
 * something does not work are 777 and 666, and both undo the per-site separation A25
 * exists to provide.
 */
final class VhostFileChmodTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/az-chmod-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public', 0755, true);
        file_put_contents($this->root . '/index.php', "<?php\n");
        file_put_contents($this->root . '/deploy.sh', "#!/bin/sh\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function chmod(string $rel, string $mode): array
    {
        return VhostFileOp::execute(['op' => 'chmod', 'root' => $this->root, 'path' => $rel, 'mode' => $mode]);
    }

    private function modeOf(string $rel): string
    {
        clearstatcache(true, $this->root . '/' . $rel);

        return substr(sprintf('%o', fileperms($this->root . '/' . ltrim($rel, '/'))), -4);
    }

    private function expectRefusal(string $rel, string $mode, string $needle): void
    {
        $before = $this->modeOf($rel);
        try {
            $this->chmod($rel, $mode);
            $this->fail('expected a refusal for mode ' . $mode);
        } catch (VhostFileException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
        $this->assertSame($before, $this->modeOf($rel), 'the mode must be unchanged after a refusal');
    }

    // ----------------------------------------------------------------- presets

    public function test_the_default_preset_differs_for_a_file_and_a_directory(): void
    {
        $this->assertSame('0644', $this->chmod('index.php', 'default')['mode']);
        $this->assertSame('0755', $this->chmod('public', 'default')['mode']);
    }

    /** 644 on a folder is the classic way to make a whole site tree unreadable. */
    public function test_a_directory_keeps_its_execute_bit(): void
    {
        $this->chmod('public', 'default');

        $this->assertSame('0755', $this->modeOf('public'));
    }

    public function test_private_locks_a_file_to_its_owner(): void
    {
        $this->chmod('index.php', 'private');

        $this->assertSame('0600', $this->modeOf('index.php'));
    }

    public function test_executable_makes_a_script_runnable(): void
    {
        $this->chmod('deploy.sh', 'executable');

        $this->assertSame('0755', $this->modeOf('deploy.sh'));
    }

    public function test_an_octal_mode_is_accepted(): void
    {
        $this->assertSame('0640', $this->chmod('index.php', '640')['mode']);
        $this->assertSame('0640', $this->chmod('index.php', '0640')['mode']);
    }

    // ---------------------------------------------------------------- refusals

    public function test_world_writable_is_refused(): void
    {
        $this->expectRefusal('index.php', '777', 'writable');
        $this->expectRefusal('index.php', '666', 'writable');
    }

    public function test_group_writable_is_refused(): void
    {
        $this->expectRefusal('index.php', '664', 'writable');
    }

    /** Nothing the File Manager does should be able to create a setuid file. */
    public function test_setuid_setgid_and_sticky_bits_are_refused(): void
    {
        foreach (['4755', '2755', '1755'] as $mode) {
            $this->expectRefusal('index.php', $mode, 'octal digits');
        }
    }

    public function test_a_mode_the_owner_cannot_read_is_refused(): void
    {
        $this->expectRefusal('index.php', '044', 'cannot read');
    }

    public function test_a_directory_without_owner_execute_is_refused(): void
    {
        $this->expectRefusal('public', '644', 'could not be entered');
    }

    /**
     * A link pointing outside never reaches the symlink check — VhostPath refuses it as
     * escaping the root first, which is the stronger refusal of the two.
     */
    public function test_a_symlink_out_of_the_root_is_refused_as_an_escape(): void
    {
        symlink('/etc/hosts', $this->root . '/hosts-link');

        try {
            $this->chmod('hosts-link', 'private');
            $this->fail('expected a refusal for a symlink leaving the root');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('outside the vhost directory', $e->getMessage());
        }
    }

    /**
     * A link pointing *inside* the root is contained, so it is the symlink check that has
     * to stop it: chmod follows the link and would change the mode of the file it points
     * at, not the link the operator clicked.
     */
    public function test_a_symlink_inside_the_root_is_refused_because_chmod_follows_it(): void
    {
        symlink($this->root . '/index.php', $this->root . '/public/alias.php');
        $before = $this->modeOf('index.php');

        try {
            $this->chmod('public/alias.php', 'private');
            $this->fail('expected a refusal for a symlink');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('symlink', $e->getMessage());
        }
        $this->assertSame($before, $this->modeOf('index.php'), 'the target must be untouched');
    }

    public function test_the_vhost_root_is_refused(): void
    {
        try {
            $this->chmod('', 'private');
            $this->fail('expected a refusal for the root');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('vhost root', $e->getMessage());
        }
    }

    public function test_a_path_outside_the_root_is_refused(): void
    {
        $this->expectException(VhostFileException::class);
        $this->chmod('../../etc/hosts', 'private');
    }

    public function test_a_missing_mode_names_the_presets(): void
    {
        try {
            $this->chmod('index.php', '');
            $this->fail('expected a refusal for an empty mode');
        } catch (VhostFileException $e) {
            $this->assertStringContainsString('default', $e->getMessage());
            $this->assertStringContainsString('private', $e->getMessage());
        }
    }

    public function test_nonsense_is_refused(): void
    {
        foreach (['rwx', '9999', '8', '-rw-r--r--'] as $mode) {
            $this->expectRefusal('index.php', $mode, 'octal digits');
        }
    }

    public function test_chmod_is_a_recognised_operation(): void
    {
        $this->assertContains('chmod', VhostFileOp::OPS);
    }
}
