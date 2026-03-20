<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\WalletCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWalletCurrencyController extends Controller
{
    /**
     * Override USD price per unit for a wallet currency row (until next CMC sync overwrites).
     */
    public function updateRate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rate' => 'required|numeric|min:0',
        ]);

        $wc = WalletCurrency::query()->findOrFail($id);
        $rate = (float) $data['rate'];
        $ngnPerUsd = (float) config('crypto.ngn_per_usd', 1500);

        $wc->rate = (string) $rate;
        $wc->price = (string) $rate;
        $wc->naira_price = (string) ($rate * $ngnPerUsd);
        $wc->save();

        return ResponseHelper::success($wc->fresh(), 'Wallet currency rate updated.');
    }
}
