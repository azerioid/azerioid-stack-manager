<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Auth\LoginOutcome;
use App\Services\Auth\PanelAuthenticator;
use Illuminate\Support\Facades\Auth;
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

    public function authenticate(PanelAuthenticator $auth): void
    {
        $this->email = strtolower(trim($this->email));
        // Trim accidental whitespace from paste (password itself may contain spaces in the middle).
        $this->password = trim($this->password);

        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $outcome = $auth->attemptPassword($this->email, $this->password, (string) request()->ip());

        $this->redirectRoute($outcome->value, navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
