<?php

namespace App\Services\Admin;

use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Services\BillPayment\BillCommissionResolver;
use App\Services\Platform\PlatformRateMarginEstimator;
use App\Services\Platform\PlatformRateResolver;

class TransactionPricingSnapshotService
{
    public function __construct(
        protected PlatformRateResolver $rates,
        protected PlatformRateMarginEstimator $estimator,
        protected BillCommissionResolver $commissions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildForTransaction(Transaction $t): array
    {
        $meta = is_array($t->metadata) ? $t->metadata : [];
        if (isset($meta['pricing_snapshot']) && is_array($meta['pricing_snapshot'])) {
            return $meta['pricing_snapshot'];
        }

        $type = (string) ($t->type ?? '');
        $amount = (float) $t->amount;
        $fee = (float) $t->fee;

        if ($type === 'bill_payment' && isset($meta['commission_pct'])) {
            $commission = (float) ($meta['estimated_commission_ngn'] ?? 0);

            return [
                'charge_ngn' => $fee,
                'provider_cost_ngn' => 0,
                'customer_revenue_ngn' => $commission,
                'estimated_profit_ngn' => $commission,
                'commission_pct' => (float) $meta['commission_pct'],
                'source' => 'bill_commission',
            ];
        }

        if ($type === 'deposit' && (($meta['provider'] ?? '') === 'palmpay' || ($meta['payment_method'] ?? '') === 'palmpay')) {
            $cost = $this->rates->fiatDepositProviderCostNgn($amount);

            return [
                'charge_ngn' => $fee,
                'provider_cost_ngn' => $cost,
                'customer_revenue_ngn' => $fee,
                'estimated_profit_ngn' => round($fee - $cost, 2),
                'source' => 'palmpay_deposit',
            ];
        }

        $rate = $this->resolveRateForTransaction($t);
        if ($rate) {
            $usd = strtoupper((string) $t->currency) === 'USD' ? $amount : 0;
            $ngn = strtoupper((string) $t->currency) === 'NGN' ? $amount : 0;
            $est = $this->estimator->estimate($rate, $ngn, $usd);
            $revenue = $fee > 0 ? $fee : ($est['charge_ngn'] > 0 ? $est['charge_ngn'] : 0);

            return array_merge($est, [
                'customer_revenue_ngn' => $revenue,
                'estimated_profit_ngn' => round($revenue - $est['provider_cost_ngn'], 2),
                'rate_slug' => $rate->slug,
                'source' => 'platform_rate',
            ]);
        }

        return [
            'charge_ngn' => $fee,
            'provider_cost_ngn' => 0,
            'customer_revenue_ngn' => $fee,
            'estimated_profit_ngn' => $fee,
            'source' => 'ledger_fee',
        ];
    }

    public function attachToTransaction(Transaction $t, ?array $extra = null): void
    {
        $snap = array_merge($this->buildForTransaction($t), $extra ?? []);
        $meta = is_array($t->metadata) ? $t->metadata : [];
        $meta['pricing_snapshot'] = $snap;
        $t->update(['metadata' => $meta]);
    }

    protected function resolveRateForTransaction(Transaction $t): ?PlatformRate
    {
        $type = (string) ($t->type ?? '');
        $category = (string) ($t->category ?? '');
        $meta = is_array($t->metadata) ? $t->metadata : [];

        return match ($type) {
            'withdrawal' => $this->rates->findFiat('withdrawal', null),
            'deposit' => $this->rates->findFiat('deposit', null),
            'bill_payment' => $this->rates->findFiat('bill_payment', $category ?: null),
            'crypto_deposit' => $this->rates->findCrypto('deposit', $meta['currency'] ?? null, $meta['blockchain'] ?? null),
            'crypto_withdrawal', 'external_send' => $this->rates->findCryptoSendOrWithdrawal(
                $meta['currency'] ?? null,
                $meta['blockchain'] ?? null
            ),
            'card_creation' => $this->rates->findVirtualCard(
                ($meta['card_scheme'] ?? '') === 'visa' ? 'visa_creation' : 'creation'
            ),
            'card_funding' => $this->rates->findVirtualCard(
                ($meta['card_scheme'] ?? '') === 'visa' ? 'visa_fund' : 'fund'
            ),
            default => null,
        };
    }
}
