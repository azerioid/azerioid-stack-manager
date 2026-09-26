<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\ArchiveCrypto;
use AzerioidPanel\Broker\Actions\BackupList;
use AzerioidPanel\Broker\Backup\ArchiveCipher;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A2.5 — nothing previously proved a stored backup could actually be used. These
 * cover `backup.verify` plus the listing's format reporting.
 */
final class BackupVerifyTest extends TestCase
{
    private const PASS = 'abcdefghijklmnopqrst';

    private FakeRuntime $rt;

    private Config $cfg;

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->rt->dirs[$this->cfg->localBackupDir] = true;
        $this->rt->dirs[$this->cfg->localBackupDir . '/db'] = true;
        $this->rt->dirs[$this->cfg->localBackupDir . '/db/all'] = true;
        $this->rt->dirs[$this->cfg->localBackupDir . '/files'] = true;
        $this->rt->dirs[$this->cfg->localBackupDir . '/files/shop'] = true;
    }

    /** @return array{0:int,1:array} */
    private function broker(array $argv, array $stdin = []): array
    {
        ob_start();
        $code = (new Kernel($this->cfg, $this->rt))->run($argv, $stdin);
        $out = (string) ob_get_clean();
        $json = json_decode(trim($out), true);

        return [$code, is_array($json) ? $json : []];
    }

    private function storeLocal(string $relative, string $blob): string
    {
        $path = $this->cfg->localBackupDir . '/' . $relative;
        $this->rt->files[$path] = $blob;

        return $path;
    }

    /** Minimal real gzipped ustar archive. */
    private static function tgz(): string
    {
        $put = static fn (string $b, int $o, string $v): string => substr_replace($b, $v, $o, strlen($v));
        $h = str_repeat("\0", 512);
        $h = $put($h, 0, 'shop/index.php');
        $h = $put($h, 100, sprintf('%07o', 0644) . "\0");
        $h = $put($h, 108, sprintf('%07o', 0) . "\0");
        $h = $put($h, 116, sprintf('%07o', 0) . "\0");
        $h = $put($h, 124, sprintf('%011o', 6) . "\0");
        $h = $put($h, 136, sprintf('%011o', 1790000000) . "\0");
        $h = $put($h, 156, '0');
        $h = $put($h, 257, "ustar\0" . '00');
        $h = $put($h, 148, str_repeat(' ', 8));
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        $h = $put($h, 148, sprintf('%06o', $sum) . "\0 ");

        return (string) gzencode($h . str_pad("<?php\n", 512, "\0") . str_repeat("\0", 1024));
    }

    // ------------------------------------------------------------------ verify

    public function test_verifies_an_authenticated_sql_backup(): void
    {
        $path = $this->storeLocal(
            'db/all/20260926T000000Z.lacmp2.bin',
            ArchiveCipher::encryptBlob("-- dump\nCREATE TABLE t (id int);\n", self::PASS)
        );

        [$code, $json] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => self::PASS]
        );

        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertTrue($json['data']['restorable']);
        $this->assertTrue($json['data']['authenticated']);
        $this->assertSame('lacmp2', $json['data']['format']);
        $this->assertSame('db', $json['data']['kind']);
        $this->assertSame('sql-text', $json['data']['structure']['checked']);
    }

    public function test_verifies_a_files_backup_by_walking_the_tar(): void
    {
        $path = $this->storeLocal(
            'files/shop/20260926T000000Z.lacmp2.bin',
            ArchiveCipher::encryptBlob(self::tgz(), self::PASS)
        );

        [$code, $json] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => self::PASS]
        );

        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertSame('tar', $json['data']['structure']['checked']);
        $this->assertSame(1, $json['data']['structure']['entries']);
    }

    public function test_a_tampered_archive_fails_verification(): void
    {
        $blob = ArchiveCipher::encryptBlob("-- dump\nCREATE TABLE t (id int);\n", self::PASS);
        $blob[60] = chr(ord($blob[60]) ^ 0x01);
        $path = $this->storeLocal('db/all/20260926T000000Z.lacmp2.bin', $blob);

        [$code, $json] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => self::PASS]
        );

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('authentication', (string) $json['error']);
    }

    public function test_a_wrong_passphrase_fails_verification(): void
    {
        $path = $this->storeLocal(
            'db/all/20260926T000000Z.lacmp2.bin',
            ArchiveCipher::encryptBlob('-- dump', self::PASS)
        );

        [$code] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => 'wrongpassphrasevalue']
        );

        $this->assertNotSame(0, $code);
    }

    /** A legacy archive verifies structurally but must be reported unauthenticated. */
    public function test_legacy_archive_is_reported_as_unauthenticated(): void
    {
        $path = $this->storeLocal(
            'db/all/20260101T000000Z.bin',
            ArchiveCrypto::encrypt("-- dump\nCREATE TABLE t (id int);\n", self::PASS)
        );

        [$code, $json] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => self::PASS]
        );

        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertSame('legacy', $json['data']['format']);
        $this->assertFalse($json['data']['authenticated']);
        $this->assertStringContainsString('no authentication', $json['data']['note']);
    }

    public function test_intact_bytes_that_are_not_a_dump_are_refused(): void
    {
        $path = $this->storeLocal(
            'db/all/20260926T000000Z.lacmp2.bin',
            ArchiveCipher::encryptBlob(str_repeat("\x7f", 5000), self::PASS)
        );

        [$code, $json] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => self::PASS]
        );

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('does not look like a database dump', (string) $json['error']);
    }

    public function test_a_files_backup_containing_an_unsafe_entry_is_flagged_before_restore(): void
    {
        // Same guard restore uses, so a backup that would be refused later fails now.
        $put = static fn (string $b, int $o, string $v): string => substr_replace($b, $v, $o, strlen($v));
        $h = str_repeat("\0", 512);
        $h = $put($h, 0, 'shop/rootshell');
        $h = $put($h, 100, sprintf('%07o', 04755) . "\0");
        $h = $put($h, 124, sprintf('%011o', 0) . "\0");
        $h = $put($h, 136, sprintf('%011o', 1790000000) . "\0");
        $h = $put($h, 156, '0');
        $h = $put($h, 257, "ustar\0" . '00');
        $h = $put($h, 148, str_repeat(' ', 8));
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        $h = $put($h, 148, sprintf('%06o', $sum) . "\0 ");
        $tgz = (string) gzencode($h . str_repeat("\0", 1024));

        $path = $this->storeLocal(
            'files/shop/20260926T000000Z.lacmp2.bin',
            ArchiveCipher::encryptBlob($tgz, self::PASS)
        );

        [$code, $json] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => self::PASS]
        );

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('setuid', (string) $json['error']);
    }

    // -------------------------------------------------------- listing metadata

    public function test_listing_reports_format_without_reading_archives(): void
    {
        $this->storeLocal('db/all/20260926T000000Z.lacmp2.bin', 'ignored');
        $this->storeLocal('db/all/20260101T000000Z.bin', 'ignored');

        [$code, $json] = $this->broker(['broker', 'backup.list'], ['destination' => 'local']);
        $this->assertSame(0, $code);

        $byKey = [];
        foreach ($json['data']['objects'] as $obj) {
            $byKey[basename((string) $obj['key'])] = $obj;
        }

        $this->assertSame('lacmp2', $byKey['20260926T000000Z.lacmp2.bin']['format']);
        $this->assertFalse($byKey['20260926T000000Z.lacmp2.bin']['legacy']);
        $this->assertSame('legacy', $byKey['20260101T000000Z.bin']['format']);
        $this->assertTrue($byKey['20260101T000000Z.bin']['legacy']);
    }

    public function test_format_marker_does_not_break_timestamp_parsing(): void
    {
        $this->storeLocal('db/all/20260926T123456Z.lacmp2.bin', 'ignored');

        [, $json] = $this->broker(['broker', 'backup.list'], ['destination' => 'local']);

        $this->assertSame('2026-09-26T12:34:56Z', $json['data']['objects'][0]['last_modified']);
    }

    // ------------------------------------------- pre-restore snapshot retention

    /**
     * Every restore used to leave another full copy of the site beside it, with
     * nothing ever removing them, so the disk filled one restore at a time.
     */
    public function test_restore_prunes_old_pre_restore_snapshots(): void
    {
        $www = rtrim($this->cfg->wwwRoot, '/');
        $this->rt->dirs[$www] = true;
        $this->rt->dirs[$www . '/shop'] = true;
        foreach (['20260101T000000Z', '20260102T000000Z', '20260103T000000Z', '20260104T000000Z'] as $stamp) {
            $this->rt->dirs[$www . '/shop.lacmp-pre-restore-' . $stamp] = true;
        }
        $this->rt->dirs['/var/lib/azerioid-panel/staging/restore-shop/shop'] = true;
        $this->storeLocal('files/shop/20260926T000000Z.lacmp2.bin', ArchiveCipher::encryptBlob(self::tgz(), self::PASS));

        [$code, $json] = $this->broker(
            ['broker', 'backup.restore.files', $this->cfg->localBackupDir . '/files/shop/20260926T000000Z.lacmp2.bin'],
            ['destination' => 'local', 'passphrase' => self::PASS, 'site' => 'shop', 'apply' => true]
        );

        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertSame(2, $json['data']['snapshots_kept']);

        // Newest two kept; the two oldest removed via rm -rf.
        $removed = [];
        foreach ($this->rt->execLog as $row) {
            if (($row['command'][0] ?? '') === '/bin/rm') {
                $removed[] = basename((string) end($row['command']));
            }
        }
        sort($removed);
        $this->assertSame(
            ['shop.lacmp-pre-restore-20260101T000000Z', 'shop.lacmp-pre-restore-20260102T000000Z'],
            $removed,
            'the two oldest snapshots must be the ones removed'
        );
    }

    public function test_snapshot_retention_is_configurable_and_bounded(): void
    {
        $www = rtrim($this->cfg->wwwRoot, '/');
        $this->rt->dirs[$www] = true;
        $this->rt->dirs[$www . '/shop'] = true;
        $this->rt->dirs[$www . '/shop.lacmp-pre-restore-20260101T000000Z'] = true;
        $this->rt->dirs['/var/lib/azerioid-panel/staging/restore-shop/shop'] = true;
        $this->storeLocal('files/shop/20260926T000000Z.lacmp2.bin', ArchiveCipher::encryptBlob(self::tgz(), self::PASS));

        [, $json] = $this->broker(
            ['broker', 'backup.restore.files', $this->cfg->localBackupDir . '/files/shop/20260926T000000Z.lacmp2.bin'],
            [
                'destination' => 'local',
                'passphrase' => self::PASS,
                'site' => 'shop',
                'apply' => true,
                'keep_snapshots' => 999,
            ]
        );

        $this->assertSame(20, $json['data']['snapshots_kept'], 'clamped to a sane ceiling');
    }

    public function test_format_of_classifies_keys(): void
    {
        $this->assertSame('lacmp2', BackupList::formatOf('azerioid/db/all/20260926T000000Z.lacmp2.bin'));
        $this->assertSame('legacy', BackupList::formatOf('azerioid/db/all/20260101T000000Z.bin'));
    }

    public function test_unknown_blob_is_refused(): void
    {
        $path = $this->storeLocal('db/all/20260926T000000Z.lacmp2.bin', 'not an archive at all');

        [$code, $json] = $this->broker(
            ['broker', 'backup.verify', $path],
            ['destination' => 'local', 'passphrase' => self::PASS]
        );

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not a recognised', strtolower((string) $json['error']));
    }
}
