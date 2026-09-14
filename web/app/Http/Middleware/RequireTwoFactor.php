<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! config('azerioid.require_totp')) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.*', 'logout')) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        // Livewire 4 update endpoints are /livewire-{hash}/… (not plain /livewire/*).
        // Allow only the enrollment component until TOTP is confirmed.
        if ($this->isLivewireUpdateRequest($request) && $this->isTwoFactorSetupRequest($request)) {
            return $next($request);
        }

        return redirect()->route('two-factor.setup');
    }

    private function isLivewireUpdateRequest(Request $request): bool
    {
        return $request->is('livewire/*') || $request->is('livewire-*/*');
    }

    private function isTwoFactorSetupRequest(Request $request): bool
    {
        $snapshot = data_get($request->all(), 'components.0.snapshot');
        if (! is_string($snapshot) || $snapshot === '') {
            return false;
        }

        return str_contains($snapshot, 'TwoFactorSetup');
    }
}
