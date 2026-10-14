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
        RateLimiter::for('auth', function (Request $request): array {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(30)->by($request->ip()),
                Limit::perMinute(10)->by($email.'|'.$request->ip()),
            ];
        });

        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
    }
}
