<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CsrfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_emit_a_csrf_token(): void
    {
        $this->get('/setup')->assertOk()->assertSee('csrf-token', false);
    }

    public function test_logout_is_not_callable_via_get(): void
    {
        $totp = new TotpService();
        $user = User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);
        $this->get('/logout')->assertStatus(405);
    }

    public function test_csrf_middleware_is_on_the_web_stack(): void
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $web = $kernel->getMiddlewareGroups()['web'] ?? [];
        $this->assertTrue(
            collect($web)->contains(fn ($m) => is_string($m) && (
                str_contains($m, 'PreventRequestForgery')
                || str_contains($m, 'ValidateCsrfToken')
                || str_contains($m, 'VerifyCsrfToken')
            ))
            || collect($web)->contains(PreventRequestForgery::class)
            || collect($web)->contains(ValidateCsrfToken::class)
        );
    }
}
