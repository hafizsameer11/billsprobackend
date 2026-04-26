<?php

namespace App\Services\Admin;

use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Services\Platform\PlatformRateResolver;

/**
 * Resolves the admin Rates row that applies to a ledger transaction (for display in profit UI).
 */
class TransactionPlatformRateSnapshot
{
    public function __construct(
        protected PlatformRateResolver $resolver
    ) {}

    public function forTransaction(Transaction $t): ?array
    {
        $rate = $this->resolve($t);

        return $rate ? $this->format($rate) : null;
    }

    protected function resolve(Transaction $t): ?PlatformRate
    {
        $type = (string) $t->type;
        $meta = is_array($t->metadata) ? $t->metadata : [];
        $cur = strtoupper((string) ($t->currency ?? ''));
        $net = isset($meta['network']) ? (string) $meta['network'] : (isset($meta['blockchain']) ? (string) $meta['blockchain'] : null);

        $cardScheme = isset($meta['card_scheme']) ? strtolower((string) $meta['card_scheme']) : '';

        return match ($type) {
            'bill_payment' => $this->resolver->findFiat('bill_payment', $t->category ?: null)
                ?? $this->resolver->findFiat('bill_payment', null),
            'deposit' => $this->resolver->findFiat('deposit', null),
            'withdrawal' => $this->resolver->findFiat('withdrawal', null),
            'crypto_buy' => $this->resolver->findCrypto('buy', $cur ?: null, $net),
            'crypto_sell' => $this->resolver->findCrypto('sell', $cur ?: null, $net),
            'crypto_deposit' => $this->resolver->findCrypto('deposit', $cur ?: null, $net),
            'crypto_withdrawal', 'external_send' => $this->resolver->findCryptoSendOrWithdrawal($cur ?: null, $net),
            'card_creation' => $cardScheme === 'visa'
                ? $this->resolver->findVirtualCard('visa_creation')
                : $this->resolver->findVirtualCard('creation'),
            'card_funding' => $cardScheme === 'visa'
                ? $this->resolver->findVirtualCard('visa_fund')
                : $this->resolver->findVirtualCard('fund'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function format(PlatformRate $r): array
    {
        return [
            'category' => $r->category,
            'service_key' => $r->service_key,
            'sub_service_key' => $r->sub_service_key,
            'crypto_asset' => $r->crypto_asset,
            'network_key' => $r->network_key,
            'fixed_fee_ngn' => $this->stringOrNull($r->fixed_fee_ngn),
            'percentage_fee' => $this->stringOrNull($r->percentage_fee),
            'min_fee_ngn' => $this->stringOrNull($r->min_fee_ngn),
            'fee_usd' => $this->stringOrNull($r->fee_usd),
            'exchange_rate_ngn_per_usd' => $this->stringOrNull($r->exchange_rate_ngn_per_usd),
        ];
    }

    protected function stringOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }
}
