<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SmokePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pages_render_expected_copy(): void
    {
        $this->actingAs($this->admin());

        $this->get('/')->assertOk()
            ->assertSee('MariaDB is listening on 0.0.0.0:3306', false)
            ->assertDontSee('RoadRunner', false)
            ->assertSee('Caddy', false)
            ->assertSee('php8.2-fpm', false)
            ->assertSee('journalctl -xeu php8.2-fpm', false);

        $this->get('/vhosts')->assertOk()
            ->assertSee('projob.az', false)
            ->assertSee('read-only', false);

        $this->get('/databases')->assertOk()->assertSee('lacmp_panel', false);
        $this->get('/services')->assertOk()->assertSee('observed', false);
        $this->get('/logs')->assertOk()->assertSee('Web server access', false);
        $this->get('/settings')->assertOk()
            ->assertSee('Idle timeout', false)
            ->assertSee('Panel domain', false);
        $this->get('/audit')->assertOk()->assertSee('Audit', false);
        $this->get('/alerts')->assertOk()->assertSee('Telegram', false);
        $this->get('/updates')->assertOk()
            ->assertSee('Pending OS packages', false)
            ->assertSee('Reboot required', false);
        $this->get('/backups')->assertOk()->assertSee('DigitalOcean Spaces', false);
        $this->get('/security')->assertOk()->assertSee('SSH / auth.log', false);
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
