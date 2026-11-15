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
        // Public form submissions spend the owner's AI credits: a few per minute per visitor.
        RateLimiter::for('public-form', fn (Request $request) => [
            Limit::perMinute(5)->by('form-min:'.$request->ip()),
            Limit::perHour(20)->by('form-hour:'.$request->ip()),
        ]);
    }
}
