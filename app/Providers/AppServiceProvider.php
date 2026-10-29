<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Auth\Notifications\ResetPassword;
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

        // Password reset: stops people from spamming reset emails.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinute(5)->by(Str::lower((string) $request->input('identifier', $request->input('email'))) . '|' . $request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // The reset email links to the React app, not to a Laravel page.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return config('reviewerhub.frontend_url')
                . '/?reset_token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        // Registration: limit mass account creation from a single IP.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
