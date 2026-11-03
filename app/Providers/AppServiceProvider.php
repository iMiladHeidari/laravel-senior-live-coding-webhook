<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
        // Lazy loading (N+1), missing selected columns and unfillable attributes throw outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('fiat-webhook', function (Request $request) {
            $token = (string) $request->header('X-Webhook-Token');

            return [
                Limit::perMinute(60)->by('token:'.hash('sha256', $token)),
                Limit::perMinute(300)->by('ip:'.$request->ip()),
            ];
        });
    }
}
