<?php

namespace App\Providers;

use App\Services\Broker\BrokerClient;
use App\Services\Broker\FakeBroker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeBroker::class, fn () => new FakeBroker());
        $this->app->singleton(BrokerClient::class, fn ($app) => new BrokerClient($app->make(FakeBroker::class)));
    }

    public function boot(): void
    {
        // Same-origin relative asset URLs — inherit the browser's self-signed trust exception
        // for whatever host the operator opened (public IP or SSH tunnel), avoid APP_URL mismatch.
        if (method_exists(Vite::class, 'useRelativePaths')) {
            Vite::useRelativePaths();
        }

        RateLimiter::for('login', function (Request $request) {
            $max = (int) config('azerioid.login.max_attempts', 5);
            return Limit::perMinute($max)->by(strtolower((string) $request->input('email')) . '|' . $request->ip());
        });

        config(['livewire.temporary_file_upload.rules' => ['file', 'max:20480']]);
    }
}
