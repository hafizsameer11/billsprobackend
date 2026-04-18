<?php

namespace App\Services\Admin;

use App\Models\Transaction;

/**
 * Classifies how platform revenue is captured per ledger row (fee vs exchange spread vs USD-notional crypto fee).
 * Used by the admin profit API so the dashboard does not misread `transactions.fee` (e.g. crypto buy/sell use fee=0).
 */
class TransactionRevenueClassifier
{
    public const KIND_FIAT_FEE = 'fiat_fee';

    public const KIND_BILL_FEE = 'bill_fee';

    public const KIND_CRYPTO_USD_NOTIONAL_FEE = 'crypto_usd_notional_fee';

    public const KIND_EXCHANGE_TRADE = 'exchange_trade';

    public const KIND_VIRTUAL_CARD_FEE = 'virtual_card_fee';

    public const KIND_OTHER = 'other';

    /**
     * @return array{
     *   revenue_kind: string,
     *   label_customer_flow: string,
     *   label_fee_line: string,
     *   ngn_notional: string|null,
     *   crypto_units: string|null,
     *   reference_ngn_per_crypto: string|null,
     *   applied_ngn_per_crypto: string|null,
     *   implied_spread_ngn: string|null
     * }
     */
    public function classify(Transaction $t): array
    {
        $type = (string) ($t->type ?? '');
        $meta = is_array($t->metadata) ? $t->metadata : [];

        return match (true) {
            in_array($type, ['deposit', 'withdrawal'], true) => $this->fiatFee($t),
            $type === 'bill_payment' => $this->billFee($t),
            in_array($type, ['crypto_deposit', 'crypto_withdrawal', 'external_send'], true) => $this->cryptoUsdNotionalFee($t),
            in_array($type, ['crypto_buy', 'crypto_sell'], true) => $this->exchangeTrade($t, $meta),
            in_array($type, ['card_creation', 'card_funding'], true) => $this->virtualCardFee($t),
            $type === 'flush' => $this->other('Treasury / flush'),
            default => $this->other('Other'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function fiatFee(Transaction $t): array
    {
        $cur = strtoupper((string) ($t->currency ?? ''));

        return [
            'revenue_kind' => self::KIND_FIAT_FEE,
            'label_customer_flow' => 'Principal (credited) / withdrawal principal',
            'label_fee_line' => 'Service fee (from Rates)',
            'ngn_notional' => $cur === 'NGN' ? $this->str((float) $t->total_amount) : null,
            'crypto_units' => null,
            'reference_ngn_per_crypto' => null,
            'applied_ngn_per_crypto' => null,
            'implied_spread_ngn' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function billFee(Transaction $t): array
    {
        $cur = strtoupper((string) ($t->currency ?? ''));

        return [
            'revenue_kind' => self::KIND_BILL_FEE,
            'label_customer_flow' => 'Bill / purchase amount',
            'label_fee_line' => 'Service fee (fixed + % + min from Rates)',
            'ngn_notional' => $cur === 'NGN' ? $this->str((float) $t->total_amount) : null,
            'crypto_units' => null,
            'reference_ngn_per_crypto' => null,
            'applied_ngn_per_crypto' => null,
            'implied_spread_ngn' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cryptoUsdNotionalFee(Transaction $t): array
    {
        return [
            'revenue_kind' => self::KIND_CRYPTO_USD_NOTIONAL_FEE,
            'label_customer_flow' => 'Crypto amount (gross)',
            'label_fee_line' => 'Processing fee (USD flat + % of USD value, from Rates)',
            'ngn_notional' => null,
            'crypto_units' => $this->str((float) $t->amount),
            'reference_ngn_per_crypto' => null,
            'applied_ngn_per_crypto' => null,
            'implied_spread_ngn' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function exchangeTrade(Transaction $t, array $meta): array
    {
        $type = (string) $t->type;
        $ngn = null;
        if ($type === 'crypto_buy') {
            $ngn = isset($meta['payment_amount']) ? $this->str((float) $meta['payment_amount']) : null;
        } else {
            $raw = $meta['ngn_amount'] ?? $meta['amount_to_receive'] ?? null;
            $ngn = $raw !== null && $raw !== '' ? $this->str((float) $raw) : null;
        }

        $ref = $meta['reference_ngn_per_crypto'] ?? null;
        $app = $meta['applied_ngn_per_crypto'] ?? null;
        $refF = is_numeric($ref) ? (float) $ref : null;
        $appF = is_numeric($app) ? (float) $app : null;
        $crypto = (float) $t->amount;
        $spread = null;
        if ($refF !== null && $appF !== null && $crypto > 0) {
            $spread = ($appF - $refF) * $crypto;
            $spread = $this->str(round($spread, 8));
        }

        return [
            'revenue_kind' => self::KIND_EXCHANGE_TRADE,
            'label_customer_flow' => $type === 'crypto_buy' ? 'NGN paid (buy crypto)' : 'NGN received (sell crypto)',
            'label_fee_line' => 'No separate ledger fee — customer price is in the exchange rate',
            'ngn_notional' => $ngn,
            'crypto_units' => $this->str($crypto),
            'reference_ngn_per_crypto' => is_numeric($ref) ? $this->str((float) $ref) : null,
            'applied_ngn_per_crypto' => is_numeric($app) ? $this->str((float) $app) : null,
            'implied_spread_ngn' => $spread,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function virtualCardFee(Transaction $t): array
    {
        $cur = strtoupper((string) ($t->currency ?? ''));

        return [
            'revenue_kind' => self::KIND_VIRTUAL_CARD_FEE,
            'label_customer_flow' => 'Card operation',
            'label_fee_line' => 'Fee charged (USD × rate and/or % from Rates)',
            'ngn_notional' => $cur === 'NGN' ? $this->str((float) $t->total_amount) : null,
            'crypto_units' => null,
            'reference_ngn_per_crypto' => null,
            'applied_ngn_per_crypto' => null,
            'implied_spread_ngn' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function other(string $flowLabel): array
    {
        return [
            'revenue_kind' => self::KIND_OTHER,
            'label_customer_flow' => $flowLabel,
            'label_fee_line' => 'See ledger amounts',
            'ngn_notional' => null,
            'crypto_units' => null,
            'reference_ngn_per_crypto' => null,
            'applied_ngn_per_crypto' => null,
            'implied_spread_ngn' => null,
        ];
    }

    protected function str(float $n): string
    {
        return number_format($n, 8, '.', '');
    }
}
