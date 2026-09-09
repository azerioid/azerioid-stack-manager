<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Sign in · AZERIOID Stack Manager')]
class Login extends Component
{
    public string $email = '';
    public string $password = '';

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirectRoute('dashboard', navigate: true);
        }
        if (! User::query()->exists()) {
            $this->redirectRoute('setup', navigate: true);
        }
    }

    public function authenticate(): void
    {
        $this->email = strtolower(trim($this->email));
        // Trim accidental whitespace from paste (password itself may contain spaces in the middle).
        $this->password = trim($this->password);

        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:' . $this->email . '|' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, (int) config('azerioid.login.max_attempts', 5))) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again shortly.',
            ]);
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$this->email])->first();
        if ($user?->isLocked()) {
            throw ValidationException::withMessages([
                'email' => 'This account is locked. Try again later.',
            ]);
        }

        if (! $user || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, (int) config('azerioid.login.decay_seconds', 60));
            \App\Support\AuthFailLog::write(request()->ip());
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
            $this->redirectRoute('two-factor.challenge', navigate: true);

            return;
        }

        Auth::login($user);
        session()->forget('login.id');
        session()->regenerate();
        session()->put('last_activity_at', time());
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();

        if ($user->mustEnrollTwoFactor()) {
            $this->redirectRoute('two-factor.setup', navigate: true);

            return;
        }

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
