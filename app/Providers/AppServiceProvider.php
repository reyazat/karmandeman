<?php

declare(strict_types=1);

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
        ini_set('zend.exception_ignore_args', '1');
        RateLimiter::for('telegram-auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('workspace-api', fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()->id));
    }
}
