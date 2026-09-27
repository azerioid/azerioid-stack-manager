<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Cron\CronRenderer;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * B2 / request #4 — structured cron jobs.
 *
 * What is being replaced: a textarea holding the whole root crontab. Every job ran as
 * root, saving replaced the entire file, and two operators editing at once lost one
 * set of changes silently. The tests that matter are therefore about identity, about
 * what happens to lines the panel did not write, and about a job's output being
 * findable afterwards.
 */
final class CronJobsTest extends TestCase
{
    /** @var array<string,string> crontab body handed to `crontab -u <user> <file>` */
    private array $installedCrontabs = [];

    private function runtime(): FakeRuntime
    {
        $this->installedCrontabs = [];
        $rt = new FakeRuntime();
        // The staging file is deleted right after install, so capture what cron was
        // actually given rather than what is left on disk.
        $rt->execHook = function (array $command) use (&$rt): void {
            if (($command[0] ?? '') === '/usr/bin/crontab' && isset($command[3])) {
                $this->installedCrontabs[(string) $command[2]] = $rt->files[$command[3]] ?? '';
            }
        };
        $rt->files['/etc/passwd'] = "root:x:0:0:root:/root:/bin/bash\naz-vh-shop-example-com:x:1001:1001::/data/www/shop.example.com:/bin/bash\n";
        $rt->files['/sbin/runuser'] = '';
        // A host that already has root cron jobs of its own.
        $rt->script(['/usr/bin/crontab', '-u', 'root', '-l'], 0, "# provider image\n0 4 * * * /usr/local/bin/nightly-backup.sh\n");
        $rt->script(['/usr/bin/crontab', '-u', 'az-vh-shop-example-com', '-l'], 1, '', 'no crontab for az-vh-shop-example-com');

        return $rt;
    }

    /** @return array{0:int,1:array} */
    private function call(FakeRuntime $rt, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = (new Kernel(new Config(), $rt))->run(array_merge(['broker'], $argv), $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    private function installed(FakeRuntime $rt, string $user): string
    {
        return $this->installedCrontabs[$user] ?? '';
    }

    // -------------------------------------------------------------- identity

    public function test_a_site_job_runs_as_the_sites_own_identity_not_root(): void
    {
        $rt = $this->runtime();

        [$code, $json] = $this->call($rt, ['cron.job.add'], [
            'owner' => 'shop.example.com',
            'schedule' => '*/5 * * * *',
            'command' => '/usr/bin/php /data/www/shop.example.com/artisan schedule:run',
        ]);

        $this->assertSame(0, $code);
        $this->assertSame('az-vh-shop-example-com', $json['data']['job']['runs_as']);
        $commands = array_column($rt->execLog, 'command');
        $this->assertContains(['/usr/bin/crontab', '-u', 'az-vh-shop-example-com', '/var/lib/azerioid-panel/staging/crontab.az-vh-shop-example-com'], $commands);
        foreach ($commands as $command) {
            $this->assertNotSame('root', $command[2] ?? null, 'a site job must not touch the root crontab');
        }
    }

    public function test_a_root_job_needs_a_typed_confirmation_every_time(): void
    {
        $rt = $this->runtime();

        [$refused, $json] = $this->call($rt, ['cron.job.add'], [
            'owner' => 'root', 'schedule' => '@daily', 'command' => '/usr/local/bin/tidy.sh',
        ]);
        $this->assertNotSame(0, $refused);
        $this->assertStringContainsString('Confirmation', (string) $json['error']);

        [$allowed] = $this->call($rt, ['cron.job.add'], [
            'owner' => 'root', 'schedule' => '@daily', 'command' => '/usr/local/bin/tidy.sh',
            'confirm' => 'RUN-AS-ROOT',
        ]);
        $this->assertSame(0, $allowed);
    }

    public function test_a_job_for_a_vhost_with_no_system_identity_is_refused(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['cron.job.add'], [
            'owner' => 'missing.example.com', 'schedule' => '@daily', 'command' => '/bin/true',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('no system identity', (string) $json['error']);
    }

    // ------------------------------------------------- the operator's own lines

    public function test_existing_root_crontab_lines_are_preserved(): void
    {
        $rt = $this->runtime();

        $this->call($rt, ['cron.job.add'], [
            'owner' => 'root', 'schedule' => '@daily', 'command' => '/usr/local/bin/tidy.sh',
            'confirm' => 'RUN-AS-ROOT',
        ]);

        $body = $this->installed($rt, 'root');
        $this->assertStringContainsString('# provider image', $body);
        $this->assertStringContainsString('0 4 * * * /usr/local/bin/nightly-backup.sh', $body);
        $this->assertStringContainsString(CronRenderer::BEGIN, $body);
    }

    public function test_the_managed_block_is_replaced_not_appended_twice(): void
    {
        $renderer = new CronRenderer();
        $first = $renderer->render("0 4 * * * /keep.sh\n", [
            \AzerioidPanel\Broker\Cron\CronJob::fromState([
                'id' => 'job-aaaaaaaaaa', 'owner' => 'root', 'schedule' => '@daily',
                'command' => '/one.sh', 'enabled' => true,
            ]),
        ], '/wrap');

        $second = $renderer->render($first, [
            \AzerioidPanel\Broker\Cron\CronJob::fromState([
                'id' => 'job-bbbbbbbbbb', 'owner' => 'root', 'schedule' => '@daily',
                'command' => '/two.sh', 'enabled' => true,
            ]),
        ], '/wrap');

        $this->assertSame(1, substr_count($second, CronRenderer::BEGIN));
        $this->assertStringContainsString('/keep.sh', $second);
        $this->assertStringNotContainsString('/one.sh', $second);
        $this->assertStringContainsString('/two.sh', $second);
    }

    /** A truncated block must not swallow the rest of the operator's crontab. */
    public function test_a_block_missing_its_end_marker_does_not_eat_the_file(): void
    {
        $renderer = new CronRenderer();
        $broken = CronRenderer::BEGIN . "\n@daily /half-written.sh\n";

        $this->assertSame('', $renderer->withoutManagedBlock($broken));
        $this->assertStringNotContainsString('/half-written.sh', $renderer->render($broken, [], '/wrap'));
    }

    public function test_no_jobs_means_no_empty_block_left_behind(): void
    {
        $rendered = (new CronRenderer())->render("0 4 * * * /keep.sh\n", [], '/wrap');

        $this->assertStringNotContainsString(CronRenderer::BEGIN, $rendered);
        $this->assertStringContainsString('/keep.sh', $rendered);
    }

    public function test_listing_reports_the_operators_untouched_root_lines(): void
    {
        [, $json] = $this->call($this->runtime(), ['cron.jobs']);

        $this->assertContains('0 4 * * * /usr/local/bin/nightly-backup.sh', $json['data']['unmanaged_root_lines']);
    }

    // ------------------------------------------------------------- validation

    public function test_reboot_is_not_accepted_as_a_schedule(): void
    {
        [$code, $json] = $this->call($this->runtime(), ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '@reboot', 'command' => '/bin/true',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('startup hook', (string) $json['error']);
    }

    public function test_a_command_cannot_escape_its_own_crontab_line(): void
    {
        // An *embedded* null, not a trailing one: trim() already removes those, and a
        // command that merely ends in whitespace is not an attack.
        foreach (["/bin/true\n@daily /evil.sh", "/bin/true %rest", "/bin/\0true"] as $command) {
            [$code] = $this->call($this->runtime(), ['cron.job.add'], [
                'owner' => 'shop.example.com', 'schedule' => '@daily', 'command' => $command,
            ]);
            $this->assertNotSame(0, $code, $command);
        }
    }

    public function test_a_five_field_schedule_is_checked_field_by_field(): void
    {
        [$bad, $json] = $this->call($this->runtime(), ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '*/5 * * * MON;rm', 'command' => '/bin/true',
        ]);
        $this->assertNotSame(0, $bad);
        $this->assertStringContainsString('field 5', (string) $json['error']);

        [$good] = $this->call($this->runtime(), ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '*/5 1-5 * * 1,3', 'command' => '/bin/true',
        ]);
        $this->assertSame(0, $good);
    }

    // ------------------------------------------------------ enable / disable / run

    public function test_a_disabled_job_stays_visible_in_the_crontab_as_a_comment(): void
    {
        $rt = $this->runtime();
        [, $added] = $this->call($rt, ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '@hourly', 'command' => '/bin/true',
        ]);
        $id = $added['data']['job']['id'];

        [$code] = $this->call($rt, ['cron.job.disable', $id]);

        $this->assertSame(0, $code);
        $body = $this->installed($rt, 'az-vh-shop-example-com');
        $this->assertStringContainsString('# DISABLED', $body);
        $this->assertStringContainsString($id, $body);
    }

    public function test_re_enabling_a_root_job_needs_the_confirmation_again(): void
    {
        $rt = $this->runtime();
        [, $added] = $this->call($rt, ['cron.job.add'], [
            'owner' => 'root', 'schedule' => '@daily', 'command' => '/bin/true', 'confirm' => 'RUN-AS-ROOT',
        ]);
        $id = $added['data']['job']['id'];
        $this->call($rt, ['cron.job.disable', $id]);

        [$refused] = $this->call($rt, ['cron.job.enable', $id]);
        $this->assertNotSame(0, $refused);

        [$allowed] = $this->call($rt, ['cron.job.enable', $id], ['confirm' => 'RUN-AS-ROOT']);
        $this->assertSame(0, $allowed);
    }

    public function test_running_a_job_now_uses_the_same_identity_and_wrapper(): void
    {
        $rt = $this->runtime();
        [, $added] = $this->call($rt, ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '@hourly', 'command' => '/bin/true',
        ]);
        $id = $added['data']['job']['id'];

        [$code, $json] = $this->call($rt, ['cron.job.run', $id]);

        $this->assertSame(0, $code);
        $this->assertSame('az-vh-shop-example-com', $json['data']['ran_as']);
        $this->assertContains(
            ['/sbin/runuser', '-u', 'az-vh-shop-example-com', '--', '/bin/sh',
                '/usr/local/lib/azerioid-panel/azerioid-cron-run', $id,
                CronRenderer::logPathFor('az-vh-shop-example-com', $id), '/bin/true'],
            array_column($rt->execLog, 'command')
        );
    }

    public function test_the_wrapper_records_the_exit_code_where_the_panel_can_read_it(): void
    {
        $rt = $this->runtime();
        $this->call($rt, ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '@hourly', 'command' => '/bin/true',
        ]);

        $wrapper = $rt->files['/usr/local/lib/azerioid-panel/azerioid-cron-run'] ?? '';
        $this->assertStringContainsString('EXIT', $wrapper);
        $this->assertSame(0755, $rt->modes['/usr/local/lib/azerioid-panel/azerioid-cron-run'] ?? null);
    }

    /**
     * A site job runs as the site, so the directory it writes into has to belong to
     * that identity. One root-owned directory would make every site job fail at its
     * redirection, before the command ran at all.
     */
    public function test_each_identity_gets_a_log_directory_it_can_actually_write_to(): void
    {
        $rt = $this->runtime();

        $this->call($rt, ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '@hourly', 'command' => '/bin/true',
        ]);

        $dir = CronRenderer::LOG_DIR . '/az-vh-shop-example-com';
        $this->assertArrayHasKey($dir, $rt->dirs);
        // The whole chain has to be walkable by the identity, not just the leaf. A live host
        // proved that: the leaf was owned correctly and the job still could not reach it,
        // because an ancestor was 0750 and owned by someone else.
        $this->assertSame(0751, $rt->modes[CronRenderer::LOG_DIR] ?? null, 'the base must be traversable by every identity');
        $this->assertStringStartsNotWith('/var/log/azerioid-panel', CronRenderer::LOG_DIR, 'cron logs must not depend on traversing the audit log directory');
        $this->assertSame(
            ['az-vh-shop-example-com', 'az-vh-shop-example-com'],
            $rt->owners[$dir] ?? null,
            'the identity that writes the log must own the directory'
        );
    }

    public function test_deleting_a_job_removes_only_that_one(): void
    {
        $rt = $this->runtime();
        [, $a] = $this->call($rt, ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '@hourly', 'command' => '/one.sh',
        ]);
        $this->call($rt, ['cron.job.add'], [
            'owner' => 'shop.example.com', 'schedule' => '@daily', 'command' => '/two.sh',
        ]);

        $this->call($rt, ['cron.job.del', $a['data']['job']['id']]);

        [, $list] = $this->call($rt, ['cron.jobs']);
        $commands = array_column($list['data']['jobs'], 'command');
        $this->assertSame(['/two.sh'], $commands);
    }

    public function test_an_unknown_job_id_is_refused(): void
    {
        foreach (['not-an-id', 'job-zzzz'] as $id) {
            [$code] = $this->call($this->runtime(), ['cron.job.del', $id]);
            $this->assertNotSame(0, $code, $id);
        }
    }

    /** The old whole-file action still works; released code calls it. */
    public function test_the_legacy_root_crontab_action_still_works(): void
    {
        [$code] = $this->call($this->runtime(), ['cron.set'], [
            'lines' => ['0 5 * * * /bin/true'], 'confirm' => 'UPDATE-ROOT-CRON',
        ]);

        $this->assertSame(0, $code);
    }
}
