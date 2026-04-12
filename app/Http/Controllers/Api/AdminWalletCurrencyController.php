<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\CryptoExchangeRate;
use App\Models\WalletCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWalletCurrencyController extends Controller
{
    /**
     * List wallet currencies (for vendor linking, filters, admin forms).
     */
    public function index(Request $request): JsonResponse
    {
        $activeOnly = filter_var($request->query('active_only', true), FILTER_VALIDATE_BOOL);
        $q = WalletCurrency::query()
            ->select([
                'id', 'blockchain', 'currency', 'symbol', 'name',
                'contract_address', 'decimals', 'is_token', 'is_active',
            ])
            ->orderBy('blockchain')
            ->orderBy('currency');

        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return ResponseHelper::success($q->get(), 'Wallet currencies retrieved.');
    }

    /**
     * Update reference `rate` on wallet_currency and buy/sell on `crypto_exchange_rates`.
     */
    public function updateRate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rate' => 'sometimes|numeric|min:0',
            'rate_buy' => 'sometimes|numeric|min:0',
            'rate_sell' => 'sometimes|numeric|min:0',
        ]);

        if (! array_key_exists('rate', $data) && ! array_key_exists('rate_buy', $data) && ! array_key_exists('rate_sell', $data)) {
            return ResponseHelper::error('Provide at least one of: rate, rate_buy, rate_sell.', 422);
        }

        $wc = WalletCurrency::query()->findOrFail($id);
        $ngnPerUsd = (float) config('crypto.ngn_per_usd', 1500);

        $base = (float) ($wc->rate ?? 0);
        /** @var CryptoExchangeRate $ex */
        $ex = CryptoExchangeRate::query()->firstOrNew(
            ['wallet_currency_id' => $wc->id],
            [
                'rate_buy' => $base,
                'rate_sell' => $base,
            ]
        );

        if (array_key_exists('rate', $data)) {
            $r = (float) $data['rate'];
            $wc->rate = (string) $r;
            $wc->price = (string) $r;
            $wc->naira_price = (string) ($r * $ngnPerUsd);
            $ex->rate_buy = $r;
            $ex->rate_sell = $r;
        }
        if (array_key_exists('rate_buy', $data)) {
            $ex->rate_buy = (float) $data['rate_buy'];
        }
        if (array_key_exists('rate_sell', $data)) {
            $ex->rate_sell = (float) $data['rate_sell'];
        }

        $buy = (float) $ex->rate_buy;
        $sell = (float) $ex->rate_sell;
        if ($buy > 0 && $sell > 0) {
            $mid = ($buy + $sell) / 2.0;
            $wc->rate = (string) $mid;
            $wc->price = (string) $mid;
            $wc->naira_price = (string) ($mid * $ngnPerUsd);
        } elseif ($buy > 0) {
            $wc->rate = (string) $buy;
            $wc->price = (string) $buy;
            $wc->naira_price = (string) ($buy * $ngnPerUsd);
        } elseif ($sell > 0) {
            $wc->rate = (string) $sell;
            $wc->price = (string) $sell;
            $wc->naira_price = (string) ($sell * $ngnPerUsd);
        }

        $wc->save();
        $ex->wallet_currency_id = $wc->id;
        $ex->save();

        return ResponseHelper::success([
            'wallet_currency' => $wc->fresh(),
            'exchange_rate' => $ex->fresh(),
        ], 'Exchange rates updated.');
    }
}
