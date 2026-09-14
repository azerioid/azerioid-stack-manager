<?php

namespace App\Livewire\Auth;

use App\Services\Auth\ChallengeMountOutcome;
use App\Services\Auth\PanelAuthenticator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Two-factor · AZERIOID Stack Manager')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public function mount(PanelAuthenticator $auth): void
    {
        $pendingId = session()->has('login.id') ? (int) session('login.id') : null;
        $outcome = $auth->resolveChallengeMount($pendingId);

        match ($outcome) {
            ChallengeMountOutcome::ShowForm => null,
            ChallengeMountOutcome::Login => $this->redirectRoute('login', navigate: true),
            ChallengeMountOutcome::Dashboard => $this->redirectRoute('dashboard', navigate: true),
            ChallengeMountOutcome::TwoFactorSetup => $this->redirectRoute('two-factor.setup', navigate: true),
        };
    }

    public function verify(PanelAuthenticator $auth): void
    {
        $this->validate(['code' => ['required', 'digits:6']]);

        if (! session()->has('login.id')) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $userStillPending = \App\Models\User::query()->find(session('login.id'));
        if (! $userStillPending || ! $userStillPending->hasTwoFactorEnabled()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        if (! $auth->verifyChallengeCode($this->code, (string) request()->ip())) {
            $this->addError('code', 'That code was not valid.');

            return;
        }

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
