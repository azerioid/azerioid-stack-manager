<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\PanelAuthenticator;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * A83: one-time recovery codes — generation, consumption, and login via a code.
 */
class RecoveryCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_are_generated_stored_and_consumed_once(): void
    {
        $totp = app(TotpService::class);
        $user = $this->enrolledUser();

        $codes = $totp->generateRecoveryCodes();
        $this->assertCount(8, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));

        $totp->storeRecoveryCodes($user, $codes);
        $this->assertCount(8, $totp->recoveryCodes($user->refresh()));

        // Consume one (case/dash-insensitive), it is removed, and cannot be reused.
        $formatted = strtolower(substr($codes[0], 0, 5).'-'.substr($codes[0], 5));
        $this->assertTrue($totp->consumeRecoveryCode($user, $formatted));
        $this->assertCount(7, $totp->recoveryCodes($user->refresh()));
        $this->assertFalse($totp->consumeRecoveryCode($user->refresh(), $codes[0]));

        // An unknown code is rejected.
        $this->assertFalse($totp->consumeRecoveryCode($user->refresh(), 'FFFFFFFFFF'));
    }

    public function test_challenge_accepts_a_recovery_code_in_place_of_totp(): void
    {
        $totp = app(TotpService::class);
        $user = $this->enrolledUser();
        $codes = $totp->generateRecoveryCodes();
        $totp->storeRecoveryCodes($user, $codes);

        session(['login.id' => $user->id]);
        $auth = app(PanelAuthenticator::class);

        $this->assertTrue($auth->verifyChallengeCode($codes[0], '127.0.0.1'));
        $this->assertAuthenticatedAs($user);

        // The used code is spent; a fresh pending challenge rejects it.
        session(['login.id' => $user->id]);
        $this->assertFalse($auth->verifyChallengeCode($codes[0], '127.0.0.1'));
    }

    public function test_re_enrollment_issues_fresh_codes_and_invalidates_the_old_set(): void
    {
        $totp = app(TotpService::class);
        $auth = app(PanelAuthenticator::class);

        // First enrollment: an unconfirmed secret, confirmed with a valid code.
        $user = User::factory()->create();
        $secret1 = $totp->generateSecret();
        $totp->storeUnconfirmed($user, $secret1);
        $this->assertTrue($auth->confirmEnrollment($user->refresh(), (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret1)));
        $firstCodes = $totp->recoveryCodes($user->refresh());
        $this->assertCount(8, $firstCodes);

        // Re-enroll with a new secret; confirming must replace the old recovery set.
        $secret2 = $totp->beginReset($user);
        $this->assertTrue($auth->confirmEnrollment($user->refresh(), (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret2)));
        $secondCodes = $totp->recoveryCodes($user->refresh());
        $this->assertCount(8, $secondCodes);
        $this->assertEmpty(array_intersect($firstCodes, $secondCodes), 'old codes must not carry over');

        // An old code no longer works.
        $this->assertFalse($totp->consumeRecoveryCode($user->refresh(), $firstCodes[0]));
    }

    private function enrolledUser(): User
    {
        $totp = app(TotpService::class);

        return User::factory()->create([
            'two_factor_secret' => Crypt::encryptString($totp->generateSecret()),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
