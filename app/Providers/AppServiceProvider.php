<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Crypto\KeyEncryptionService::class, function () {
            return \App\Services\Crypto\KeyEncryptionService::fromConfig();
        });

        $this->app->singleton(\App\Services\Tatum\TatumOutboundTxService::class, function ($app) {
            return new \App\Services\Tatum\TatumOutboundTxService(
                $app->make(\App\Services\Crypto\KeyEncryptionService::class)
            );
        });
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
