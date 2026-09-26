<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vhost;
use App\Services\Broker\BrokerClient;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use App\Services\VhostProjection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A44 — per-vhost state projection.
 *
 * The config files stay authoritative for how traffic is served; this table only
 * projects them so the panel can query and so later features have somewhere to hang
 * per-vhost records. These tests pin that contract, and the drift reporting that
 * exists because reconciliation is change-triggered rather than continuous.
 */
class VhostProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function projection(): VhostProjection
    {
        return app(VhostProjection::class);
    }

    private function fake(): FakeBroker
    {
        return app(FakeBroker::class);
    }

    private function admin(): User
    {
        $totp = new TotpService();

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- reconcile

    public function test_reconcile_projects_the_live_vhosts(): void
    {
        $result = $this->projection()->reconcile();

        $this->assertGreaterThan(0, $result['created']);
        $this->assertSame($result['created'], Vhost::query()->count());

        $shop = Vhost::query()->where('domain', 'shop.example.com')->first();
        $this->assertNotNull($shop);
        $this->assertSame('php', $shop->type);
        $this->assertSame('caddy', $shop->engine);
        $this->assertSame('8.4', $shop->php_version);
        $this->assertSame('/data/www/shop.example.com', $shop->docroot);
        $this->assertSame('auto', $shop->tls_mode);
        $this->assertTrue($shop->tls_enabled);
        $this->assertNotNull($shop->reconciled_at);
    }

    /**
     * tls_status comes from a live CertProbe. Storing it would mean the panel
     * showing an expiry and issuer that were true at reconcile time and possibly
     * false now, which is worse than not showing them.
     */
    public function test_projection_does_not_store_live_probe_results(): void
    {
        $this->projection()->reconcile();
        $row = Vhost::query()->where('domain', 'shop.example.com')->first()->toArray();

        $flat = json_encode($row);
        $this->assertStringNotContainsString('issuer', (string) $flat);
        $this->assertStringNotContainsString('days_remaining', (string) $flat);
        $this->assertStringNotContainsString('lets_encrypt', (string) $flat);
        $this->assertArrayNotHasKey('tls_status', $row);
    }

    public function test_reconcile_is_idempotent(): void
    {
        $first = $this->projection()->reconcile();
        $second = $this->projection()->reconcile();

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['removed']);
        $this->assertSame($first['created'], $second['unchanged'], 'a no-op refresh must not read as an update');
        $this->assertSame($first['total'], $second['total']);
    }

    public function test_reconcile_detects_a_changed_vhost(): void
    {
        $this->projection()->reconcile();

        $fake = $this->fake();
        foreach ($fake->vhosts as $i => $v) {
            if (($v['domain'] ?? '') === 'shop.example.com') {
                $fake->vhosts[$i]['php_version'] = '8.3';
                $fake->vhosts[$i]['config_sha256'] = str_repeat('b', 64);
            }
        }

        $result = $this->projection()->reconcile();

        $this->assertSame(1, $result['updated']);
        $this->assertSame('8.3', Vhost::query()->where('domain', 'shop.example.com')->first()->php_version);
    }

    /** A vhost the config no longer describes must not linger in the UI. */
    public function test_reconcile_removes_a_deleted_vhost(): void
    {
        $this->projection()->reconcile();
        $before = Vhost::query()->count();

        $fake = $this->fake();
        $fake->vhosts = array_values(array_filter(
            $fake->vhosts,
            static fn ($v) => ($v['domain'] ?? '') !== 'shop.example.com'
        ));

        $result = $this->projection()->reconcile();

        $this->assertSame(1, $result['removed']);
        $this->assertSame($before - 1, Vhost::query()->count());
        $this->assertNull(Vhost::query()->where('domain', 'shop.example.com')->first());
    }

    public function test_reconcile_survives_a_broker_failure_without_wiping_the_projection(): void
    {
        $this->projection()->reconcile();
        $before = Vhost::query()->count();
        $this->assertGreaterThan(0, $before);

        // An empty/failed listing must not be read as "every vhost was deleted".
        $this->fake()->vhosts = [];
        $this->projection()->reconcile();

        $this->assertSame(
            $before,
            Vhost::query()->count(),
            'an empty listing is more likely a broker problem than a mass deletion'
        );
    }

    // ----------------------------------------------------------------- drift

    public function test_drift_reports_nothing_right_after_reconcile(): void
    {
        $this->projection()->reconcile();

        $drift = $this->projection()->drift();

        $this->assertSame([], $drift['drifted']);
        $this->assertGreaterThan(0, $drift['in_sync']);
    }

    /** The case change-triggered reconciliation cannot catch on its own. */
    public function test_drift_detects_a_config_file_edited_outside_the_panel(): void
    {
        $this->projection()->reconcile();

        $fake = $this->fake();
        foreach ($fake->vhosts as $i => $v) {
            if (($v['domain'] ?? '') === 'shop.example.com') {
                $fake->vhosts[$i]['config_sha256'] = str_repeat('f', 64);
            }
        }

        $drift = $this->projection()->drift();

        $domains = array_column($drift['drifted'], 'domain');
        $this->assertContains('shop.example.com', $domains);
        $reason = $drift['drifted'][array_search('shop.example.com', $domains, true)]['reason'];
        $this->assertStringContainsString('outside the panel', $reason);
    }

    public function test_drift_detects_a_vhost_only_in_the_database(): void
    {
        Vhost::query()->create([
            'domain' => 'ghost.example.com',
            'config_sha256' => str_repeat('a', 64),
        ]);

        $drift = $this->projection()->drift();

        $domains = array_column($drift['drifted'], 'domain');
        $this->assertContains('ghost.example.com', $domains);
    }

    public function test_drift_detects_a_vhost_only_in_the_config(): void
    {
        // Nothing reconciled yet, so every served vhost is missing from the panel.
        $drift = $this->projection()->drift();

        $domains = array_column($drift['drifted'], 'domain');
        $this->assertContains('shop.example.com', $domains);
        $this->assertSame(0, $drift['in_sync']);
    }

    // -------------------------------------------------------------- triggers

    public function test_mutating_actions_are_the_ones_that_invalidate_the_projection(): void
    {
        foreach (['vhost.add', 'vhost.edit', 'vhost.del', 'vhost.octane.enable', 'vhost.docker.disable'] as $action) {
            $this->assertTrue(VhostProjection::invalidatedBy($action), $action);
        }
        foreach (['vhost.list', 'vhost.files.read', 'db.list', 'status.all'] as $action) {
            $this->assertFalse(VhostProjection::invalidatedBy($action), $action);
        }
    }

    /** Reconciliation is wired in one place so a new call site cannot forget it. */
    public function test_a_mutating_broker_call_reconciles_automatically(): void
    {
        $this->assertSame(0, Vhost::query()->count());

        $this->actingAs($this->admin());
        app(BrokerClient::class)->call('vhost.add', ['new.example.com'], [
            'type' => 'static',
            'root' => '/data/www/new.example.com',
        ]);

        $this->assertGreaterThan(0, Vhost::query()->count(), 'the projection must be built without an explicit call');
        $this->assertNotNull(Vhost::query()->where('domain', 'new.example.com')->first());
    }

    public function test_a_read_only_broker_call_does_not_reconcile(): void
    {
        $this->actingAs($this->admin());
        app(BrokerClient::class)->call('vhost.list', [], [], null, false);

        $this->assertSame(0, Vhost::query()->count());
    }

    /**
     * The config files are authoritative and `vhost reconcile --repair` can always
     * rebuild, so a projection problem must never fail the operator's action.
     */
    public function test_a_projection_failure_does_not_fail_the_operation(): void
    {
        $this->actingAs($this->admin());
        $this->app->bind(VhostProjection::class, function () {
            throw new \RuntimeException('projection exploded');
        });

        $response = app(BrokerClient::class)->call('vhost.add', ['ok.example.com'], [
            'type' => 'static',
            'root' => '/data/www/ok.example.com',
        ]);

        $this->assertTrue($response->ok, 'the vhost was created; only the projection failed');
    }

    // ------------------------------------------------------------------- CLI

    public function test_cli_reports_drift_and_exits_non_zero(): void
    {
        $this->artisan('azerioid:vhost', ['action' => 'reconcile'])
            ->assertFailed();
    }

    public function test_cli_repair_rebuilds_the_projection(): void
    {
        $this->artisan('azerioid:vhost', ['action' => 'reconcile', '--repair' => true])
            ->assertSuccessful();

        $this->assertGreaterThan(0, Vhost::query()->count());

        $this->artisan('azerioid:vhost', ['action' => 'reconcile'])
            ->assertSuccessful();
    }

    public function test_cli_dry_run_changes_nothing(): void
    {
        $this->artisan('azerioid:vhost', [
            'action' => 'reconcile',
            '--repair' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Vhost::query()->count());
    }
}
