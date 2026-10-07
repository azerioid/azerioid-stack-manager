<?php

use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\IdleTimeout;
use App\Http\Middleware\IpAllowlist;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Livewire\AuditLogPage;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\SetupWizard;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Auth\TwoFactorSetup;
use App\Livewire\Dashboard;
use App\Livewire\DatabasesPage;
use App\Livewire\LogsPage;
use App\Livewire\ServicesPage;
use App\Livewire\SettingsPage;
use App\Livewire\VhostsPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1'))
            ))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
        );
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [
            IdleTimeout::class,
            IpAllowlist::class,
            EnsureSetupComplete::class,
        ]);
        $middleware->alias([
            '2fa' => RequireTwoFactor::class,
        ]);
        $middleware->redirectGuestsTo(function () {
            return \App\Models\User::query()->exists() ? route('login') : route('setup');
        });
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        $middleware->validateCsrfTokens(except: [
            // A78: the push-to-deploy webhook is an external POST from GitHub/GitLab;
            // it is authenticated by an HMAC signature the broker verifies, not CSRF.
            'hooks/deploy/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
