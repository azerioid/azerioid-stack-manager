<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['azerioid.require_totp' => false]);

        return User::factory()->create([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    public function test_auth_check_requires_loopback_and_session(): void
    {
        $user = $this->admin();
        $this->get('/internal/auth-check')->assertUnauthorized();

        $this->actingAs($user);
        $this->get('/internal/auth-check', ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();

        $this->get('/internal/auth-check', ['REMOTE_ADDR' => '10.0.0.5'])->assertForbidden();
    }

    public function test_auth_check_returns_401_after_logout(): void
    {
        $user = $this->admin();
        $this->actingAs($user);
        $this->get('/internal/auth-check', ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();

        $this->post('/logout');
        $this->get('/internal/auth-check', ['REMOTE_ADDR' => '127.0.0.1'])->assertUnauthorized();
    }

    public function test_sidebar_shows_database_admin_when_adminer_installed(): void
    {
        $user = $this->admin();
        $fake = $this->app->make(FakeBroker::class);
        $fake->fakeInstalledComponents['adminer'] = true;

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Database Admin')
            ->assertSee('/tools/adminer');
    }

    public function test_sidebar_hides_database_admin_when_not_installed(): void
    {
        $user = $this->admin();
        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Database Admin');
    }
}
