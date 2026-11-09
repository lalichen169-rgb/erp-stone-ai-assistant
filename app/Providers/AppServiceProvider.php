<?php

namespace App\Providers;

use App\Auth\ErpGateRegistrar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        ErpGateRegistrar::register();

        RateLimiter::for('api-login', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('api-assistant', function (Request $request) {
            $perMinute = max(1, (int) config('ai.assistant.rate_limit_per_minute', 60));
            $user = $request->user();

            return Limit::perMinute($perMinute)->by($user?->id !== null ? 'user:'.$user->id : 'guest:'.$request->ip());
        });
    }
}
