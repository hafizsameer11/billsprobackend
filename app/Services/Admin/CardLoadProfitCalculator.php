<?php

namespace App\Services\Admin;

use App\Models\PagocardsWalletRecharge;
use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Services\Platform\PlatformRateResolver;
use Illuminate\Support\Carbon;

class CardLoadProfitCalculator
{
    public function __construct(
        protected PlatformRateResolver $rates,
    ) {}

    /**
     * @param  array<string, mixed>  $charges
     * @return array<string, mixed>|null
     */
    public function snapshotForNewFunding(
        array $charges,
        float $principalUsd,
        string $fundServiceKey = 'visa_fund',
    ): ?array {
        if (($charges['payment_wallet_type'] ?? '') !== 'naira_wallet') {
            return null;
        }

        $trueRate = $this->currentTrueRate();
        $customerRate = (float) ($charges['exchange_rate_ngn_per_usd'] ?? 0);
        $revenueNgn = (float) ($charges['charge_ngn'] ?? 0);
        $rate = $this->rates->findVirtualCard($fundServiceKey);

        if ($trueRate === null || $trueRate <= 0) {
            return [
                'source' => 'card_load_profit',
                'missing_true_rate' => true,
                'revenue_ngn' => round($revenueNgn, 2),
                'principal_usd' => round($principalUsd, 8),
                'customer_rate_ngn_per_usd' => $customerRate,
                'customer_revenue_ngn' => round($revenueNgn, 2),
                'provider_cost_ngn' => 0,
                'estimated_profit_ngn' => 0,
                'net_profit_ngn' => 0,
            ];
        }

        return $this->buildSnapshot($revenueNgn, $principalUsd, $trueRate, $customerRate, $rate);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function computeForFundingTransaction(Transaction $t): ?array
    {
        if ((string) ($t->type ?? '') !== 'card_funding') {
            return null;
        }

        $meta = is_array($t->metadata) ? $t->metadata : [];
        if (isset($meta['profit_snapshot']) && is_array($meta['profit_snapshot'])) {
            return $meta['profit_snapshot'];
        }

        $backfillKey = CardLoadProfitBackfillService::METADATA_KEY;
        if (isset($meta[$backfillKey]) && is_array($meta[$backfillKey])) {
            return $meta[$backfillKey];
        }

        if (($meta['payment_wallet_type'] ?? '') !== 'naira_wallet') {
            return null;
        }

        $charges = is_array($meta['wallet_charge'] ?? null) ? $meta['wallet_charge'] : [];
        $principalUsd = (float) ($meta['principal_usd'] ?? $charges['principal_usd'] ?? 0);
        $fundKey = ($meta['card_scheme'] ?? '') === 'visa' ? 'visa_fund' : 'fund';

        return $this->snapshotForNewFunding(
            array_merge($charges, ['payment_wallet_type' => 'naira_wallet']),
            $principalUsd,
            $fundKey,
        );
    }

    /**
     * Historical backfill snapshot — stored separately from live profit_snapshot.
     *
     * @return array<string, mixed>
     */
    public function buildBackfillSnapshot(
        float $revenueNgn,
        float $principalUsd,
        float $trueRate,
        float $customerRate,
        string $fundServiceKey,
        int $rechargeId,
    ): array {
        $rate = $this->rates->findVirtualCard($fundServiceKey);
        $snapshot = $this->buildSnapshot($revenueNgn, $principalUsd, $trueRate, $customerRate, $rate);

        return array_merge($snapshot, [
            'source' => 'card_load_profit_backfill',
            'recharge_id' => $rechargeId,
            'backfilled_at' => Carbon::now()->toIso8601String(),
            'metadata_key' => CardLoadProfitBackfillService::METADATA_KEY,
        ]);
    }

    protected function currentTrueRate(): ?float
    {
        $row = PagocardsWalletRecharge::query()
            ->orderByDesc('recharged_at')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) $row->true_rate_ngn_per_usd : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSnapshot(
        float $revenueNgn,
        float $principalUsd,
        float $trueRate,
        float $customerRate,
        ?PlatformRate $rate,
    ): array {
        $providerFeeUsd = $this->providerFeeUsd($rate, $principalUsd);
        $principalCostNgn = round($principalUsd * $trueRate, 2);
        $providerFeeNgn = round($providerFeeUsd * $trueRate, 2);
        $providerCostNgn = round($principalCostNgn + $providerFeeNgn, 2);
        $netProfitNgn = round($revenueNgn - $providerCostNgn, 2);
        $fxMarginNgn = round($principalUsd * max(0, $customerRate - $trueRate), 2);

        return [
            'source' => 'card_load_profit',
            'revenue_ngn' => round($revenueNgn, 2),
            'principal_usd' => round($principalUsd, 8),
            'principal_cost_ngn' => $principalCostNgn,
            'provider_fee_usd' => $providerFeeUsd,
            'provider_fee_ngn' => $providerFeeNgn,
            'provider_cost_ngn' => $providerCostNgn,
            'net_profit_ngn' => $netProfitNgn,
            'true_rate_ngn_per_usd' => $trueRate,
            'customer_rate_ngn_per_usd' => $customerRate,
            'customer_revenue_ngn' => round($revenueNgn, 2),
            'charge_ngn' => round($revenueNgn, 2),
            'estimated_profit_ngn' => $netProfitNgn,
            'fx_margin_ngn' => $fxMarginNgn,
            'fee_margin_ngn' => round($netProfitNgn - $fxMarginNgn, 2),
        ];
    }

    protected function providerFeeUsd(?PlatformRate $rate, float $principalUsd): float
    {
        $flat = $rate && $rate->provider_cost_usd !== null
            ? (float) $rate->provider_cost_usd
            : 1.0;
        $pct = $rate && $rate->provider_pct !== null
            ? (float) $rate->provider_pct
            : 2.0;

        return round($flat + ($principalUsd * max(0, $pct) / 100), 8);
    }
}
