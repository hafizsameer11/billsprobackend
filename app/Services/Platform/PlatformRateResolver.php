<?php

namespace App\Services\Platform;

use App\Models\PlatformRate;

/**
 * Resolves admin-configured platform fees (Naira, crypto ops, virtual cards).
 */
class PlatformRateResolver
{
    public function __construct(
        protected PlatformRateMarginEstimator $marginEstimator
    ) {}

    public function marginEstimator(): PlatformRateMarginEstimator
    {
        return $this->marginEstimator;
    }
    public function findFiat(string $serviceKey, ?string $subServiceKey = null): ?PlatformRate
    {
        if ($subServiceKey !== null && $subServiceKey !== '') {
            $specific = PlatformRate::query()
                ->where('category', 'fiat')
                ->where('service_key', $serviceKey)
                ->where('sub_service_key', $subServiceKey)
                ->where('is_active', true)
                ->first();
            if ($specific) {
                return $specific;
            }
        }

        return PlatformRate::query()
            ->where('category', 'fiat')
            ->where('service_key', $serviceKey)
            ->whereNull('sub_service_key')
            ->where('is_active', true)
            ->first();
    }

    public function findCrypto(string $serviceKey, ?string $cryptoAsset = null, ?string $networkKey = null): ?PlatformRate
    {
        $candidates = [
            fn () => $cryptoAsset && $networkKey
                ? PlatformRate::query()
                    ->where('category', 'crypto')
                    ->where('service_key', $serviceKey)
                    ->where('crypto_asset', $cryptoAsset)
                    ->where('network_key', $networkKey)
                    ->where('is_active', true)
                    ->first()
                : null,
            fn () => $cryptoAsset
                ? PlatformRate::query()
                    ->where('category', 'crypto')
                    ->where('service_key', $serviceKey)
                    ->where('crypto_asset', $cryptoAsset)
                    ->whereNull('network_key')
                    ->where('is_active', true)
                    ->first()
                : null,
            fn () => PlatformRate::query()
                ->where('category', 'crypto')
                ->where('service_key', $serviceKey)
                ->whereNull('crypto_asset')
                ->whereNull('network_key')
                ->where('is_active', true)
                ->first(),
        ];

        foreach ($candidates as $fn) {
            $row = $fn();
            if ($row instanceof PlatformRate) {
                return $row;
            }
        }

        return null;
    }

    /**
     * On-chain crypto out (app “Send”) uses the same admin row as withdrawal.
     * Falls back to legacy `send` service_key if present.
     */
    public function findCryptoSendOrWithdrawal(?string $cryptoAsset = null, ?string $networkKey = null): ?PlatformRate
    {
        return $this->findCrypto('withdrawal', $cryptoAsset, $networkKey)
            ?? $this->findCrypto('send', $cryptoAsset, $networkKey);
    }

    public function findVirtualCard(string $serviceKey): ?PlatformRate
    {
        return PlatformRate::query()
            ->where('category', 'virtual_card')
            ->where('service_key', $serviceKey)
            ->where('is_active', true)
            ->first();
    }

    /**
     * NGN FX spread markup per 1 USD notional on crypto buy/sell (admin `platform_rates.fixed_fee_ngn`).
     * Falls back from asset-specific row to global buy/sell row.
     */
    public function cryptoTradeFxMarkupNgn(string $serviceKey, ?string $cryptoAsset = null, ?string $networkKey = null): float
    {
        if (! in_array($serviceKey, ['buy', 'sell'], true)) {
            return 0.0;
        }

        $row = $this->findCrypto($serviceKey, $cryptoAsset, $networkKey);
        if (! $row) {
            return 0.0;
        }

        return max(0.0, (float) $row->fixed_fee_ngn);
    }

    /**
     * Fiat deposit fee (NGN): flat + optional percentage with cap from platform row.
     */
    public function fiatDepositFeeNgn(float $amount = 0, float $fallback = 200.0): float
    {
        $r = $this->findFiat('deposit', null);
        if (! $r) {
            return $fallback;
        }

        $fee = (float) $r->fixed_fee_ngn;
        if ($amount > 0 && $r->percentage_fee !== null) {
            $fee += round($amount * (float) $r->percentage_fee / 100, 2);
        }

        return $fee;
    }

    /**
     * Provider cost for PalmPay / fiat deposit (not charged to user).
     */
    public function fiatDepositProviderCostNgn(float $amount): float
    {
        $r = $this->findFiat('deposit', null);
        if (! $r || $amount <= 0) {
            return 0.0;
        }

        return $this->marginEstimator->providerCostFromRate($r, $amount, null)['ngn'];
    }

    public function billUsesCommissionRevenue(string $billCategoryCode): bool
    {
        return in_array(strtolower($billCategoryCode), ['airtime', 'data', 'betting'], true);
    }

    /**
     * Fiat withdrawal flat fee (NGN).
     */
    public function fiatWithdrawalFeeNgn(float $fallback = 200.0): float
    {
        $r = $this->findFiat('withdrawal', null);

        return $r ? (float) $r->fixed_fee_ngn : $fallback;
    }

    /**
     * Bill payment fee from platform row or legacy formula.
     */
    public function billPaymentFeeNgn(float $amount, string $currency, string $billCategoryCode): float
    {
        if ($this->billUsesCommissionRevenue($billCategoryCode)) {
            return 0.0;
        }

        $r = $this->findFiat('bill_payment', $billCategoryCode);
        if (! $r) {
            $r = $this->findFiat('bill_payment', null);
        }

        if (! $r) {
            return $this->legacyBillFee($amount, $currency);
        }

        $pct = $r->percentage_fee !== null ? (float) $r->percentage_fee / 100.0 : 0.01;
        $fixed = (float) $r->fixed_fee_ngn;
        $min = $r->min_fee_ngn !== null ? (float) $r->min_fee_ngn : 0.0;

        $calculated = $amount * $pct + $fixed;

        return $min > 0 ? max($calculated, $min) : $calculated;
    }

    public function billPaymentFeePercentDisplay(string $billCategoryCode): float
    {
        $r = $this->findFiat('bill_payment', $billCategoryCode) ?? $this->findFiat('bill_payment', null);
        if ($r && $r->percentage_fee !== null) {
            return (float) $r->percentage_fee;
        }

        return 1.0;
    }

    protected function legacyBillFee(float $amount, string $currency): float
    {
        $feePercent = 0.01;
        $calculatedFee = $amount * $feePercent;
        $minFees = [
            'NGN' => 20,
            'USD' => 0.1,
            'KES' => 2,
            'GHS' => 0.5,
        ];
        $minFee = $minFees[$currency] ?? 0.1;

        return max($calculatedFee, $minFee);
    }
}
