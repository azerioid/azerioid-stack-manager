<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Initial setup · AZERIOID Stack Manager')]
class SetupWizard extends Component
{
    public string $name = 'Admin';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public int $step = 1;
    public string $code = '';
    public string $secret = '';
    public string $qr = '';

    public function mount(TotpService $totp): void
    {
        if (User::query()->exists()) {
            $this->redirectRoute('login', navigate: true);
        }
    }

    public function createAccount(TotpService $totp): void
    {
        if (User::query()->exists()) {
            abort(403);
        }
        $this->email = strtolower(trim($this->email));
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $user = User::query()->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        Auth::login($user);
        session()->regenerate();
        session()->put('last_activity_at', time());

        if (! config('azerioid.require_totp')) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $this->secret = $totp->generateSecret();
        $totp->storeUnconfirmed($user, $this->secret);
        $this->qr = $totp->qrSvg($user->email, $this->secret);
        $this->step = 2;
    }

    public function confirmTwoFactor(TotpService $totp): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $this->validate(['code' => ['required', 'digits:6']]);
        $secret = $user->plainTwoFactorSecret() ?: $this->secret;
        if (! $totp->verify($secret, $this->code)) {
            $this->addError('code', 'That code was not valid.');
            return;
        }
        $totp->confirm($user);
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.setup-wizard');
    }
}
