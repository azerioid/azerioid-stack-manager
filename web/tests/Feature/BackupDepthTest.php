<?php

namespace Tests\Feature;

use App\Jobs\RunOperationJob;
use App\Livewire\BackupBundles;
use App\Livewire\BackupSchedules;
use App\Models\BackupJob;
use App\Models\BackupSchedule;
use App\Models\Operation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B6 / ADR A52 in the panel: site bundles, per-target schedules with age-based retention,
 * restore verification.
 */
class BackupDepthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString((new TotpService())->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function fake(): FakeBroker
    {
        Setting::putSecret('backup.passphrase', 'abcdefghijklmnopqrst');
        $fake = app(FakeBroker::class);
        $fake->vhosts[] = ['domain' => 'shop.test', 'domains' => ['shop.test'], 'root' => '/data/www/shop.test/public', 'type' => 'php',
            'engine' => 'caddy', 'runtime' => 'fpm', 'readonly' => false, 'enabled' => true, 'tls' => false, 'tls_mode' => 'off'];

        return $fake;
    }

    public function test_a_site_bundle_is_queued_and_its_passphrase_is_not_kept_in_the_operation(): void
    {
        Queue::fake();
        $fake = $this->fake();

        Livewire::actingAs($this->admin())->test(BackupBundles::class)
            ->set('domain', 'shop.test')
            ->set('dbText', "mariadb:shop\n")
            ->call('saveDatabases')
            ->assertSet('error', null)
            ->call('runBundle')
            ->assertSet('error', null);

        $this->assertSame([['engine' => 'mariadb', 'name' => 'shop']], $fake->bundleSettings['shop.test']['databases']);
        $op = Operation::query()->sole();
        $this->assertSame('backup.vhost.run', $op->broker_action);
        $this->assertSame('shop.test', $op->subject_id);
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', json_encode($op->toArray()));
        Queue::assertPushed(RunOperationJob::class);
    }

    public function test_a_malformed_database_line_is_refused(): void
    {
        $fake = $this->fake();

        Livewire::actingAs($this->admin())->test(BackupBundles::class)
            ->set('domain', 'shop.test')
            ->set('dbText', 'shop')
            ->call('saveDatabases')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'engine:name'));

        $this->assertArrayNotHasKey('shop.test', $fake->bundleSettings);
    }

    public function test_restore_previews_then_queues_only_with_the_typed_domain(): void
    {
        Queue::fake();
        $fake = $this->fake();
        $fake->fakeBundles[] = ['domain' => 'shop.test', 'bundle' => '20260928T030000Z', 'destination' => 'local',
            'parts' => ['config', 'db-mariadb-shop', 'files', 'manifest'], 'size' => 10, 'complete' => true, 'created_at' => '2026-09-28T03:00:00Z'];

        $page = Livewire::actingAs($this->admin())->test(BackupBundles::class)
            ->call('previewRestore', 'shop.test', '20260928T030000Z', 'local')
            ->assertSet('error', null)
            ->assertSet('restoreParts', ['config', 'db-mariadb-shop', 'files'])
            ->set('restoreParts', ['files'])
            ->set('restoreConfirm', 'shop.test')
            ->call('applyRestore')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'SHOP.TEST'));
        $this->assertSame(0, Operation::query()->count());

        $page->set('restoreConfirm', 'SHOP.TEST')->call('applyRestore')->assertSet('error', null);

        $op = Operation::query()->sole();
        $this->assertSame('backup.vhost.restore', $op->broker_action);
        Queue::assertPushed(RunOperationJob::class, fn ($job): bool => $job->stdin['parts'] === ['files'] && $job->stdin['apply'] === true);
    }

    public function test_schedules_are_added_paused_and_removed(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BackupSchedules::class)
            ->set('targetType', 'vhost')->set('target', 'shop.test')->set('retentionDays', '7')
            ->call('add')->assertSet('error', null);
        $s = BackupSchedule::query()->sole();
        $this->assertSame(7, $s->retention_days);

        Livewire::test(BackupSchedules::class)
            ->set('targetType', 'vhost')->set('target', 'shop.test')
            ->call('add')->assertSet('error', fn ($e) => str_contains((string) $e, 'already has a schedule'));

        Livewire::test(BackupSchedules::class)->call('toggle', $s->id);
        $this->assertFalse($s->fresh()->enabled);
        Livewire::test(BackupSchedules::class)->call('delete', $s->id);
        $this->assertSame(0, BackupSchedule::query()->count());
    }

    public function test_a_target_with_shell_characters_is_refused(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BackupSchedules::class)
            ->set('targetType', 'db')->set('target', 'shop; rm -rf /')
            ->call('add')->assertHasErrors('target');
    }

    public function test_a_due_schedule_runs_then_applies_its_own_retention(): void
    {
        $fake = $this->fake();
        $this->travelTo(now()->setTime(4, 10));
        BackupSchedule::query()->create(['target_type' => 'vhost', 'target' => 'shop.test', 'destination' => 'local',
            'cadence' => 'daily', 'hour' => 4, 'retention_days' => 7, 'enabled' => true]);
        BackupSchedule::query()->create(['target_type' => 'db', 'target' => 'shop', 'engine' => 'mariadb', 'destination' => 'local',
            'cadence' => 'daily', 'hour' => 4, 'enabled' => true]);
        BackupSchedule::query()->create(['target_type' => 'files', 'target' => 'other', 'destination' => 'local',
            'cadence' => 'daily', 'hour' => 5, 'enabled' => true]);
        Setting::put('backup.retention_days', 21);

        $this->assertSame(0, Artisan::call('azerioid:backup-scheduled'));

        $this->assertSame(['backup.vhost.run', 'backup.prune.age', 'backup.db', 'backup.prune.age'], array_values(array_filter(
            $fake->callLog, static fn (string $a): bool => str_starts_with($a, 'backup.')
        )));
        $prunes = array_values(array_filter($fake->calls, static fn (array $c): bool => $c['action'] === 'backup.prune.age'));
        $this->assertSame(7, $prunes[0]['stdin']['days']);
        $this->assertSame(['vhost', 'shop.test'], [$prunes[0]['stdin']['kind'], $prunes[0]['stdin']['name']]);
        $this->assertSame(21, $prunes[1]['stdin']['days'], 'no override: the global default');
        $this->assertSame(2, BackupJob::query()->whereNotNull('schedule_id')->count());
        $this->assertSame('ok', BackupSchedule::query()->where('target', 'shop.test')->sole()->last_status);

        // Not twice in the same day.
        $fake->callLog = [];
        Artisan::call('azerioid:backup-scheduled');
        $this->assertSame([], array_filter($fake->callLog, static fn (string $a): bool => str_starts_with($a, 'backup.')));
    }

    public function test_cli_bundle_and_deep_verify(): void
    {
        $fake = $this->fake();

        $this->assertSame(0, Artisan::call('azerioid:backup', ['action' => 'bundle-dbs', 'domain' => 'shop.test', '--set' => ['mariadb:shop']]));
        $this->assertSame(0, Artisan::call('azerioid:backup', ['action' => 'bundle', 'domain' => 'shop.test', '--local' => true]));
        $this->assertStringContainsString('db-mariadb-shop', Artisan::output());

        $this->assertSame(0, Artisan::call('azerioid:backup', ['action' => 'verify', '--file' => '/var/lib/azerioid-panel/backups/db/shop/x.lacmp2.bin', '--local' => true, '--deep' => true]));
        $this->assertStringContainsString('scratch database', Artisan::output());

        $this->assertSame(2, Artisan::call('azerioid:backup', ['action' => 'bundle-restore', 'domain' => 'shop.test', '--local' => true,
            '--bundle' => $fake->fakeBundles[0]['bundle'], '--apply' => true]));
    }
}
