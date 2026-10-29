<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Crypto\OpenBaoTransitKeyManager;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Crypto\WorkspaceKeyManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WorkspaceKeyManager::class, OpenBaoTransitKeyManager::class);
        // Scoped: unwrapped data keys live only for one request or queued job.
        $this->app->scoped(WorkspaceFieldCipher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ini_set('zend.exception_ignore_args', '1');
        // The worker resets scoped instances only before the next job; drop
        // unwrapped data keys as soon as a job ends so an idle worker holds none.
        Queue::after(fn () => $this->app->forgetScopedInstances());
        Queue::failing(fn () => $this->app->forgetScopedInstances());
        RateLimiter::for('telegram-auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('workspace-api', fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()->id));
        RateLimiter::for('account-deletion', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()->id));
        // Each upload is held and encrypted in memory (ADR-022).
        RateLimiter::for('asset-upload', fn (Request $request) => Limit::perMinute((int) config('karmandeman.assets.uploads_per_minute'))->by((string) $request->user()->id));
    }
}
