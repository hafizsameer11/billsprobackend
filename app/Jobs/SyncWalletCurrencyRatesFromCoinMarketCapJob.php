<?php

namespace App\Jobs;

use App\Services\Rates\CoinMarketCapRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWalletCurrencyRatesFromCoinMarketCapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CoinMarketCapRateService $service): void
    {
        try {
            $r = $service->syncWalletCurrencyRates();
            Log::info('CoinMarketCap wallet currency sync', $r);
        } catch (Throwable $e) {
            Log::error('SyncWalletCurrencyRatesFromCoinMarketCapJob failed', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
