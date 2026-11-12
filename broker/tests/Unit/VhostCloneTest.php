<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A79 (increment 1): clone a site to a new domain — vhost replicated and the
 * file tree copied, as a fully isolated vhost. Databases and Octane/PM2/Docker
 * runtimes are not cloned yet and the action refuses those sources.
 */
final class VhostCloneTest extends TestCase
{
    private FakeRuntime $rt;
    private Config $cfg;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->rt->files['/etc/caddy/Caddyfile'] = "{\n    admin off\n}\nimport /etc/caddy/conf.d/*.conf\n";
        $this->cfg = new Config();
        $this->kernel = new Kernel($this->cfg, $this->rt);
        $this->rt->script(['/usr/bin/caddy', 'validate', '--config', '/etc/caddy/Caddyfile'], 0, 'Valid configuration');
    }

    /** @return array{0:int,1:array} */
    private function kernelRun(array $argv): array
    {
        ob_start();
        $code = $this->kernel->run($argv, []);
        $out = (string) ob_get_clean();

        return [$code, json_decode(trim($out), true) ?: []];
    }

    /** One ustar entry: header plus data blocks. */
    private static function tarEntry(string $name, string $type = '0', string $data = '', string $link = '', int $mode = 0644): string
    {
        $h = str_pad($name, 100, "\0")
            . sprintf("%07o\0", $mode) . sprintf("%07o\0", 0) . sprintf("%07o\0", 0)
            . sprintf("%011o\0", strlen($data)) . sprintf("%011o\0", 0)
            . '        ' . $type . str_pad($link, 100, "\0") . "ustar\0" . '00';
        $h = str_pad($h, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($h)));
        $h = substr_replace($h, sprintf("%06o\0 ", $sum), 148, 8);

        return $h . ($data === '' ? '' : str_pad($data, (int) ceil(strlen($data) / 512) * 512, "\0"));
    }

    /** @param list<string> $entries */
    private static function tarOf(array $entries): string
    {
        return implode('', $entries) . str_repeat("\0", 1024);
    }

    private static function plainSiteTar(): string
    {
        return self::tarOf([
            self::tarEntry('./', '5', mode: 0755),
            self::tarEntry('./public/', '5', mode: 0755),
            self::tarEntry('./public/index.php', '0', '<?php echo 1;'),
            // `artisan storage:link` writes an absolute link; a clone keeps it (A72 sweeps it).
            self::tarEntry('./public/storage', '2', link: '/data/www/src.test/storage/app/public'),
        ]);
    }

    /**
     * Model the A103 copy: the source-identity tar writes $archive bytes (exit
     * $packExit) to the staging file its root shell redirects to; extraction makes
     * the clone's directory.
     */
    private function modelCopy(string $dstTop, int $packExit = 0, ?string $archive = null): void
    {
        $archive ??= self::plainSiteTar();
        $this->rt->execFn = function (array $cmd) use ($dstTop, $packExit, $archive): mixed {
            if (($cmd[0] ?? '') === '/bin/sh' && ($cmd[2] ?? '') === 'umask 077 && exec "$@" > "$0"') {
                $this->rt->files[$cmd[3]] = $packExit >= 2 ? '' : $archive;

                return new \AzerioidPanel\Broker\ExecResult($cmd, $packExit, '', $packExit === 0 ? '' : 'tar: error');
            }
            if (($cmd[0] ?? '') === '/bin/cp') {
                $this->rt->files[$cmd[3]] = $this->rt->files[$cmd[2]] ?? '';
            }
            if (($cmd[0] ?? '') === '/usr/bin/tar' && in_array('-xf', $cmd, true)) {
                $this->rt->dirs[$dstTop] = true;
            }

            return null;
        };
    }

    /** Index of the first logged command matching $match, or null. */
    private function firstIndex(callable $match): ?int
    {
        foreach ($this->rt->execLog as $i => $e) {
            if ($match($e['command'])) {
                return $i;
            }
        }

        return null;
    }

    public function test_clone_replicates_the_vhost_and_copies_the_files(): void
    {
        [$c] = $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->assertSame(0, $c);
        $this->rt->script(['/usr/bin/stat', '-c', '%G', '/data/www/dst.test/public'], 0, 'az-vh-dst-test');
        $this->modelCopy('/data/www/dst.test');
        $this->rt->execLog = [];

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertArrayHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);

        $cmds = array_column($this->rt->execLog, 'command');
        // A103 (AZ-32): the source is read as the SOURCE identity, never by a root walk.
        $pack = $this->firstIndex(static fn (array $c): bool => in_array('/usr/bin/tar', $c, true) && in_array('-cf', $c, true));
        $this->assertNotNull($pack);
        $this->assertSame(['/usr/sbin/runuser', '-u', 'az-vh-src-test', '--', '/usr/bin/tar', '-C', '/data/www/src.test'], array_slice($cmds[$pack], 4, 7));
        $this->assertSame([], array_filter($cmds, static fn (array $c): bool => in_array('/usr/bin/rsync', $c, true)), 'no root rsync over the live source');
        // Extracted by root into the clone's directory without archive owners or modes —
        // from a copy root made after the source's tar exited, never the file tar wrote.
        $extract = $this->firstIndex(static fn (array $c): bool => array_slice($c, 0, 6) === ['/usr/bin/tar', '-C', '/data/www/dst.test', '--no-same-owner', '--no-same-permissions', '--no-overwrite-dir']);
        $this->assertNotNull($extract);
        $written = $cmds[$pack][3];
        $this->assertStringEndsWith('.checked.tar', end($cmds[$extract]));
        $this->assertNotSame($written, end($cmds[$extract]));
        $this->assertContains(['/bin/cp', '--', $written, end($cmds[$extract])], $cmds);
        // The staging archive does not stay.
        $this->assertSame([], array_filter(array_keys($this->rt->files), static fn (string $f): bool => str_contains($f, '/clone-dst-test-')));
        // The WHOLE copied tree (not just the docroot) is handed to the clone.
        $this->assertNotNull($this->firstIndex(static fn (array $c): bool => in_array('/usr/bin/chown', $c, true) && in_array('-R', $c, true) && in_array('/data/www/dst.test', $c, true)));
    }

    /** A103 (AZ-18): nothing for the clone — identity, pool, route — exists while root writes its tree. */
    public function test_clone_copies_before_the_clone_identity_or_route_exists(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->rt->script(['/usr/bin/stat', '-c', '%G', '/data/www/dst.test/public'], 0, 'az-vh-dst-test');
        $this->modelCopy('/data/www/dst.test');
        $routed = null;
        $inner = $this->rt->execFn;
        $this->rt->execFn = function (array $cmd, ?string $stdin) use ($inner, &$routed): mixed {
            if ($routed === null && isset($this->rt->files['/etc/caddy/conf.d/dst.test.conf'])) {
                $routed = count($this->rt->execLog) - 1;
            }

            return $inner($cmd, $stdin);
        };
        $this->rt->execLog = [];

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertSame(0, $code, json_encode($json));

        $extract = $this->firstIndex(static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/tar' && in_array('-xf', $c, true));
        // The first command that acts for the clone identity at all (useradd, a chown to it).
        $useradd = $this->firstIndex(static fn (array $c): bool => ($c[0] ?? '') !== '/usr/bin/id' && str_contains(implode(' ', $c), 'az-vh-dst-test'));
        $this->assertNotNull($extract);
        $this->assertNotNull($useradd);
        $this->assertNotNull($routed);
        $this->assertLessThan($useradd, $extract, 'the copy is done before the clone identity exists');
        $this->assertLessThan($routed, $extract, 'the copy is done before the clone is routed');
    }

    /** Clone src.test → dst.test with the source tar answering $packExit; returns [code, json]. */
    private function cloneWithPackExit(int $packExit, ?string $archive = null): array
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->rt->script(['/usr/bin/stat', '-c', '%G', '/data/www/dst.test/public'], 0, 'az-vh-dst-test');
        $this->modelCopy('/data/www/dst.test', $packExit, $archive);

        return $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
    }

    public function test_clone_rehardens_after_the_whole_tree_handover_and_keeps_execute_bits(): void
    {
        [$code, $json] = $this->cloneWithPackExit(0);
        $this->assertSame(0, $code, json_encode($json));

        // Order matters: the whole-tree chmod resets .env to group-readable, so the
        // A72 pass (.env 0600, escaping symlinks) must run once more AFTER it.
        $handover = null;
        $lastHarden = null;
        foreach ($this->rt->execLog as $i => $e) {
            $line = implode(' ', $e['command']);
            if (str_contains($line, "/data/www/dst.test /usr/bin/find . -type f -execdir /bin/chmod")) {
                $handover = $i;
                $this->assertStringContainsString('u=rwX,g=rwX,o=', $line, 'the handover must keep execute bits');
            }
            if (str_contains($line, 'azerioid-harden /data/www/dst.test')) {
                $lastHarden = $i;
            }
        }
        $this->assertNotNull($handover);
        $this->assertNotNull($lastHarden);
        $this->assertGreaterThan($handover, $lastHarden, 'content hardening must run after the whole-tree handover');
        // The welcome page seeded by vhost.add is not part of the clone.
        $this->assertArrayNotHasKey('/data/www/dst.test/public/index.php', $this->rt->files);
    }

    public function test_clone_rolls_back_when_the_copy_fails_but_tolerates_changed_files(): void
    {
        [$code, $json] = $this->cloneWithPackExit(2);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('clone was removed', (string) ($json['error'] ?? ''));
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files, 'a failed copy must not leave the vhost routing');
        $this->assertContains(
            ['/bin/rm', '-rf', '--one-file-system', '/data/www/dst.test'],
            array_column($this->rt->execLog, 'command')
        );
        $this->assertNull($this->firstIndex(static fn (array $c): bool => ($c[0] ?? '') !== '/usr/bin/id' && str_contains(implode(' ', $c), 'az-vh-dst-test') && !in_array('/usr/bin/pkill', $c, true) && !in_array('/usr/sbin/userdel', $c, true)), 'nothing is done for the clone identity after a failed copy');

        // Retry is not refused, and tar exit 1 (a file changed while read on a live site) is success.
        unset($this->rt->dirs['/data/www/dst.test']);
        [$code, $json] = $this->cloneWithPackExit(1);
        $this->assertSame(0, $code, json_encode($json));
        $this->assertArrayHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);
    }

    /** A103: a docroot path through a symlink in the copied tree is refused, never created or followed. */
    public function test_clone_refuses_a_docroot_reached_through_a_copied_symlink(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->modelCopy('/data/www/dst.test');
        $inner = $this->rt->execFn;
        $this->rt->execFn = function (array $cmd, ?string $stdin) use ($inner): mixed {
            if (($cmd[0] ?? '') === '/usr/bin/tar' && in_array('-xf', $cmd, true)) {
                $this->rt->links['/data/www/dst.test/public'] = true;
            }

            return $inner($cmd, $stdin);
        };
        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('no plain docroot', (string) ($json['error'] ?? ''));
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);
    }

    /** A103: the copy stream must be a plain tar — tar -x would decompress what the inspection read raw. */
    public function test_clone_refuses_a_compressed_copy_stream(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->modelCopy('/data/www/dst.test');
        $inner = $this->rt->execFn;
        $this->rt->execFn = function (array $cmd, ?string $stdin) use ($inner): mixed {
            if (($cmd[0] ?? '') === '/usr/bin/head') {
                return new \AzerioidPanel\Broker\ExecResult($cmd, 0, "\xfd7zXZ\x00", '');
            }

            return $inner($cmd, $stdin);
        };
        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('compressed', (string) ($json['error'] ?? ''));
        $this->assertNull($this->firstIndex(static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/tar' && in_array('-xf', $c, true)));
    }

    /** A103: a clone whose identity is another site's is refused before anything is made — and nothing of that site is removed. */
    public function test_clone_refuses_an_identity_of_another_site_and_leaves_it_alone(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        // dst.test and dst-test share the slug, so the identity az-vh-dst-test.
        $this->rt->files['/var/lib/azerioid-panel/vhost-users.json'] = json_encode(['users' => ['dst-test' => ['username' => 'az-vh-dst-test', 'root' => '/data/www/dst-test/public']]]);
        $this->modelCopy('/data/www/dst.test');
        $this->rt->execLog = [];

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertNotSame(0, $code);
        $cmds = array_column($this->rt->execLog, 'command');
        $this->assertSame([], array_filter($cmds, static fn (array $c): bool => in_array($c[0] ?? '', ['/usr/sbin/userdel', '/usr/bin/pkill'], true)), 'another site\'s identity is never removed');
        $this->assertSame([], array_filter($cmds, static fn (array $c): bool => in_array('/usr/bin/tar', $c, true)), 'nothing is copied');
    }

    /** A103 (AZ-32): the source-made stream is inspected like a backup archive before root extracts it. */
    public function test_clone_refuses_a_crafted_copy_stream(): void
    {
        $crafted = self::tarOf([
            self::tarEntry('./', '5', mode: 0755),
            self::tarEntry('./public', '2', link: '/etc'),
            self::tarEntry('./public/passwd', '0', 'x'),
        ]);
        [$code, $json] = $this->cloneWithPackExit(0, $crafted);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('below a symlink', (string) ($json['error'] ?? ''));
        $this->assertNull($this->firstIndex(static fn (array $c): bool => ($c[0] ?? '') === '/usr/bin/tar' && in_array('-xf', $c, true)), 'nothing is extracted');
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);
        $this->assertContains(['/bin/rm', '-rf', '--one-file-system', '/data/www/dst.test'], array_column($this->rt->execLog, 'command'));
    }

    public function test_clone_refuses_same_domain_and_an_existing_target(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        [$same] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'src.test']);
        $this->assertNotSame(0, $same);

        $this->kernelRun(['broker', 'vhost.add', 'dst.test', '/data/www/dst.test/public', 'php', '8.4']);
        [$exists] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertNotSame(0, $exists);
    }

    public function test_clone_refuses_when_another_vhost_shares_the_source_or_target_tree(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);

        // A site whose root sits under the target top — cloning there would
        // overwrite and take ownership of its files.
        $this->kernelRun(['broker', 'vhost.add', 'other.test', '/data/www/claimed.test/public', 'php', '8.4']);
        [$claimed, $cj] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'claimed.test']);
        $this->assertNotSame(0, $claimed);
        $this->assertStringContainsString('under claimed.test', str_replace('/data/www/', '', strtolower((string) ($cj['error'] ?? ''))));

        // A second site nested inside the source tree — copying it would leak its
        // files into the clone and hand them to the clone's identity.
        $this->kernelRun(['broker', 'vhost.add', 'nested.test', '/data/www/src.test/nested/public', 'php', '8.4']);
        [$nested, $nj] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'fresh.test']);
        $this->assertNotSame(0, $nested);
        $this->assertStringContainsString('source directory', strtolower((string) ($nj['error'] ?? '')));
    }

    public function test_clone_fails_closed_and_rolls_back_when_the_identity_handover_cannot_resolve(): void
    {
        $this->kernelRun(['broker', 'vhost.add', 'src.test', '/data/www/src.test/public', 'php', '8.4']);
        $this->modelCopy('/data/www/dst.test');
        // No stat script → the settled group reads empty → the handover must fail
        // closed: remove the partial copy and the just-created vhost, then error.
        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'src.test', 'dst.test']);
        $this->assertNotSame(0, $code);
        $this->assertArrayNotHasKey('/etc/caddy/conf.d/dst.test.conf', $this->rt->files);

        $removed = false;
        foreach ($this->rt->execLog as $e) {
            $cmd = $e['command'];
            if (($cmd[0] ?? '') === '/bin/rm' && in_array('/data/www/dst.test', $cmd, true)) {
                $removed = true;
            }
        }
        $this->assertTrue($removed, 'the partial copy must be removed on a failed handover');
    }

    public function test_clone_refuses_a_docker_source(): void
    {
        // Docker carries too much state to replicate safely — still refused.
        $this->rt->files['/etc/caddy/conf.d/box.test.conf'] =
            "# azerioid-managed engine=caddy type=proxy root=/data/www/box.test runtime=docker docker_port=37000\n"
            . "box.test {\n    reverse_proxy 127.0.0.1:37000\n}\n";

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'box.test', 'boxclone.test']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not supported yet', strtolower((string) ($json['error'] ?? '')));
    }

    public function test_clone_attempts_to_replicate_an_octane_runtime(): void
    {
        // An Octane source is no longer refused: the clone's files are copied and
        // the runtime is enabled best-effort. Enabling needs Supervisor/Composer,
        // which the fake host lacks, so it is reported as a runtime_error rather
        // than failing the whole clone.
        $this->rt->files['/etc/caddy/conf.d/app.test.conf'] =
            "# azerioid-managed engine=caddy type=php root=/data/www/app.test/public runtime=octane octane_port=34000 octane_max_requests=500 php_version=8.4\n"
            . "app.test {\n    reverse_proxy 127.0.0.1:34000\n}\n";
        $this->rt->script(['/usr/bin/stat', '-c', '%G', '/data/www/appclone.test/public'], 0, 'az-vh-appclone-test');
        $this->rt->script(['/usr/bin/id', '-u', 'az-vh-app-test'], 0, '1001');
        $this->modelCopy('/data/www/appclone.test');

        [$code, $json] = $this->kernelRun(['broker', 'vhost.clone', 'app.test', 'appclone.test']);
        $this->assertSame(0, $code, json_encode($json));
        $data = $json['data'] ?? [];
        $this->assertTrue(
            ($data['runtime'] ?? null) === 'octane' || ($data['runtime_error'] ?? null) !== null,
            'an octane source must be replicated or report why it could not be'
        );
    }
}
