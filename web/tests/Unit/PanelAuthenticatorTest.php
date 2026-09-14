<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\ChallengeMountOutcome;
use App\Services\Auth\LoginOutcome;
use App\Services\Auth\PanelAuthenticator;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PanelAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_only_login_reaches_dashboard(): void
    {
        config(['azerioid.require_totp' => false]);
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $outcome = $this->auth()->attemptPassword($user->email, 'password', '127.0.0.1');

        $this->assertSame(LoginOutcome::Dashboard, $outcome);
        $this->assertAuthenticatedAs($user);
    }

    public function test_unenrolled_required_totp_routes_to_setup_not_challenge(): void
    {
        config(['azerioid.require_totp' => true]);
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $outcome = $this->auth()->attemptPassword($user->email, 'password', '127.0.0.1');

        $this->assertSame(LoginOutcome::TwoFactorSetup, $outcome);
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_enrolled_user_routes_to_challenge(): void
    {
        config(['azerioid.require_totp' => true]);
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = User::factory()->create([
            'email' => 'enrolled@example.com',
            'password' => 'password',
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $outcome = $this->auth()->attemptPassword($user->email, 'password', '127.0.0.1');

        $this->assertSame(LoginOutcome::TwoFactorChallenge, $outcome);
        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_wrong_totp_code_fails_with_same_semantics(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = User::factory()->create([
            'email' => 'enrolled@example.com',
            'password' => 'password',
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);
        session(['login.id' => $user->id]);

        $ok = $this->auth()->verifyChallengeCode('000000', '127.0.0.1');

        $this->assertFalse($ok);
        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_lockout_after_configured_failed_logins(): void
    {
        config([
            'azerioid.login.lockout_attempts' => 3,
            'azerioid.login.max_attempts' => 50,
            'azerioid.login.lockout_minutes' => 15,
        ]);
        RateLimiter::clear('login:admin@example.com|127.0.0.1');
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->auth()->attemptPassword($user->email, 'wrong', '127.0.0.1');
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame(['Those credentials do not match.'], $e->errors()['email']);
            }
        }

        $user->refresh();
        $this->assertTrue($user->isLocked());

        try {
            $this->auth()->attemptPassword($user->email, 'password', '127.0.0.1');
            $this->fail('Expected lockout ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['This account is locked. Try again later.'], $e->errors()['email']);
        }
    }

    public function test_failed_password_writes_auth_fail_log_line(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        $log = storage_path('logs/auth-fail.log');
        @unlink($log);

        try {
            $this->auth()->attemptPassword($user->email, 'wrong', '203.0.113.9');
        } catch (ValidationException) {
        }

        $this->assertFileExists($log);
        $this->assertStringContainsString('AZERIOID_PANEL_AUTH_FAIL ip=203.0.113.9', (string) file_get_contents($log));
    }

    public function test_challenge_mount_resumes_show_form_when_pending_enrolled(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = User::factory()->create([
            'email' => 'enrolled@example.com',
            'password' => 'password',
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $outcome = $this->auth()->resolveChallengeMount($user->id);

        $this->assertSame(ChallengeMountOutcome::ShowForm, $outcome);
        $this->assertGuest();
    }

    public function test_challenge_mount_without_login_id_sends_to_login(): void
    {
        $this->assertSame(
            ChallengeMountOutcome::Login,
            $this->auth()->resolveChallengeMount(null)
        );
    }

    public function test_challenge_mount_with_missing_user_clears_session_and_sends_to_login(): void
    {
        session(['login.id' => 99999]);

        $outcome = $this->auth()->resolveChallengeMount(99999);

        $this->assertSame(ChallengeMountOutcome::Login, $outcome);
        $this->assertFalse(session()->has('login.id'));
    }

    public function test_challenge_mount_when_totp_disabled_mid_login_goes_to_dashboard(): void
    {
        config(['azerioid.require_totp' => false]);
        $user = User::factory()->create([
            'email' => 'was-enrolled@example.com',
            'password' => 'password',
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        session(['login.id' => $user->id]);

        $outcome = $this->auth()->resolveChallengeMount($user->id);

        $this->assertSame(ChallengeMountOutcome::Dashboard, $outcome);
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('login.id'));
    }

    public function test_challenge_mount_unenrolled_required_totp_recovers_to_setup(): void
    {
        config(['azerioid.require_totp' => true]);
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'password' => 'password',
        ]);
        session(['login.id' => $user->id]);

        $outcome = $this->auth()->resolveChallengeMount($user->id);

        $this->assertSame(ChallengeMountOutcome::TwoFactorSetup, $outcome);
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('login.id'));
    }

    public function test_challenge_mount_when_already_authenticated_goes_to_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        $this->actingAs($user);

        $this->assertSame(
            ChallengeMountOutcome::Dashboard,
            $this->auth()->resolveChallengeMount($user->id)
        );
    }

    private function auth(): PanelAuthenticator
    {
        return new PanelAuthenticator(new TotpService());
    }
}
