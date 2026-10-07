<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Broker\FakeBroker;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_down_sends_one_alert_and_one_resolve(): void
    {
        Setting::put('alert.rules', [
            'service_down' => true,
            'observed_down' => false,
            'reboot_required' => false,
            'tls' => false,
            'backup_stale' => false,
            'ssh' => false,
            'disk_percent' => 99,
            'ram_percent' => 99,
            'load' => 100,
            'tls_days' => 1,
            'backup_stale_hours' => 168,
        ]);
        Setting::putSecret('telegram.bot_token', '123456:'.str_repeat('A', 35));
        Setting::put('telegram.chat_id', '11111');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $fake = $this->app->make(FakeBroker::class);
        $fake->php82Failed = true;
        $eval = $this->app->make(AlertEvaluator::class);

        $first = $eval->run();
        $this->assertSame(1, $first['opened']);
        $this->assertSame(0, $first['resolved']);
        $this->assertSame(1, $first['notified']);

        $second = $eval->run();
        $this->assertSame(0, $second['opened']);
        $this->assertSame(0, $second['resolved']);
        $this->assertSame(0, $second['notified']);

        $fake->php82Failed = false;
        $third = $eval->run();
        $this->assertSame(0, $third['opened']);
        $this->assertSame(1, $third['resolved']);
        $this->assertSame(1, $third['notified']);

        Http::assertSentCount(2);
    }

    public function test_app_down_alerts_and_resolves_for_a_runtime_vhost(): void
    {
        Setting::put('alert.rules', [
            'service_down' => false, 'observed_down' => false, 'reboot_required' => false,
            'tls' => false, 'backup_stale' => false, 'cron_failed' => false, 'ssh' => false,
            'app_down' => true,
            'disk_percent' => 99, 'ram_percent' => 99, 'load' => 100, 'tls_days' => 1, 'backup_stale_hours' => 168,
        ]);
        Setting::putSecret('telegram.bot_token', '123456:'.str_repeat('A', 35));
        Setting::put('telegram.chat_id', '11111');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['supervisor'] = ['unit' => 'supervisor'];
        $fake->vhosts[] = ['domain' => 'shop.test', 'domains' => ['shop.test'], 'runtime' => 'pm2', 'pm2_program' => 'pm2-shop-test', 'readonly' => false];
        $fake->supervisorPrograms['pm2-shop-test'] = ['state' => 'fatal', 'status_raw' => 'FATAL'];

        $eval = $this->app->make(AlertEvaluator::class);

        $first = $eval->run();
        $this->assertSame(1, $first['opened']);
        $this->assertSame(1, $first['notified']);

        // Worker recovers → the incident resolves.
        $fake->supervisorPrograms['pm2-shop-test'] = ['state' => 'running', 'status_raw' => 'RUNNING'];
        $second = $eval->run();
        $this->assertSame(0, $second['opened']);
        $this->assertSame(1, $second['resolved']);
    }

    public function test_telegram_test_does_not_echo_token(): void
    {
        $this->actingAs($this->admin());
        Setting::putSecret('telegram.bot_token', '123456:'.str_repeat('B', 35));
        Setting::put('telegram.chat_id', '11111');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->get('/alerts')->assertOk()->assertDontSee('123456:', false);

        \Livewire\Livewire::test(\App\Livewire\AlertsPage::class)
            ->call('testTelegram')
            ->assertSet('flash', 'Test message sent.')
            ->assertDontSee(str_repeat('B', 35), false);
    }

    private function admin(): User
    {
        $totp = new TotpService();
        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
