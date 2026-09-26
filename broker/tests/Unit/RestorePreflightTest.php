<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\SpacesClient;
use PHPUnit\Framework\TestCase;

/**
 * B5.2 — `backup.restore.check`.
 *
 * Restores are queued now, so the panel asks for the refusal before handing the
 * work to a worker. The value of this action is entirely in it giving the *same*
 * answer as the restore while doing none of the work, so that is what these tests
 * pin: same refusals, no download, no decryption, nothing written.
 */
final class RestorePreflightTest extends TestCase
{
    private MemorySpacesTransport $spaces;

    protected function setUp(): void
    {
        parent::setUp();
        // Registered but deliberately empty: a preflight that reached for an
        // archive would fail here, which is the point.
        $this->spaces = new MemorySpacesTransport();
        SpacesClient::$http = $this->spaces->handler();
    }

    protected function tearDown(): void
    {
        SpacesClient::$http = null;
        parent::tearDown();
    }

    /** @return array{0:int,1:array} */
    private function check(FakeRuntime $rt, array $args, array $stdin, ?Config $cfg = null): array
    {
        ob_start();
        $code = (new Kernel($cfg ?? new Config(), $rt))->run(
            array_merge(['broker', 'backup.restore.check'], $args),
            $stdin
        );
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    private function protectedConfig(): Config
    {
        $cfg = new Config();
        $cfg->readonlyVhosts = ['projob.az', 'www.projob.az'];

        return $cfg;
    }

    // --------------------------------------------------------------- database

    public function test_an_existing_database_is_refused_with_the_restore_wording(): void
    {
        $rt = new FakeRuntime();
        $rt->dbRows = [['Database' => 'projob']];

        [$code, $json] = $this->check($rt, ['db'], ['target' => 'projob']);

        $this->assertNotSame(0, $code);
        // Identical wording to backup.restore.db, because it is the same guard.
        $this->assertStringContainsString(
            'Target database exists. Restore into a new name, or send overwrite confirm OVERWRITE.',
            (string) $json['error']
        );
    }

    public function test_a_new_database_is_allowed(): void
    {
        // The fake answers every SHOW DATABASES the same way, so "does not exist"
        // is expressed by returning nothing — as in KernelPhase2Test.
        [$code, $json] = $this->check(new FakeRuntime(), ['db'], ['target' => 'projob_restore_1']);

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['allowed']);
    }

    public function test_overwrite_still_needs_the_typed_confirmation(): void
    {
        $rt = new FakeRuntime();
        $rt->dbRows = [['Database' => 'projob']];

        [$code] = $this->check($rt, ['db'], ['target' => 'projob', 'overwrite' => true]);
        $this->assertNotSame(0, $code);

        [$ok] = $this->check($rt, ['db'], ['target' => 'projob', 'overwrite' => true, 'confirm' => 'OVERWRITE']);
        $this->assertSame(0, $ok);
    }

    /** A refusal must not depend on the operator having typed a passphrase yet. */
    public function test_no_passphrase_is_required(): void
    {
        $rt = new FakeRuntime();
        $rt->dbRows = [['Database' => 'projob']];

        [$code, $json] = $this->check($rt, ['db'], ['target' => 'projob']);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('assphrase', (string) $json['error']);
    }

    // ------------------------------------------------------------------ files

    public function test_a_read_only_vhost_is_refused_with_the_restore_wording(): void
    {
        [$code, $json] = $this->check(
            new FakeRuntime(),
            ['files'],
            ['site' => 'projob.az', 'apply' => true],
            $this->protectedConfig()
        );

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString(
            'Refusing to restore over a read-only vhost without force + confirm PROJOB.AZ.',
            (string) $json['error']
        );
    }

    public function test_force_with_the_typed_confirmation_is_allowed(): void
    {
        [$code, $json] = $this->check(
            new FakeRuntime(),
            ['files'],
            ['site' => 'projob.az', 'apply' => true, 'force' => true, 'confirm' => 'PROJOB.AZ'],
            $this->protectedConfig()
        );

        $this->assertSame(0, $code);
        $this->assertTrue($json['data']['allowed']);
    }

    public function test_an_unprotected_site_is_allowed(): void
    {
        [$code] = $this->check(
            new FakeRuntime(),
            ['files'],
            ['site' => 'shop.example.com', 'apply' => true],
            $this->protectedConfig()
        );

        $this->assertSame(0, $code);
    }

    /** Staging an archive to read its listing changes nothing, so it is not guarded. */
    public function test_a_preview_of_a_protected_site_is_not_refused(): void
    {
        [$code] = $this->check(
            new FakeRuntime(),
            ['files'],
            ['site' => 'projob.az', 'apply' => false],
            $this->protectedConfig()
        );

        $this->assertSame(0, $code);
    }

    // ----------------------------------------------------------- cheapness

    /**
     * The whole reason this action exists is that it is cheap enough to run inside
     * the request. If it ever starts fetching, decrypting or writing, it is no
     * longer a preflight.
     */
    public function test_the_preflight_touches_no_archive_and_writes_nothing(): void
    {
        $rt = new FakeRuntime();

        $this->check($rt, ['db'], ['target' => 'projob_restore_1']);
        $this->check($rt, ['files'], ['site' => 'projob.az', 'apply' => true, 'force' => true, 'confirm' => 'PROJOB.AZ'], $this->protectedConfig());

        $this->assertSame([], $this->spaces->requests, 'no object may be fetched');
        // The audit log is the one permitted write: every broker call is recorded,
        // and a refusal is exactly the kind of thing worth having a record of.
        $this->assertSame(
            ['/var/log/azerioid-panel/broker-audit.log'],
            array_keys($rt->files),
            'the preflight may write nothing but its audit entry'
        );
        // FakeRuntime seeds the standard tree, so the claim to pin is the absence
        // of a *staging* directory: that is what a real restore creates first.
        $staging = array_filter(array_keys($rt->dirs), static fn (string $d): bool => str_contains($d, '/restore-'));
        $this->assertSame([], array_values($staging), 'no staging directory may be created');
        $tools = array_map(static fn (array $row): string => basename((string) ($row['command'][0] ?? '')), $rt->execLog);
        foreach (['tar', 'mv', 'mysql', 'mariadb', 'psql', 'pg_restore', 'mongorestore'] as $forbidden) {
            $this->assertNotContains($forbidden, $tools, $forbidden . ' must not run during a preflight');
        }
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        [$code, $json] = $this->check(new FakeRuntime(), ['sideways'], []);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('kind=db or kind=files', (string) $json['error']);
    }
}
