<?php

namespace App\Services\Platform;

use App\Models\PlatformRate;

class PlatformRateMarginEstimator
{
    /**
     * Estimate customer charge and provider cost for catalog / admin preview.
     *
     * @return array{
     *   charge_ngn: float,
     *   charge_usd: float,
     *   provider_cost_ngn: float,
     *   provider_cost_usd: float,
     *   estimated_profit_ngn: float|null,
     *   estimated_profit_usd: float|null
     * }
     */
    public function estimate(
        PlatformRate $r,
        ?float $amountNgn = null,
        ?float $amountUsd = null,
        ?float $ngnPerUsd = null,
    ): array {
        $amountNgn = max(0.0, (float) ($amountNgn ?? 0));
        $amountUsd = max(0.0, (float) ($amountUsd ?? 0));
        $rate = $ngnPerUsd ?? (float) ($r->exchange_rate_ngn_per_usd ?? 0);

        $chargeNgn = (float) $r->fixed_fee_ngn;
        if ($r->percentage_fee !== null && $amountNgn > 0) {
            $pct = (float) $r->percentage_fee;
            $chargeNgn += round($amountNgn * $pct / 100, 2);
            if ($r->min_fee_ngn !== null) {
                $chargeNgn = max($chargeNgn, (float) $r->min_fee_ngn);
            }
        }

        $chargeUsd = (float) ($r->fee_usd ?? 0);
        if ($r->percentage_fee !== null && $amountUsd > 0) {
            $chargeUsd += round($amountUsd * (float) $r->percentage_fee / 100, 8);
        }
        if ($chargeUsd > 0 && $rate > 0 && $chargeNgn <= 0) {
            $chargeNgn = round($chargeUsd * $rate, 2);
        }

        $costNgn = (float) ($r->provider_cost_ngn ?? 0);
        if ($r->provider_pct !== null && $amountNgn > 0) {
            $raw = round($amountNgn * (float) $r->provider_pct / 100, 2);
            if ($r->provider_pct_cap_ngn !== null) {
                $raw = min($raw, (float) $r->provider_pct_cap_ngn);
            }
            $costNgn += $raw;
        }

        $costUsd = (float) ($r->provider_cost_usd ?? 0);
        if ($r->provider_pct !== null && $amountUsd > 0) {
            $costUsd += round($amountUsd * (float) $r->provider_pct / 100, 8);
        }

        $profitNgn = $chargeNgn > 0 || $costNgn > 0 ? round($chargeNgn - $costNgn, 2) : null;
        $profitUsd = $chargeUsd > 0 || $costUsd > 0 ? round($chargeUsd - $costUsd, 8) : null;

        return [
            'charge_ngn' => $chargeNgn,
            'charge_usd' => $chargeUsd,
            'provider_cost_ngn' => $costNgn,
            'provider_cost_usd' => $costUsd,
            'estimated_profit_ngn' => $profitNgn,
            'estimated_profit_usd' => $profitUsd,
        ];
    }

    public function providerCostFromRate(PlatformRate $r, ?float $amountNgn = null, ?float $amountUsd = null): array
    {
        $e = $this->estimate($r, $amountNgn, $amountUsd);

        return [
            'ngn' => $e['provider_cost_ngn'],
            'usd' => $e['provider_cost_usd'],
        ];
    }

    public function chargeFromRate(PlatformRate $r, ?float $amountNgn = null, ?float $amountUsd = null): array
    {
        $e = $this->estimate($r, $amountNgn, $amountUsd);

        return [
            'ngn' => $e['charge_ngn'],
            'usd' => $e['charge_usd'],
        ];
    }
}
