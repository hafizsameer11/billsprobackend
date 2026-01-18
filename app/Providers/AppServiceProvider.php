<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        // Force HTTPS URLs in production or when behind proxy
        if (config('app.env') === 'production' || env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        } elseif (request()->header('X-Forwarded-Proto') === 'https' || 
                   request()->server('HTTP_X_FORWARDED_PROTO') === 'https') {
            // Auto-detect HTTPS from proxy headers
            URL::forceScheme('https');
        }
    }
}
