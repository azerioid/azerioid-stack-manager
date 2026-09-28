<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Actions\BackupPrune;
use AzerioidPanel\Broker\Backup\ArchiveCipher;
use AzerioidPanel\Broker\Backup\ScratchVerifier;
use AzerioidPanel\Broker\Backup\VhostBundle;
use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\FakeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * B6 / ADR A52: vhost bundles (manifest + parts), age-based retention, restore verification.
 */
final class VhostBundleTest extends TestCase
{
    private const DOMAIN = 'shop.test';
    private const PASS = 'correct horse battery staple';

    private FakeRuntime $rt;
    private Config $cfg;

    /** @var list<list<string>> */
    private array $failCommands = [];

    protected function setUp(): void
    {
        $this->rt = new FakeRuntime();
        $this->cfg = new Config();
        $this->cfg->localBackupDir = '/var/lib/azerioid-panel/backups';
        $this->cfg->stagingDir = '/var/lib/azerioid-panel/staging';
        $this->rt->files['/etc/caddy/conf.d/shop.test.conf'] = "# azerioid-managed engine=caddy type=php php=8.4 root=/data/www/shop.test/public\nshop.test {\n    root * /data/www/shop.test/public\n}\n";
        $this->rt->dirs['/data/www/shop.test'] = true;
        $this->rt->dirs['/data/www/shop.test/public'] = true;
        $this->rt->execFn = function (array $c, ?string $stdin): ?ExecResult {
            foreach ($this->failCommands as $bad) {
                if (array_slice($c, 0, count($bad)) === $bad) {
                    return new ExecResult($c, 1, '', 'boom');
                }
            }

            return match ($c[0] ?? '') {
                '/usr/bin/tar' => in_array('-czf', $c, true) ? new ExecResult($c, 0, 'TAR:' . implode(' ', $c), '') : null,
                '/usr/bin/mysqldump' => new ExecResult($c, 0, "CREATE TABLE t (id int);\n", ''),
                default => null,
            };
        };
    }

    public function test_databases_that_belong_to_a_site_are_recorded_and_validated(): void
    {
        $bundle = new VhostBundle($this->cfg, $this->rt);
        $bundle->saveSettings(self::DOMAIN, ['databases' => [['engine' => 'mariadb', 'name' => 'shop'], ['engine' => 'mariadb', 'name' => 'shop']]]);

        $this->assertSame(['databases' => [['engine' => 'mariadb', 'name' => 'shop']]], $bundle->settings(self::DOMAIN));

        $this->expectException(BrokerException::class);
        $bundle->saveSettings(self::DOMAIN, ['databases' => [['engine' => 'oracle', 'name' => 'x']]]);
    }

    public function test_a_bundle_is_a_manifest_and_one_encrypted_part_per_piece(): void
    {
        $bundle = new VhostBundle($this->cfg, $this->rt);
        $bundle->saveSettings(self::DOMAIN, ['databases' => [['engine' => 'mariadb', 'name' => 'shop']]]);

        $out = $bundle->run(self::DOMAIN, ['passphrase' => self::PASS, 'destination' => 'local']);

        $dir = '/var/lib/azerioid-panel/backups/vhost/shop.test/' . $out['bundle'];
        foreach (['manifest', 'files', 'config', 'db-mariadb-shop'] as $part) {
            $this->assertArrayHasKey("{$dir}/{$part}.lacmp2.bin", $this->rt->files, $part);
        }
        $manifest = json_decode(ArchiveCipher::decryptBlob($this->rt->files["{$dir}/manifest.lacmp2.bin"], self::PASS), true);
        $this->assertSame('shop.test', $manifest['domain']);
        $this->assertSame('/data/www/shop.test', $manifest['top'], 'the whole site, not only public/');
        $this->assertSame(['files', 'config', 'db-mariadb-shop'], array_column($manifest['parts'], 'part'));
        $this->assertContains('tls-private-keys', $manifest['not_included']);
        $files = ArchiveCipher::decryptBlob($this->rt->files["{$dir}/files.lacmp2.bin"], self::PASS);
        $this->assertStringContainsString('-C /data/www -czf - --exclude=shop.test/node_modules --exclude=shop.test/storage/logs shop.test', $files);
        $this->assertNotEmpty(array_filter($this->rt->execLog, static fn (array $e): bool => ($e['command'][0] ?? '') === '/bin/rm'
            && str_contains((string) end($e['command']), '/staging/bundle-')), 'the config staging directory is removed');
    }

    public function test_a_failed_part_leaves_no_half_bundle(): void
    {
        $bundle = new VhostBundle($this->cfg, $this->rt);
        $bundle->saveSettings(self::DOMAIN, ['databases' => [['engine' => 'mariadb', 'name' => 'shop']]]);
        $this->failCommands[] = ['/usr/bin/mysqldump'];

        try {
            $bundle->run(self::DOMAIN, ['passphrase' => self::PASS, 'destination' => 'local']);
            $this->fail('expected failure');
        } catch (BrokerException) {
        }

        $this->assertSame([], array_filter(array_keys($this->rt->files), static fn (string $k): bool => str_contains($k, '/backups/vhost/')));
    }

    public function test_list_reads_names_only_and_marks_complete_bundles(): void
    {
        $bundle = new VhostBundle($this->cfg, $this->rt);
        $out = $bundle->run(self::DOMAIN, ['passphrase' => self::PASS, 'destination' => 'local']);

        $list = $bundle->list(['destination' => 'local'])['bundles'];

        $this->assertCount(1, $list);
        $this->assertSame($out['bundle'], $list[0]['bundle']);
        $this->assertTrue($list[0]['complete']);
        $this->assertSame(['config', 'files', 'manifest'], $list[0]['parts']);
    }

    public function test_restore_previews_without_apply_and_needs_the_typed_domain(): void
    {
        $bundle = new VhostBundle($this->cfg, $this->rt);
        $out = $bundle->run(self::DOMAIN, ['passphrase' => self::PASS, 'destination' => 'local']);

        $preview = $bundle->restore(self::DOMAIN, ['passphrase' => self::PASS, 'destination' => 'local', 'bundle' => $out['bundle']]);
        $this->assertFalse($preview['applied']);
        $this->assertSame(['files', 'config'], $preview['would_restore']);

        $this->expectException(BrokerException::class);
        $bundle->restore(self::DOMAIN, ['passphrase' => self::PASS, 'destination' => 'local', 'bundle' => $out['bundle'], 'apply' => true, 'confirm' => 'yes']);
    }

    public function test_a_bundle_cannot_be_restored_into_another_domain(): void
    {
        $bundle = new VhostBundle($this->cfg, $this->rt);
        $out = $bundle->run(self::DOMAIN, ['passphrase' => self::PASS, 'destination' => 'local']);
        $this->rt->files['/var/lib/azerioid-panel/backups/vhost/other.test/' . $out['bundle'] . '/manifest.lacmp2.bin']
            = $this->rt->files['/var/lib/azerioid-panel/backups/vhost/shop.test/' . $out['bundle'] . '/manifest.lacmp2.bin'];

        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage('another domain is not supported');
        $bundle->restore('other.test', ['passphrase' => self::PASS, 'destination' => 'local', 'bundle' => $out['bundle']]);
    }

    public function test_age_retention_removes_whole_old_bundles_but_keeps_the_newest(): void
    {
        $base = '/var/lib/azerioid-panel/backups/vhost/shop.test';
        $this->rt->dirs['/var/lib/azerioid-panel/backups/vhost'] = true;
        $this->rt->dirs[$base] = true;
        foreach (['20260101T030000Z', '20260201T030000Z', '20260920T030000Z'] as $stamp) {
            $this->rt->dirs[$base . '/' . $stamp] = true;
            foreach (['manifest', 'files'] as $part) {
                $this->rt->files["{$base}/{$stamp}/{$part}.lacmp2.bin"] = 'x';
            }
        }
        $this->rt->clock = '2026-09-28T12:00:00+00:00';

        $removed = (new VhostBundle($this->cfg, $this->rt))->pruneAge(['destination' => 'local'], 30, 1);

        $this->assertSame(['shop.test/20260201T030000Z', 'shop.test/20260101T030000Z'], $removed);
        $this->assertArrayHasKey($base . '/20260920T030000Z/files.lacmp2.bin', $this->rt->files);
        $this->assertArrayNotHasKey($base . '/20260101T030000Z/files.lacmp2.bin', $this->rt->files);

        $this->rt->clock = '2027-09-28T12:00:00+00:00';
        $this->assertSame([], (new VhostBundle($this->cfg, $this->rt))->pruneAge(['destination' => 'local'], 30, 1), 'the last bundle survives, however old');
    }

    public function test_count_based_prune_never_touches_bundle_parts(): void
    {
        $base = '/var/lib/azerioid-panel/backups';
        $this->rt->dirs[$base] = true;
        $this->rt->dirs[$base . '/vhost'] = true;
        $this->rt->dirs[$base . '/vhost/shop.test'] = true;
        $this->rt->dirs[$base . '/vhost/shop.test/20260101T030000Z'] = true;
        $this->rt->files[$base . '/vhost/shop.test/20260101T030000Z/files.lacmp2.bin'] = 'x';

        (new BackupPrune())->handle('backup.prune', [], ['destination' => 'local', 'keep' => 1], $this->rt, $this->cfg);

        $this->assertArrayHasKey($base . '/vhost/shop.test/20260101T030000Z/files.lacmp2.bin', $this->rt->files);
    }

    // ------------------------------------------------------ restore verification

    public function test_whole_server_dumps_are_never_restored_for_verification(): void
    {
        $out = (new ScratchVerifier($this->cfg, $this->rt))->verify("CREATE DATABASE live;\n", 'all', 'mariadb');

        $this->assertSame('skipped', $out['checked']);
        $this->assertSame([], $this->rt->dbExecLog);
    }

    public function test_a_dump_that_names_its_own_database_is_refused(): void
    {
        $this->expectException(BrokerException::class);
        $this->expectExceptionMessage('names a database of its own');
        (new ScratchVerifier($this->cfg, $this->rt))->verify("USE live_db;\nDROP TABLE users;\n", 'shop', 'mariadb');
    }

    public function test_a_dump_restores_into_a_scratch_database_that_is_always_dropped(): void
    {
        $this->rt->dbQueryFn = static fn (string $sql): array => str_contains($sql, 'information_schema.tables') ? [['n' => 3]] : [];

        $out = (new ScratchVerifier($this->cfg, $this->rt))->verify("CREATE TABLE t (id int);\n", 'shop', 'mariadb');

        $this->assertSame('scratch-restore', $out['checked']);
        $this->assertSame(3, $out['objects']);
        $sql = implode("\n", array_map(static fn ($e) => is_array($e) ? (string) ($e['sql'] ?? $e[0] ?? '') : (string) $e, $this->rt->dbExecLog));
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `' . $out['scratch'] . '`', $sql);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `' . $out['scratch'] . '`', $sql);
        $this->assertStringStartsWith('azv_verify_', $out['scratch']);
    }

    public function test_the_scratch_database_is_dropped_even_when_the_restore_fails(): void
    {
        $this->failCommands[] = ['/usr/bin/mysql'];

        try {
            (new ScratchVerifier($this->cfg, $this->rt))->verify("CREATE TABLE t (id int);\n", 'shop', 'mariadb');
            $this->fail('expected failure');
        } catch (BrokerException) {
        }

        $sql = implode("\n", array_map(static fn ($e) => is_array($e) ? (string) ($e['sql'] ?? $e[0] ?? '') : (string) $e, $this->rt->dbExecLog));
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `azv_verify_', $sql);
    }
}
