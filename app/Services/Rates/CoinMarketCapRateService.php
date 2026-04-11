<?php

namespace App\Services\Rates;

use App\Models\CryptoExchangeRate;
use App\Models\WalletCurrency;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CoinMarketCapRateService
{
    /**
     * @return array{updated: int, skipped: int, errors: array<int, string>}
     */
    public function syncWalletCurrencyRates(): array
    {
        $key = (string) config('coinmarketcap.api_key', '');
        if ($key === '') {
            Log::warning('CoinMarketCapRateService: COINMARKETCAP_API_KEY not set; skipping sync');

            return ['updated' => 0, 'skipped' => 0, 'errors' => ['COINMARKETCAP_API_KEY not set']];
        }

        $map = (array) config('coinmarketcap.currency_to_symbol', []);
        $rows = WalletCurrency::query()->where('is_active', true)->get();

        $symbolToCurrencies = [];
        foreach ($rows as $wc) {
            $sym = $map[strtoupper((string) $wc->currency)] ?? strtoupper((string) $wc->currency);
            $symbolToCurrencies[$sym] = true;
        }

        $symbols = array_values(array_unique(array_keys($symbolToCurrencies)));
        if ($symbols === []) {
            return ['updated' => 0, 'skipped' => 0, 'errors' => []];
        }

        $quotes = $this->fetchQuotesBySymbols($key, $symbols);

        $updated = 0;
        $skipped = 0;
        $errors = [];

        $ngnPerUsd = (float) config('crypto.ngn_per_usd', 1500);

        DB::transaction(function () use ($rows, $map, $quotes, $ngnPerUsd, &$updated, &$skipped, &$errors) {
            foreach ($rows as $wc) {
                $sym = $map[strtoupper((string) $wc->currency)] ?? strtoupper((string) $wc->currency);
                $priceUsd = $quotes[$sym] ?? null;
                if ($priceUsd === null || $priceUsd <= 0) {
                    $skipped++;
                    $errors[] = "No quote for {$wc->currency} (CMC symbol {$sym})";

                    continue;
                }

                $wc->rate = (string) $priceUsd;
                $wc->naira_price = (string) ($priceUsd * $ngnPerUsd);
                $wc->price = (string) $priceUsd;
                $wc->save();

                CryptoExchangeRate::query()->updateOrCreate(
                    ['wallet_currency_id' => $wc->id],
                    ['rate_buy' => $priceUsd, 'rate_sell' => $priceUsd]
                );

                $updated++;
            }
        });

        if ($errors !== []) {
            Log::warning('CoinMarketCapRateService: partial sync', ['errors' => $errors]);
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<string, float> symbol => USD price
     */
    protected function fetchQuotesBySymbols(string $apiKey, array $symbols): array
    {
        $base = (string) config('coinmarketcap.base_url');
        $timeout = (int) config('coinmarketcap.timeout', 30);

        $out = [];
        foreach (array_chunk($symbols, 40) as $chunk) {
            $symbolParam = implode(',', $chunk);

            try {
                $response = Http::withHeaders([
                    'X-CMC_PRO_API_KEY' => $apiKey,
                    'Accept' => 'application/json',
                ])->timeout($timeout)->get($base.'/v1/cryptocurrency/quotes/latest', [
                    'symbol' => $symbolParam,
                    'convert' => 'USD',
                ]);

                $response->throw();
                $data = $response->json();
            } catch (RequestException $e) {
                throw new RuntimeException(
                    'CoinMarketCap quotes failed: '.($e->response?->body() ?? $e->getMessage()),
                    0,
                    $e
                );
            }

            $list = $data['data'] ?? [];
            foreach ($list as $entry) {
                $sym = strtoupper((string) ($entry['symbol'] ?? ''));
                $price = (float) ($entry['quote']['USD']['price'] ?? 0);
                if ($sym !== '' && $price > 0) {
                    $out[$sym] = $price;
                }
            }
        }

        return $out;
    }
}
