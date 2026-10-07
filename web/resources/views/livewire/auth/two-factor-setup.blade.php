<div>
    @if ($recoveryCodes !== [])
        <h2 class="mb-1 text-base font-medium text-zinc-100">Save your recovery codes</h2>
        <p class="mb-4 text-sm text-zinc-500">Each code signs you in once if you lose your authenticator. Store them somewhere safe — they are shown only now.</p>
        <ul class="mb-5 grid grid-cols-2 gap-2 rounded-md bg-black/30 p-4 font-mono text-sm text-zinc-200">
            @foreach ($recoveryCodes as $rc)
                <li class="text-center tracking-[0.15em]">{{ $rc }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-primary w-full" wire:click="finish">I've saved these — continue</button>
    @else
    <h2 class="mb-1 text-base font-medium text-zinc-100">
        @if (config('azerioid.require_totp'))
            Two-factor is required
        @else
            Enroll authenticator (optional)
        @endif
    </h2>
    <p class="mb-4 text-sm text-zinc-500">
        @if (config('azerioid.require_totp'))
            This panel can restart services and drop databases. TOTP is required.
        @else
            TOTP is optional on this panel. You can enroll now or skip.
        @endif
    </p>
    <div class="mb-4 flex justify-center rounded-md bg-white p-3">{!! $qr !!}</div>
    <p class="mb-4 break-all text-center font-mono text-xs text-zinc-400">{{ $secret }}</p>
    <form wire:submit="confirm" class="space-y-4">
        <input type="text" inputmode="numeric" wire:model="code" maxlength="6" class="field text-center tracking-[0.4em]" placeholder="000000">
        @error('code') <p class="text-sm text-bad">{{ $message }}</p> @enderror
        <button type="submit" class="btn-primary w-full">Enable 2FA</button>
    </form>
    @unless (config('azerioid.require_totp'))
        <button type="button" class="btn-ghost mt-3 w-full" wire:click="skip">Skip</button>
    @endunless
    @endif
</div>
