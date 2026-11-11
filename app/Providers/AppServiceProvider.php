<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request lifecycle.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Loudly catch mass-assignment attempts during development/testing.
        Model::preventSilentlyDiscardingAttributes((bool) config('app.debug'));

        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters. Authentication and administrative endpoints get
     * deliberately stricter limits than ordinary API traffic.
     */
    private function configureRateLimiting(): void
    {
        // Signature must match what RateLimiter invokes: (Request, array $headers).
        $limited = fn (Request $request, array $headers) =>
            response()->json(['message' => 'Too many attempts. Please slow down.', 'code' => 'rate_limited'], 429, $headers);

        RateLimiter::for('auth.register', fn (Request $request) => [
            Limit::perHour(10)->by($request->ip())->response($limited),
        ]);

        // Route-level net; the controller adds per-email+IP lockout on top.
        RateLimiter::for('auth.login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip())->response($limited),
        ]);

        RateLimiter::for('auth.reset', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip())->response($limited),
        ]);

        RateLimiter::for('sa.auth', fn (Request $request) => [
            Limit::perMinute(8)->by($request->ip())->response($limited),
        ]);

        RateLimiter::for('tenant.api', fn (Request $request) => [
            Limit::perMinute(120)->by($request->user()?->id ?: $request->ip())->response($limited),
        ]);

        RateLimiter::for('sa.api', fn (Request $request) => [
            Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())->response($limited),
        ]);

        RateLimiter::for('payments.submit', fn (Request $request) => [
            Limit::perMinute(10)->by('pay:'.($request->user()?->id ?: $request->ip()))->response($limited),
        ]);

        RateLimiter::for('impersonate', fn (Request $request) => [
            Limit::perHour(10)->by('imp:'.($request->user()?->id ?: $request->ip()))->response($limited),
        ]);

        RateLimiter::for('uploads', fn (Request $request) => [
            Limit::perMinute(30)->by('upl:'.($request->user()?->id ?: $request->ip()))->response($limited),
        ]);
    }
}
