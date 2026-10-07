<div>
    @if ($useRecovery)
        <h2 class="mb-1 text-base font-medium text-zinc-100">Recovery code</h2>
        <p class="mb-6 text-sm text-zinc-500">Enter one of the one-time recovery codes you saved when you set up two-factor. Each code works once.</p>
        <form wire:submit="verify" class="space-y-4">
            <input type="text" wire:model="code" maxlength="40" class="field text-center font-mono tracking-[0.2em]" placeholder="XXXXX-XXXXX" autofocus autocomplete="one-time-code">
            @error('code') <p class="text-sm text-bad">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full">Verify</button>
        </form>
        <button type="button" wire:click="useAuthenticatorCode" class="mt-4 text-xs text-zinc-500 hover:text-zinc-300">Use an authenticator code instead</button>
    @else
        <h2 class="mb-1 text-base font-medium text-zinc-100">Authenticator code</h2>
        <p class="mb-6 text-sm text-zinc-500">Enter the 6-digit code from your TOTP app.</p>
        <form wire:submit="verify" class="space-y-4">
            <input type="text" inputmode="numeric" wire:model="code" maxlength="6" class="field text-center tracking-[0.4em]" placeholder="000000" autofocus autocomplete="one-time-code">
            @error('code') <p class="text-sm text-bad">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full">Verify</button>
        </form>
        <button type="button" wire:click="useRecoveryCode" class="mt-4 text-xs text-zinc-500 hover:text-zinc-300">Lost your device? Use a recovery code</button>
    @endif
</div>
