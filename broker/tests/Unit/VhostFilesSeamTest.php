<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use AzerioidPanel\Broker\Files\VhostFiles;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The seam between VhostFiles::run() (builds the helper payload) and VhostFileOp::execute()
 * (the helper that reads it). Every other test fakes one side of it, which is how zip, chmod
 * and search shipped in v1.9.0 with their inputs never forwarded: each side was tested, the
 * hand-over between them was not, and all three failed on every real host.
 *
 * Here the helper is not faked. The payload VhostFiles::run() would pipe to it is fed to the
 * real VhostFileOp::execute() against a real temporary docroot.
 */
final class VhostFilesSeamTest extends TestCase
{
    private FakeRuntime $rt;

    private Kernel $kernel;

    private string $tmp;

    private string $docroot;

    private string $handoverDir;

    /** When set, the fake "site" replaces its archive with a symlink to this path. */
    private ?string $plantSymlinkTo = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/azerioid-seam-' . bin2hex(random_bytes(4));
        $this->docroot = $this->tmp . '/www';
        mkdir($this->docroot . '/sub', 0777, true);
        file_put_contents($this->docroot . '/index.php', "<?php echo 'hello';\n");
        file_put_contents($this->docroot . '/about.html', "<h1>about</h1>\n");
        file_put_contents($this->docroot . '/sub/inner.txt', "inner\n");

        $cfg = new Config();
        $cfg->wwwRoot = '/data/www';
        $cfg->stagingDir = $this->tmp . '/staging';
        $cfg->vhostZipBuildDir = $this->tmp . '/zipbuild';
        $cfg->panelRoot = $this->tmp . '/panel';
        $this->handoverDir = $cfg->panelRoot . '/web/storage/framework/tmp';
        mkdir($this->handoverDir, 0777, true);
        $cfg->auditLog = $this->tmp . '/audit.log';
        $this->rt = new FakeRuntime();
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->rt->files['/etc/caddy/conf.d/shop.example.com.conf'] =
            "shop.example.com {\n    root * /data/www/shop.example.com\n    php_fastcgi unix//run/php/php8.4-fpm.sock\n    file_server\n}\n";
        $this->rt->dirs['/data/www/shop.example.com'] = true;
        $this->rt->uid = 1000;

        // Stand in for the helper process: run the real operation on the payload it would get.
        $this->rt->execHook = function (array $command, ?string $stdin): void {
            // Root's side of the handover, done for real on the temp tree.
            if ($command[0] === '/usr/bin/stat' && ($command[2] ?? '') === '%F') {
                $f = (string) $command[3];
                $this->rt->script($command, 0, is_link($f) ? "symbolic link\n" : (is_file($f) ? "regular file\n" : "\n"));

                return;
            }
            if ($command[0] === '/bin/mv') {
                $ok = @rename((string) $command[2], (string) $command[3]);
                $this->rt->script($command, $ok ? 0 : 1, '', $ok ? '' : 'mv failed');

                return;
            }
            if ($command[0] === '/bin/rm') {
                exec('rm -rf ' . escapeshellarg((string) end($command)));

                return;
            }
            if ($command !== [PHP_BINARY, VhostFiles::HELPER]) {
                return;
            }
            $req = json_decode((string) $stdin, true);
            $req['root'] = $this->docroot; // the Caddy root is fake; the files are real
            if (($req['out'] ?? '') !== '') {
                @mkdir(dirname($req['out']), 0777, true);
            }
            try {
                $reply = ['ok' => true, 'data' => VhostFileOp::execute($req)];
            } catch (VhostFileException $e) {
                $reply = ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->getCode()];
            }
            if (($req['out'] ?? '') !== '' && is_file($req['out'])) {
                $this->rt->files[$req['out']] = (string) file_get_contents($req['out']);
                if ($this->plantSymlinkTo !== null) {
                    unlink($req['out']);
                    symlink($this->plantSymlinkTo, $req['out']);
                }
            }
            $this->rt->script($command, 0, json_encode($reply, JSON_UNESCAPED_SLASHES) . "\n");
        };
        $this->kernel = new Kernel($cfg, $this->rt);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /** @return array{0:int,1:array} */
    private function call(string $action, array $stdin): array
    {
        ob_start();
        $code = $this->kernel->run(['broker', $action, 'shop.example.com'], $stdin + ['admin_user_id' => '1']);
        $out = ob_get_clean();

        return [$code, (array) json_decode(trim((string) $out), true)];
    }

    /** @return array<string,string> entry name => contents */
    private function zipEntries(array $json): array
    {
        $path = (string) ($json['data']['path'] ?? '');
        $this->assertStringStartsWith($this->handoverDir . '/azerioid-zip-', $path, 'handed over into the panel temp dir');
        $this->assertFileExists($path);
        $this->assertFalse(is_link($path));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'the archive must be a valid zip');
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '/')) {
                $out[$name] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        ksort($out);

        return $out;
    }

    /** The operator's report: one selected file returned 422. */
    public function test_zip_a_single_file(): void
    {
        [$code, $json] = $this->call('vhost.files.zip', ['paths' => ['index.php']]);

        $this->assertSame(0, $code, (string) json_encode($json));
        $this->assertSame(['index.php' => "<?php echo 'hello';\n"], $this->zipEntries($json));
    }

    public function test_zip_several_files(): void
    {
        [$code, $json] = $this->call('vhost.files.zip', ['paths' => ['index.php', 'about.html']]);

        $this->assertSame(0, $code, (string) json_encode($json));
        $this->assertSame(['about.html', 'index.php'], array_keys($this->zipEntries($json)));
    }

    /** v1.9.0's own fix: a selected directory must contribute its contents. */
    public function test_zip_a_directory_includes_its_contents(): void
    {
        [$code, $json] = $this->call('vhost.files.zip', ['paths' => ['sub']]);

        $this->assertSame(0, $code, (string) json_encode($json));
        $this->assertSame(['sub/inner.txt' => "inner\n"], $this->zipEntries($json));
    }

    public function test_zip_still_refuses_to_escape_the_docroot(): void
    {
        [$code, $json] = $this->call('vhost.files.zip', ['paths' => ['../../etc/passwd']]);

        $this->assertNotSame(0, $code);
        $this->assertArrayNotHasKey('path', (array) ($json['data'] ?? []));
    }

    public function test_chmod_receives_its_mode(): void
    {
        [$code, $json] = $this->call('vhost.files.chmod', ['path' => 'about.html', 'mode' => '0640']);

        $this->assertSame(0, $code, (string) json_encode($json));
        clearstatcache();
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->docroot . '/about.html')), -4));
    }

    public function test_search_receives_its_query(): void
    {
        [$code, $json] = $this->call('vhost.files.search', ['path' => '', 'query' => 'inner']);

        $this->assertSame(0, $code, (string) json_encode($json));
        $this->assertSame(['sub/inner.txt'], array_column((array) ($json['data']['results'] ?? []), 'path'));
    }

    /** The build tree never keeps a copy of the site, whatever happened. */
    public function test_build_directory_is_removed(): void
    {
        $this->call('vhost.files.zip', ['paths' => ['index.php']]);

        $this->assertSame([], glob($this->tmp . '/zipbuild/zip-*') ?: []);
    }

    /** A site that swaps its archive for a symlink must not get the panel to stream the target. */
    public function test_a_planted_symlink_is_refused(): void
    {
        file_put_contents($this->tmp . '/panel-secret', "APP_KEY=secret\n");
        $this->plantSymlinkTo = $this->tmp . '/panel-secret';

        [$code, $json] = $this->call('vhost.files.zip', ['paths' => ['index.php']]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not a regular file', (string) ($json['error'] ?? ''));
        $this->assertSame([], glob($this->handoverDir . '/*') ?: [], 'nothing handed over');
        $this->assertSame([], glob($this->tmp . '/zipbuild/zip-*') ?: []);
    }
}
