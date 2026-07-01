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
        if ($this->shouldEnforceProductionConfig()) {
            if (config('app.debug')) {
                throw new \RuntimeException('APP_DEBUG must be false in production.');
            }
            if (! config('palmpay.verify_webhook_signature', true)) {
                throw new \RuntimeException('PALMPAY_VERIFY_WEBHOOK_SIGNATURE must be true in production.');
            }
            if (config('admin.webhook_replay_enabled')) {
                throw new \RuntimeException('ADMIN_WEBHOOK_REPLAY_ENABLED must be false in production.');
            }
            foreach (['ENCRYPTION_KEY', 'TATUM_API_KEY', 'PALMPAY_APP_ID'] as $key) {
                if (empty(env($key))) {
                    throw new \RuntimeException("{$key} must be set in production.");
                }
            }
        }

        // Force HTTPS URLs in production or when behind proxy
        if (config('app.env') === 'production' || env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        } elseif (request()->header('X-Forwarded-Proto') === 'https' ||
                   request()->server('HTTP_X_FORWARDED_PROTO') === 'https') {
            // Auto-detect HTTPS from proxy headers
            URL::forceScheme('https');
        }
    }

    /**
     * Skip strict production checks during Docker image build / composer autoload scripts.
     */
    protected function shouldEnforceProductionConfig(): bool
    {
        if (! app()->environment('production') || app()->environment('testing')) {
            return false;
        }

        if (app()->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? '';
            if (in_array($command, ['package:discover', 'vendor:publish', 'clear-compiled'], true)) {
                return false;
            }
        }

        return true;
    }
}
