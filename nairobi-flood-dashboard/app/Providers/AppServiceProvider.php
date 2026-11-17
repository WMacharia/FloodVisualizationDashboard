<?php

namespace App\Providers;

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
        // Each limit has its own counter. (A plain 'throttle:10,1' shares one counter per visitor
        // across every route, so the map's own API calls would use up the sign-in allowance.)
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('sign-in', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('reconfirm', fn (Request $r) => Limit::perMinute(5)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('activity', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));
    }
}
