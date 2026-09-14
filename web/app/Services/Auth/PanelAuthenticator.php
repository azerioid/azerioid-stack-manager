<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\TotpService;
use App\Support\AuthFailLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Panel login / TOTP challenge orchestration (credential checks, lockout,
 * fail2ban auth-fail lines, session establishment, enroll-vs-verify routing).
 * Livewire components stay presentation-only.
 */
final class PanelAuthenticator
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    /**
     * Password step after the form has already validated email/password presence.
     *
     * @throws ValidationException
     */
    public function attemptPassword(string $email, string $password, string $ip): LoginOutcome
    {
        $email = strtolower(trim($email));
        $password = trim($password);

        $key = $this->rateLimitKey($email, $ip);
        if (RateLimiter::tooManyAttempts($key, (int) config('azerioid.login.max_attempts', 5))) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again shortly.',
            ]);
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user?->isLocked()) {
            throw ValidationException::withMessages([
                'email' => 'This account is locked. Try again later.',
            ]);
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, (int) config('azerioid.login.decay_seconds', 60));
            AuthFailLog::write($ip);
            if ($user) {
                $user->increment('failed_logins');
                if ($user->failed_logins >= (int) config('azerioid.login.lockout_attempts', 10)) {
                    $user->forceFill([
                        'locked_until' => now()->addMinutes((int) config('azerioid.login.lockout_minutes', 15)),
                    ])->save();
                }
            }
            throw ValidationException::withMessages(['email' => 'Those credentials do not match.']);
        }

        RateLimiter::clear($key);
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();

        // Challenge whenever this account is enrolled — including optional TOTP
        // (PANEL_REQUIRE_TOTP=false). Instance policy only forces enrollment.
        if ($user->hasTwoFactorEnabled()) {
            session(['login.id' => $user->id]);

            return LoginOutcome::TwoFactorChallenge;
        }

        $this->establishSession($user, $ip);

        if ($user->mustEnrollTwoFactor()) {
            return LoginOutcome::TwoFactorSetup;
        }

        return LoginOutcome::Dashboard;
    }

    /**
     * Resolve /two-factor/challenge when a pending login.id may be stale or unenrolled.
     */
    public function resolveChallengeMount(?int $pendingUserId): ChallengeMountOutcome
    {
        if (Auth::check()) {
            return ChallengeMountOutcome::Dashboard;
        }
        if ($pendingUserId === null) {
            return ChallengeMountOutcome::Login;
        }

        $pending = User::query()->find($pendingUserId);
        if (! $pending instanceof User) {
            session()->forget('login.id');

            return ChallengeMountOutcome::Login;
        }
        if (! $pending->hasTwoFactorEnabled()) {
            session()->forget('login.id');
            $this->establishSession($pending, (string) request()->ip());
            if ($pending->mustEnrollTwoFactor()) {
                return ChallengeMountOutcome::TwoFactorSetup;
            }

            return ChallengeMountOutcome::Dashboard;
        }

        return ChallengeMountOutcome::ShowForm;
    }

    /**
     * Verify TOTP for a pending login.id. On success, establishes the session.
     *
     * @return bool true when the code is valid and the session was established
     */
    public function verifyChallengeCode(string $code, string $ip): bool
    {
        $user = User::query()->find(session('login.id'));
        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return false;
        }
        $secret = $user->plainTwoFactorSecret();
        if ($secret === null || ! $this->totp->verify($secret, $code)) {
            return false;
        }
        $this->establishSession($user, $ip);
        session()->forget('login.id');

        return true;
    }

    /**
     * Ensure an authenticated user has a pending (or new) enrollment secret + QR.
     *
     * @return array{secret: string, qr: string}
     */
    public function enrollmentPayload(User $user): array
    {
        $existing = $user->plainTwoFactorSecret();
        $secret = $existing ?: $this->totp->generateSecret();
        if ($existing === null) {
            $this->totp->storeUnconfirmed($user, $secret);
        }

        return [
            'secret' => $secret,
            'qr' => $this->totp->qrSvg($user->email, $secret),
        ];
    }

    /** Confirm enrollment for the authenticated user against the stored secret only. */
    public function confirmEnrollment(User $user, string $code): bool
    {
        $secret = $user->plainTwoFactorSecret();
        if ($secret === null || ! $this->totp->verify($secret, $code)) {
            return false;
        }
        $this->totp->confirm($user);

        return true;
    }

    public function establishSession(User $user, string $ip): void
    {
        Auth::login($user);
        session()->forget('login.id');
        session()->regenerate();
        session()->put('last_activity_at', time());
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
    }

    private function rateLimitKey(string $email, string $ip): string
    {
        return 'login:'.$email.'|'.$ip;
    }
}
