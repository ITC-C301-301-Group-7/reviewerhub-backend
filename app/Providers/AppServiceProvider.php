<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sign-in: 5 tries/minute per account + IP (stops password guessing),
        // plus a generous per-IP cap because many students share campus Wi-Fi.
        RateLimiter::for('login', function (Request $request) {
            $identifier = Str::lower((string) $request->input('identifier'));

            return [
                Limit::perMinute(5)->by($identifier . '|' . $request->ip()),
                Limit::perMinute(60)->by($request->ip()),
            ];
        });

        // Registration: limit mass account creation from a single IP.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
