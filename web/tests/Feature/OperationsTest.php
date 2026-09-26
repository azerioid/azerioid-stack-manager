<?php

namespace Tests\Feature;

use App\Jobs\RunOperationJob;
use App\Livewire\OperationsPage;
use App\Models\Operation;
use App\Models\User;
use App\Services\Broker\FakeBroker;
use App\Services\OperationDispatcher;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B5 / request #13 — long-running work becomes a record instead of a blocking
 * request.
 *
 * Component installs and panel updates already had this; a docker build, a backup,
 * a runtime enable did not. They ran synchronously behind a 900-second broker
 * timeout with no progress, no log, no cancel and no trace afterwards.
 */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $totp = new TotpService();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function dispatcher(): OperationDispatcher
    {
        return app(OperationDispatcher::class);
    }

    /** Docker needs a proxy/static vhost with the runtime already enabled. */
    private function dockerCapableVhost(string $domain): void
    {
        $fake = app(FakeBroker::class);
        $fake->vhosts[] = [
            'domain' => $domain,
            'domains' => [$domain],
            'root' => '/data/www/' . $domain,
            'type' => 'proxy',
            'engine' => 'caddy',
            'runtime' => 'docker',
            'readonly' => false,
            'enabled' => true,
            'tls' => true,
            'tls_mode' => 'auto',
            'docker_port' => 37000,
            'docker_internal_port' => 8080,
            'docker_mode' => 'image',
            'docker_image' => 'nginx:alpine',
        ];
    }

    // ------------------------------------------------------------- dispatching

    public function test_a_slow_action_is_recognised_and_a_quick_one_is_not(): void
    {
        foreach (['vhost.docker.build', 'vhost.docker.enable', 'backup.restore.db', 'backup.restore.files'] as $slow) {
            $this->assertTrue(OperationDispatcher::isAsync($slow), $slow);
        }
        // Read-only and quick writes must keep running inline; queueing them would
        // add latency and a row for nothing.
        foreach (['vhost.list', 'status.all', 'db.list', 'vhost.files.read', 'firewall.status'] as $quick) {
            $this->assertFalse(OperationDispatcher::isAsync($quick), $quick);
        }
    }

    /**
     * A runtime enable returns the port it is now serving from, and normally
     * finishes in seconds. Queueing it would take that answer away from the
     * operator to solve a problem it does not have. Backups are slow, but they
     * keep a BackupJob row that the staleness alert reads, so they wait for the
     * unification rather than being half-converted.
     */
    public function test_actions_deliberately_left_inline_stay_inline(): void
    {
        foreach (['vhost.octane.enable', 'vhost.pm2.enable', 'mail.domain.enable',
            'backup.db', 'backup.files', 'backup.caddy'] as $inline) {
            $this->assertFalse(OperationDispatcher::isAsync($inline), $inline);
        }
    }

    public function test_dispatch_records_the_operation_and_queues_a_job(): void
    {
        Queue::fake();

        $op = $this->dispatcher()->dispatch('vhost.docker.build', ['shop.example.com'], []);

        $this->assertSame('docker.build', $op->kind);
        $this->assertSame('vhost', $op->subject_type);
        $this->assertSame('shop.example.com', $op->subject_id);
        $this->assertSame('vhost.docker.build', $op->broker_action);
        $this->assertSame(Operation::STATUS_QUEUED, $op->status);
        Queue::assertPushed(RunOperationJob::class);
    }

    /**
     * A restore is called with the *archive* key as its argument, but the subject
     * the operator is watching — and the subject the per-subject lock has to
     * serialise on — is the database being restored into.
     */
    public function test_the_subject_of_a_restore_is_the_target_not_the_archive(): void
    {
        Queue::fake();

        $op = $this->dispatcher()->dispatch('backup.restore.db', ['db/all-20260926.lacmp'], [
            'target' => 'shop_production',
            'key' => 'db/all-20260926.lacmp',
        ]);

        $this->assertSame('shop_production', $op->subject_id);
        $this->assertSame('database', $op->subject_type);
        // The archive is still recorded, just not as the subject.
        $this->assertSame(['db/all-20260926.lacmp'], $op->args);
    }

    public function test_a_file_restore_is_subject_to_the_site_it_unpacks_into(): void
    {
        Queue::fake();

        $op = $this->dispatcher()->dispatch('backup.restore.files', ['files/shop-20260926.lacmp'], [
            'site' => 'shop.example.com',
            'apply' => true,
        ]);

        $this->assertSame('shop.example.com', $op->subject_id);
    }

    public function test_work_with_no_identifiable_subject_gets_a_stable_one(): void
    {
        Queue::fake();

        // Nothing currently dispatched is host-wide, but the fallback has to be
        // stable rather than empty: the lock key is derived from it.
        $this->assertSame('host', $this->dispatcher()->dispatch('backup.restore.files', [], [])->subject_id);
    }

    /**
     * The row is read by the UI and kept for 180 days, so secrets are stripped
     * rather than merely redacted.
     */
    public function test_secrets_never_reach_the_operations_table(): void
    {
        Queue::fake();

        $op = $this->dispatcher()->dispatch('backup.restore.db', ['db/all.lacmp'], [
            'passphrase' => 'correct-horse-battery-staple',
            'spaces' => ['secret' => 'supersecretkeyvalue'],
            'destination' => 'local',
            'target' => 'shop_restore',
        ]);

        $serialised = json_encode($op->fresh()->toArray());
        $this->assertStringNotContainsString('correct-horse-battery-staple', (string) $serialised);
        $this->assertStringNotContainsString('supersecretkeyvalue', (string) $serialised);
        // Non-secret context is kept, because that is what makes the record useful.
        $this->assertSame('local', $op->options['destination'] ?? null);
        $this->assertSame('shop_restore', $op->options['target'] ?? null);
    }

    public function test_dispatching_an_unlisted_action_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dispatcher()->dispatch('vhost.list', [], []);
    }

    // ------------------------------------------------------------------ running

    /** Uses a static vhost, since the fake correctly refuses Docker on a PHP one. */
    public function test_running_an_operation_records_success(): void
    {
        $this->dockerCapableVhost('app.example.com');
        $op = Operation::query()->create([
            'kind' => 'docker.build',
            'subject_type' => 'vhost',
            'subject_id' => 'app.example.com',
            'broker_action' => 'vhost.docker.build',
            'args' => ['app.example.com'],
            'status' => Operation::STATUS_QUEUED,
        ]);

        (new RunOperationJob($op->id))->handle(app(\App\Services\Broker\BrokerClient::class), app(\App\Services\Alerts\TelegramNotifier::class));

        $op->refresh();
        $this->assertSame(Operation::STATUS_COMPLETED, $op->status);
        $this->assertNotNull($op->started_at);
        $this->assertNotNull($op->finished_at);
        $this->assertNull($op->error);
    }

    public function test_a_broker_failure_is_recorded_not_thrown(): void
    {
        app(FakeBroker::class)->failNextCall = true;
        $op = Operation::query()->create([
            'kind' => 'docker.build',
            'subject_type' => 'vhost',
            'subject_id' => 'shop.example.com',
            'broker_action' => 'vhost.docker.build',
            'args' => ['nonexistent.example.com'],
            'status' => Operation::STATUS_QUEUED,
        ]);

        (new RunOperationJob($op->id))->handle(app(\App\Services\Broker\BrokerClient::class), app(\App\Services\Alerts\TelegramNotifier::class));

        $this->assertSame(Operation::STATUS_FAILED, $op->refresh()->status);
        $this->assertNotNull($op->error);
        $this->assertNotNull($op->finished_at, 'a failed operation must still be terminal');
    }

    /** Same lesson as A1: a dead worker must not leave a row stuck at running. */
    public function test_job_failure_leaves_the_row_terminal(): void
    {
        $op = Operation::query()->create([
            'kind' => 'docker.build',
            'subject_type' => 'vhost',
            'subject_id' => 'shop.example.com',
            'broker_action' => 'vhost.docker.build',
            'status' => Operation::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        (new RunOperationJob($op->id))->failed(new \RuntimeException('worker killed'));

        $op->refresh();
        $this->assertSame(Operation::STATUS_FAILED, $op->status);
        $this->assertStringContainsString('worker killed', (string) $op->error);
    }

    public function test_job_is_bounded_by_deadline_not_attempt_count(): void
    {
        $job = new RunOperationJob(1);

        $this->assertSame(0, $job->tries, 'releasing to wait for a lock must not burn the only attempt');
        $this->assertGreaterThan(now()->addMinutes(50), $job->retryUntil());
    }

    /** Cancelled while queued: the job must not then run it anyway. */
    public function test_a_cancelled_operation_is_not_executed(): void
    {
        $op = Operation::query()->create([
            'kind' => 'docker.build',
            'subject_type' => 'vhost',
            'subject_id' => 'shop.example.com',
            'broker_action' => 'vhost.docker.build',
            'status' => Operation::STATUS_CANCELLED,
        ]);

        (new RunOperationJob($op->id))->handle(app(\App\Services\Broker\BrokerClient::class), app(\App\Services\Alerts\TelegramNotifier::class));

        $this->assertSame(Operation::STATUS_CANCELLED, $op->refresh()->status);
        $this->assertNull($op->started_at);
    }

    // ------------------------------------------------------------- concurrency

    /** The point of this phase: locks are per subject, not global. */
    public function test_two_different_subjects_do_not_block_each_other(): void
    {
        $a = Operation::lockKeyFor('vhost', 'a.example.com');
        $b = Operation::lockKeyFor('vhost', 'b.example.com');

        $this->assertNotSame($a, $b);
        $this->assertTrue(Cache::lock($a, 10)->get());
        $this->assertTrue(Cache::lock($b, 10)->get(), 'a second vhost must be able to work concurrently');
    }

    public function test_the_same_subject_blocks_itself(): void
    {
        $key = Operation::lockKeyFor('vhost', 'shop.example.com');

        $this->assertTrue(Cache::lock($key, 10)->get());
        $this->assertFalse(Cache::lock($key, 10)->get(), 'one vhost must not build twice at once');
    }

    // --------------------------------------------------------------------- UI

    public function test_the_page_lists_operations_and_filters_them(): void
    {
        Operation::query()->create([
            'kind' => 'docker.build', 'subject_type' => 'vhost', 'subject_id' => 'ok.example.com',
            'broker_action' => 'vhost.docker.build', 'status' => Operation::STATUS_COMPLETED,
        ]);
        Operation::query()->create([
            'kind' => 'backup.db', 'subject_type' => 'database', 'subject_id' => 'all',
            'broker_action' => 'backup.db', 'status' => Operation::STATUS_FAILED, 'error' => 'dump failed',
        ]);

        Livewire::actingAs($this->admin())->test(OperationsPage::class)
            ->assertSee('docker.build')
            ->assertSee('backup.db')
            ->set('filter', 'failed')
            ->assertSee('backup.db')
            ->assertDontSee('ok.example.com');
    }

    public function test_a_queued_operation_can_be_cancelled_from_the_page(): void
    {
        $op = Operation::query()->create([
            'kind' => 'docker.build', 'subject_type' => 'vhost', 'subject_id' => 'shop.example.com',
            'broker_action' => 'vhost.docker.build', 'status' => Operation::STATUS_QUEUED,
        ]);

        Livewire::actingAs($this->admin())->test(OperationsPage::class)->call('cancel', $op->id);

        $this->assertSame(Operation::STATUS_CANCELLED, $op->refresh()->status);
        $this->assertNotNull($op->finished_at);
    }

    /**
     * Interrupting a running operation would leave the package manager or the
     * container build half-finished, so the UI refuses rather than offering a
     * button that does damage.
     */
    public function test_a_running_operation_cannot_be_cancelled(): void
    {
        $op = Operation::query()->create([
            'kind' => 'docker.build', 'subject_type' => 'vhost', 'subject_id' => 'shop.example.com',
            'broker_action' => 'vhost.docker.build', 'status' => Operation::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        Livewire::actingAs($this->admin())->test(OperationsPage::class)
            ->call('cancel', $op->id)
            ->assertSet('error', 'Only a queued operation can be cancelled; this one has already started.');

        $this->assertSame(Operation::STATUS_RUNNING, $op->refresh()->status);
    }

    // -------------------------------------------------------------- call sites

    private function seedBackupSecrets(): void
    {
        \App\Models\Setting::put('spaces.endpoint', 'https://fra1.digitaloceanspaces.com');
        \App\Models\Setting::put('spaces.region', 'fra1');
        \App\Models\Setting::put('spaces.bucket', 'azerioid-backups');
        \App\Models\Setting::putSecret('spaces.access_key', 'DO00TESTKEY');
        \App\Models\Setting::putSecret('spaces.secret', 'supersecretkeyvalue');
        \App\Models\Setting::putSecret('backup.passphrase', 'abcdefghijklmnopqrst');
    }

    public function test_a_database_restore_is_queued_rather_than_run_inline(): void
    {
        Queue::fake();
        $this->seedBackupSecrets();
        $fake = app(FakeBroker::class);

        Livewire::actingAs($this->admin())->test(\App\Livewire\BackupsPage::class)
            ->set('restore_key', 'azerioid/db/all/fixture.bin')
            ->set('restore_target', 'shop_restore')
            ->call('restoreDb')
            ->assertSet('error', null);

        $op = Operation::query()->sole();
        $this->assertSame('backup.restore.db', $op->broker_action);
        $this->assertSame('shop_restore', $op->subject_id);
        $this->assertSame(Operation::STATUS_QUEUED, $op->status);
        // The preflight ran; the restore itself did not.
        $this->assertContains('backup.restore.check', $fake->callLog);
        $this->assertNotContains('backup.restore.db', $fake->callLog);
    }

    public function test_a_file_restore_is_queued_but_its_preview_still_runs_inline(): void
    {
        Queue::fake();
        $this->seedBackupSecrets();
        $fake = app(FakeBroker::class);

        $page = Livewire::actingAs($this->admin())->test(\App\Livewire\BackupsPage::class)
            ->set('restore_key', 'azerioid/files/shop.example.com/fixture.bin')
            ->set('restore_site', 'shop.example.com')
            ->call('previewFiles');

        // A preview only reads a listing, and the operator is waiting for it.
        $this->assertContains('backup.restore.files', $fake->callLog);
        $this->assertSame(0, Operation::query()->count());

        $page->call('applyFiles');

        $this->assertSame('shop.example.com', Operation::query()->sole()->subject_id);
    }

    /**
     * The guards that stop an operator overwriting a live database or a read-only
     * vhost have to refuse *before* anything is queued. A refusal that produced a
     * queued row would either run anyway or leave a failed operation the operator
     * only reads later — both worse than the inline refusal this replaced.
     */
    public function test_a_refused_restore_queues_nothing(): void
    {
        Queue::fake();
        $this->seedBackupSecrets();

        Livewire::actingAs($this->admin())->test(\App\Livewire\BackupsPage::class)
            ->set('restore_key', 'azerioid/db/projob/fixture.bin')
            ->set('restore_target', 'projob')
            ->set('restore_overwrite', false)
            ->call('restoreDb')
            ->assertSet('error', 'Target database exists. Restore into a new name, or send overwrite confirm OVERWRITE.');

        $this->assertSame(0, Operation::query()->count(), 'a refusal must not leave queued work behind');
        Queue::assertNothingPushed();
    }

    public function test_a_standalone_docker_enable_is_queued(): void
    {
        Queue::fake();
        $fake = app(FakeBroker::class);
        $fake->vhosts[] = [
            'domain' => 'static.example.com',
            'domains' => ['static.example.com'],
            'root' => '/data/www/static.example.com',
            'type' => 'static',
            'engine' => 'caddy',
            'runtime' => 'static',
            'readonly' => false,
            'enabled' => true,
            'tls' => true,
            'tls_mode' => 'auto',
        ];

        Livewire::actingAs($this->admin())->test(\App\Livewire\VhostsPage::class)
            ->call('askDocker', 'static.example.com')
            ->set('dockerMode', 'image')
            ->set('dockerImage', 'nginx:alpine')
            ->set('dockerInternalPort', '8080')
            ->call('enableDocker')
            ->assertSet('error', null);

        $op = Operation::query()->sole();
        $this->assertSame('vhost.docker.enable', $op->broker_action);
        $this->assertSame('static.example.com', $op->subject_id);
        $this->assertNotContains('vhost.docker.enable', $fake->callLog);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get('/operations')->assertRedirect();
    }
}
