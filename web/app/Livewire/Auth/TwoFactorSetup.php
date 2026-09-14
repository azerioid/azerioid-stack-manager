<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Auth\PanelAuthenticator;
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

    public function mount(PanelAuthenticator $auth): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        if ($user->hasTwoFactorEnabled()) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }
        $payload = $auth->enrollmentPayload($user);
        $this->secret = $payload['secret'];
        $this->qr = $payload['qr'];
    }

    public function skip(): void
    {
        abort_if(config('azerioid.require_totp'), 403);
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function confirm(PanelAuthenticator $auth): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $this->validate(['code' => ['required', 'digits:6']]);
        if (! $auth->confirmEnrollment($user, $this->secret, $this->code)) {
            $this->addError('code', 'That code was not valid.');

            return;
        }
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.two-factor-setup');
    }
}
