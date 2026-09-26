<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Panel\PanelDbSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * A3 / R4 — self-update rolled the code back but never the schema, because the
 * rollback path re-ran `artisan migrate`, which is forward-only.
 */
final class PanelDbSnapshotTest extends TestCase
{
    private FakeRuntime $rt;

    private Config $cfg;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->rt->dirs['/var/lib/azerioid-panel'] = true;
        $this->rt->files[$this->cfg->panelDbPath] = 'ORIGINAL-DB';
        $this->rt->files['/usr/bin/sqlite3'] = '';
    }

    /** FakeRuntime does not run sqlite3, so stand in for what .backup would write. */
    private function scriptBackup(string $contents = 'SNAPSHOT-DB'): void
    {
        $files = &$this->rt->files;
        $this->rt->defaultExec = new ExecResult(['sqlite3'], 0, '', '');
        $this->rt->execHook = static function (array $cmd) use (&$files, $contents): void {
            if (($cmd[0] ?? '') === '/usr/bin/sqlite3' && str_starts_with((string) ($cmd[2] ?? ''), '.backup')) {
                if (preg_match("/^\.backup '(.+)'$/", (string) $cmd[2], $m) === 1) {
                    $files[$m[1]] = $contents;
                }
            }
        };
    }

    // ------------------------------------------------------------------ create

    public function test_uses_the_sqlite_online_backup_api_not_a_file_copy(): void
    {
        $this->scriptBackup();

        $path = (new PanelDbSnapshot($this->rt, $this->cfg))->create('op-1');

        $this->assertNotNull($path);
        $calls = array_values(array_filter(
            $this->rt->execLog,
            static fn ($r) => ($r['command'][0] ?? '') === '/usr/bin/sqlite3'
        ));
        $this->assertCount(1, $calls);
        $this->assertSame($this->cfg->panelDbPath, $calls[0]['command'][1]);
        $this->assertStringStartsWith('.backup ', (string) $calls[0]['command'][2]);
    }

    public function test_snapshot_lands_in_the_snapshot_directory_with_the_operation_id(): void
    {
        $this->scriptBackup();

        $path = (new PanelDbSnapshot($this->rt, $this->cfg))->create('op-42');

        $this->assertStringStartsWith($this->cfg->panelDbSnapshotDir . '/', (string) $path);
        $this->assertStringEndsWith('-op-42.sqlite', (string) $path);
        $this->assertSame('SNAPSHOT-DB', $this->rt->files[$path]);
    }

    /** Sessions, encrypted secrets and the audit trail live in this file. */
    public function test_snapshot_is_not_readable_by_other_users(): void
    {
        $this->scriptBackup();

        $path = (new PanelDbSnapshot($this->rt, $this->cfg))->create('op-1');

        $this->assertSame(0600, $this->rt->modes[$path] ?? null);
    }

    public function test_skips_when_there_is_no_database_yet(): void
    {
        unset($this->rt->files[$this->cfg->panelDbPath]);

        $this->assertNull((new PanelDbSnapshot($this->rt, $this->cfg))->create('op-1'));
    }

    /**
     * Falling back to a plain copy would be worse than refusing: its consistency
     * is not guaranteed for a WAL database, and an operator who believes they have
     * a rollback point and does not is worse off than one told to install sqlite3.
     */
    public function test_refuses_rather_than_falling_back_to_a_copy_without_sqlite3(): void
    {
        unset($this->rt->files['/usr/bin/sqlite3']);

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/sqlite3 is not installed/');
        (new PanelDbSnapshot($this->rt, $this->cfg))->create('op-1');
    }

    public function test_fails_loudly_when_backup_produces_nothing(): void
    {
        $this->rt->defaultExec = new ExecResult(['sqlite3'], 1, '', 'disk I/O error');

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/snapshot of the panel database failed.*disk I\/O error/');
        (new PanelDbSnapshot($this->rt, $this->cfg))->create('op-1');
    }

    // ----------------------------------------------------------------- restore

    public function test_restore_puts_the_snapshot_back(): void
    {
        $snap = $this->cfg->panelDbSnapshotDir . '/20260926T000000Z-op-1.sqlite';
        $this->rt->dirs[$this->cfg->panelDbSnapshotDir] = true;
        $this->rt->files[$snap] = 'SNAPSHOT-DB';
        $this->rt->files[$this->cfg->panelDbPath] = 'MIGRATED-DB';

        (new PanelDbSnapshot($this->rt, $this->cfg))->restore($snap);

        $this->assertSame('SNAPSHOT-DB', $this->rt->files[$this->cfg->panelDbPath]);
    }

    /**
     * Leaving the WAL behind would let SQLite replay the newer log over the
     * restored file, quietly undoing the rollback.
     */
    public function test_restore_removes_the_stale_wal_and_shm(): void
    {
        $snap = $this->cfg->panelDbSnapshotDir . '/20260926T000000Z-op-1.sqlite';
        $this->rt->dirs[$this->cfg->panelDbSnapshotDir] = true;
        $this->rt->files[$snap] = 'SNAPSHOT-DB';
        $this->rt->files[$this->cfg->panelDbPath . '-wal'] = 'newer log';
        $this->rt->files[$this->cfg->panelDbPath . '-shm'] = 'shared mem';

        (new PanelDbSnapshot($this->rt, $this->cfg))->restore($snap);

        $this->assertArrayNotHasKey($this->cfg->panelDbPath . '-wal', $this->rt->files);
        $this->assertArrayNotHasKey($this->cfg->panelDbPath . '-shm', $this->rt->files);
    }

    public function test_restore_hands_ownership_back_to_the_panel_user(): void
    {
        $snap = $this->cfg->panelDbSnapshotDir . '/20260926T000000Z-op-1.sqlite';
        $this->rt->dirs[$this->cfg->panelDbSnapshotDir] = true;
        $this->rt->files[$snap] = 'SNAPSHOT-DB';

        (new PanelDbSnapshot($this->rt, $this->cfg))->restore($snap);

        $chown = array_values(array_filter(
            $this->rt->execLog,
            static fn ($r) => ($r['command'][0] ?? '') === '/usr/bin/chown'
        ));
        $this->assertNotSame([], $chown, 'the FPM/queue user must still be able to open the database');
        $this->assertSame($this->cfg->panelDbPath, end($chown[0]['command']));
    }

    public function test_restore_refuses_a_missing_snapshot(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessageMatches('/snapshot is missing/');
        (new PanelDbSnapshot($this->rt, $this->cfg))->restore('/var/lib/azerioid-panel/db-snapshots/gone.sqlite');
    }

    // ------------------------------------------------------------------- prune

    public function test_prune_keeps_the_newest_and_removes_the_rest(): void
    {
        $dir = $this->cfg->panelDbSnapshotDir;
        $this->rt->dirs[$dir] = true;
        $stamps = [
            '20260101T000000Z-op-1',
            '20260102T000000Z-op-2',
            '20260103T000000Z-op-3',
            '20260104T000000Z-op-4',
            '20260105T000000Z-op-5',
            '20260106T000000Z-op-6',
            '20260107T000000Z-op-7',
        ];
        foreach ($stamps as $s) {
            $this->rt->files[$dir . '/' . $s . '.sqlite'] = 'x';
        }

        $removed = (new PanelDbSnapshot($this->rt, $this->cfg))->prune();

        $this->assertCount(2, $removed, '7 snapshots, KEEP = 5');
        $this->assertContains($dir . '/20260101T000000Z-op-1.sqlite', $removed);
        $this->assertContains($dir . '/20260102T000000Z-op-2.sqlite', $removed);
        $this->assertArrayHasKey($dir . '/20260107T000000Z-op-7.sqlite', $this->rt->files);
    }

    public function test_prune_ignores_unrelated_files(): void
    {
        $dir = $this->cfg->panelDbSnapshotDir;
        $this->rt->dirs[$dir] = true;
        $this->rt->files[$dir . '/README.txt'] = 'not a snapshot';
        $this->rt->files[$dir . '/20260101T000000Z-op-1.sqlite'] = 'x';

        $removed = (new PanelDbSnapshot($this->rt, $this->cfg))->prune(0);

        $this->assertSame([$dir . '/20260101T000000Z-op-1.sqlite'], $removed);
        $this->assertArrayHasKey($dir . '/README.txt', $this->rt->files);
    }

    public function test_prune_is_a_noop_without_a_snapshot_directory(): void
    {
        $this->assertSame([], (new PanelDbSnapshot($this->rt, $this->cfg))->prune());
    }
}
