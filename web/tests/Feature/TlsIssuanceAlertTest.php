<?php

namespace Tests\Feature;

use App\Models\AlertIncident;
use App\Models\Setting;
use App\Models\Vhost;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B1 / request #5 — TLS configured but never actually issued.
 *
 * The existing expiry rule reads `tls.certs`, which only lists certificates that
 * exist, so a vhost set to automatic TLS whose issuance never succeeded could not
 * appear there at all and the panel stayed silent. On the verification host four
 * vhosts were in exactly that state: automatic HTTPS, no certificate, an
 * unmatched-SNI handshake failure for visitors, no alert.
 */
class TlsIssuanceAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        // Only the TLS rule; everything else off so nothing else can fire.
        Setting::put('alert.rules', [
            'service_down' => false,
            'observed_down' => false,
            'reboot_required' => false,
            'tls' => true,
            'backup_stale' => false,
            'ssh' => false,
            'disk_percent' => 99,
            'ram_percent' => 99,
            'load' => 100,
            'tls_days' => 1,
            'tls_grace_hours' => 24,
            'backup_stale_hours' => 168,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function vhostServing(string $domain, array $overrides = []): void
    {
        $fake = app(FakeBroker::class);
        $fake->vhosts = [array_merge([
            'domain' => $domain,
            'domains' => [$domain],
            'root' => '/data/www/' . $domain,
            'type' => 'php',
            'engine' => 'caddy',
            'runtime' => 'fpm',
            'readonly' => false,
            'enabled' => true,
            'tls' => true,
            'tls_mode' => 'auto',
            'tls_status' => [
                'enabled' => true,
                'mode' => 'auto',
                'issuer_type' => 'pending',
                'ok' => false,
                'pending' => true,
                'failed' => false,
                'error' => 'No certificate yet; DNS for this name does not resolve to this host',
                'days_remaining' => null,
            ],
        ], $overrides)];
    }

    private function evaluate(): array
    {
        return app(AlertEvaluator::class)->run();
    }

    private function agedVhost(string $domain, int $hoursOld): void
    {
        $row = Vhost::query()->create(['domain' => $domain]);
        $row->forceFill(['created_at' => now()->subHours($hoursOld)])->save();
    }

    // ---------------------------------------------------------------- the gap

    public function test_alerts_when_automatic_tls_never_produced_a_certificate(): void
    {
        $this->vhostServing('shop.example.com');
        $this->agedVhost('shop.example.com', 48);

        $this->evaluate();

        $incident = AlertIncident::query()->where('rule_key', 'tls.issuance')->first();
        $this->assertNotNull($incident, 'this is precisely the case that used to be silent');
        $this->assertSame('shop.example.com', $incident->subject);
        $this->assertStringContainsString('no certificate has been issued', $incident->message);
    }

    /** AcmeStatusHint already works out why, so the alert should say why. */
    public function test_alert_carries_the_reason(): void
    {
        $this->vhostServing('shop.example.com');
        $this->agedVhost('shop.example.com', 48);

        $this->evaluate();

        $this->assertStringContainsString(
            'does not resolve to this host',
            (string) AlertIncident::query()->where('rule_key', 'tls.issuance')->first()->message
        );
    }

    public function test_a_hard_failure_is_higher_severity_than_a_pending_one(): void
    {
        $this->vhostServing('shop.example.com', [
            'tls_status' => [
                'enabled' => true, 'mode' => 'auto', 'issuer_type' => 'failed',
                'ok' => false, 'pending' => false, 'failed' => true,
                'error' => 'Failed to obtain a certificate', 'days_remaining' => null,
            ],
        ]);
        $this->agedVhost('shop.example.com', 48);

        $this->evaluate();

        $this->assertSame('high', AlertIncident::query()->where('rule_key', 'tls.issuance')->first()->severity);
    }

    // ----------------------------------------------------------- grace period

    /** Creating a vhost before pointing DNS at it is the normal workflow. */
    public function test_a_freshly_created_vhost_does_not_alarm(): void
    {
        $this->vhostServing('brand-new.example.com');
        $this->agedVhost('brand-new.example.com', 2);

        $this->evaluate();

        $this->assertNull(AlertIncident::query()->where('rule_key', 'tls.issuance')->first());
    }

    public function test_grace_can_be_disabled(): void
    {
        $rules = Setting::get('alert.rules', []);
        $rules['tls_grace_hours'] = 0;
        Setting::put('alert.rules', $rules);

        $this->vhostServing('brand-new.example.com');
        $this->agedVhost('brand-new.example.com', 1);

        $this->evaluate();

        $this->assertNotNull(AlertIncident::query()->where('rule_key', 'tls.issuance')->first());
    }

    /**
     * An existing host that has not run `vhost reconcile --repair` has no row to
     * date the vhost from. The bug being fixed is silence, so it alerts.
     */
    public function test_an_undated_vhost_alerts_rather_than_being_skipped(): void
    {
        $this->vhostServing('shop.example.com');
        // No projection row at all.

        $this->evaluate();

        $this->assertNotNull(AlertIncident::query()->where('rule_key', 'tls.issuance')->first());
    }

    // ------------------------------------------------------- must not fire

    public function test_no_alert_when_the_certificate_is_fine(): void
    {
        $this->vhostServing('shop.example.com', [
            'tls_status' => [
                'enabled' => true, 'mode' => 'auto', 'issuer_type' => 'lets_encrypt',
                'ok' => true, 'pending' => false, 'failed' => false,
                'error' => null, 'days_remaining' => 60,
            ],
        ]);
        $this->agedVhost('shop.example.com', 48);

        $this->evaluate();

        $this->assertNull(AlertIncident::query()->where('rule_key', 'tls.issuance')->first());
    }

    /** `internal` and `off` are working as configured, not failing. */
    public function test_self_signed_and_plain_http_are_not_failures(): void
    {
        foreach (['internal', 'off'] as $mode) {
            AlertIncident::query()->delete();
            $this->vhostServing('local.example.com', [
                'tls_mode' => $mode,
                'tls' => $mode !== 'off',
                'tls_status' => [
                    'enabled' => $mode !== 'off', 'mode' => $mode, 'issuer_type' => 'self_signed',
                    'ok' => false, 'pending' => false, 'failed' => true,
                    'error' => 'not captured', 'days_remaining' => null,
                ],
            ]);
            $this->agedVhost('local.example.com', 48);

            $this->evaluate();

            $this->assertNull(
                AlertIncident::query()->where('rule_key', 'tls.issuance')->first(),
                "mode {$mode} must not alert"
            );
            Vhost::query()->delete();
        }
    }

    public function test_readonly_vhosts_are_skipped(): void
    {
        $this->vhostServing('64.226.78.176:3169', ['readonly' => true]);
        $this->agedVhost('64.226.78.176:3169', 48);

        $this->evaluate();

        $this->assertNull(AlertIncident::query()->where('rule_key', 'tls.issuance')->first());
    }

    public function test_rule_can_be_turned_off_entirely(): void
    {
        $rules = Setting::get('alert.rules', []);
        $rules['tls'] = false;
        Setting::put('alert.rules', $rules);

        $this->vhostServing('shop.example.com');
        $this->agedVhost('shop.example.com', 48);

        $this->evaluate();

        $this->assertNull(AlertIncident::query()->where('rule_key', 'tls.issuance')->first());
    }

    public function test_incident_resolves_once_the_certificate_arrives(): void
    {
        $this->vhostServing('shop.example.com');
        $this->agedVhost('shop.example.com', 48);
        $this->evaluate();
        $this->assertSame('open', AlertIncident::query()->where('rule_key', 'tls.issuance')->first()->status);

        $this->vhostServing('shop.example.com', [
            'tls_status' => [
                'enabled' => true, 'mode' => 'auto', 'issuer_type' => 'lets_encrypt',
                'ok' => true, 'pending' => false, 'failed' => false,
                'error' => null, 'days_remaining' => 89,
            ],
        ]);
        $this->evaluate();

        $this->assertSame(
            'resolved',
            AlertIncident::query()->where('rule_key', 'tls.issuance')->first()->status
        );
    }
}
