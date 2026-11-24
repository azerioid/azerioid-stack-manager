<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Broker\FakeBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    public function test_fake_broker_rejects_readonly_vhost_terminal(): void
    {
        $fake = $this->app->make(FakeBroker::class);
        $res = $fake->handle('terminal.session.start', ['site1.example.com'], [
            'admin_user_id' => '1',
            'source_ip' => '127.0.0.1',
        ]);
        $this->assertFalse($res->ok);
        $this->assertStringContainsString('read-only', strtolower((string) $res->error));
    }

    public function test_terminal_auth_requires_matching_admin(): void
    {
        $user = $this->admin();
        $fake = $this->app->make(FakeBroker::class);
        $start = $fake->handle('terminal.session.start', ['shop.example.com'], [
            'admin_user_id' => (string) $user->id,
            'source_ip' => '127.0.0.1',
        ]);
        $sessionId = (string) ($start->data['session_id'] ?? '');
        $this->actingAs($user);
        $this->get('/internal/terminal/auth/' . $sessionId)->assertOk();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->get('/internal/terminal/auth/' . $sessionId)->assertForbidden();
    }
}
