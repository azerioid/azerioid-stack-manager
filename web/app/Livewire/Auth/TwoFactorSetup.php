<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Enable 2FA · AZERIOID Stack Manager')]
class TwoFactorSetup extends Component
{
    public string $code = '';
    public string $secret = '';
    public string $qr = '';

    public function mount(TotpService $totp): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        if ($user->hasTwoFactorEnabled()) {
            $this->redirectRoute('dashboard', navigate: true);
            return;
        }
        $existing = $user->plainTwoFactorSecret();
        $this->secret = $existing ?: $totp->generateSecret();
        if ($existing === null) {
            $totp->storeUnconfirmed($user, $this->secret);
        }
        $this->qr = $totp->qrSvg($user->email, $this->secret);
    }

    public function skip(): void
    {
        abort_if(config('azerioid.require_totp'), 403);
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function confirm(TotpService $totp): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $this->validate(['code' => ['required', 'digits:6']]);
        if (! $totp->verify($this->secret, $this->code)) {
            $this->addError('code', 'That code was not valid.');
            return;
        }
        $totp->confirm($user);
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.two-factor-setup');
    }
}
