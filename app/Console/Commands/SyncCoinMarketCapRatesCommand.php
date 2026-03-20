<?php

namespace App\Console\Commands;

use App\Services\Rates\CoinMarketCapRateService;
use Illuminate\Console\Command;

class SyncCoinMarketCapRatesCommand extends Command
{
    protected $signature = 'crypto:sync-cmc-rates';

    protected $description = 'Fetch latest USD prices from CoinMarketCap and update wallet_currencies.rate / naira_price';

    public function handle(CoinMarketCapRateService $service): int
    {
        try {
            $r = $service->syncWalletCurrencyRates();
            $this->info("Updated: {$r['updated']}, skipped: {$r['skipped']}");
            foreach ($r['errors'] as $err) {
                $this->warn($err);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
