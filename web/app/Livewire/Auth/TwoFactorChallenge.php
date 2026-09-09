<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Two-factor · AZERIOID Stack Manager')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }
        if (! session()->has('login.id')) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $pending = User::query()->find(session('login.id'));
        if (! $pending instanceof User) {
            session()->forget('login.id');
            $this->redirectRoute('login', navigate: true);

            return;
        }
        if (! $pending->hasTwoFactorEnabled()) {
            session()->forget('login.id');
            Auth::login($pending);
            session()->regenerate();
            session()->put('last_activity_at', time());
            if ($pending->mustEnrollTwoFactor()) {
                $this->redirectRoute('two-factor.setup', navigate: true);

                return;
            }
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }
    }

    public function verify(TotpService $totp): void
    {
        $this->validate(['code' => ['required', 'digits:6']]);
        $user = User::query()->find(session('login.id'));
        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $this->redirectRoute('login', navigate: true);
            return;
        }
        $secret = $user->plainTwoFactorSecret();
        if ($secret === null || ! $totp->verify($secret, $this->code)) {
            $this->addError('code', 'That code was not valid.');
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
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
