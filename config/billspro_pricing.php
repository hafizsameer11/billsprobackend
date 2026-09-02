<?php

/**
 * BillsPro Pricing & Profit Margin Table (client PDF).
 *
 * Seeded into `platform_rates`, `service_profit_settings`, and (via CommissionTablesSeeder)
 * `bill_commission_rates` / `commission_volume_tiers`.
 *
 * Run: php artisan db:seed --class=PdfPricingDefaultsSeeder
 */
return [

    /** FX spread markup (NGN) on card funding and crypto buy/sell quotes. */
    'fx_markup_ngn' => (float) env('BILLSPRO_FX_MARKUP_NGN', 80),

    'fiat' => [
        'withdrawal' => [
            'label' => 'Bank Transfer',
            'fixed_fee_ngn' => 50,
            'provider_cost_ngn' => 25,
        ],
        'deposit' => [
            'label' => 'Wallet Deposit (PalmPay)',
            'fixed_fee_ngn' => 0,
            'percentage_fee' => 0,
            'provider_pct' => 0.7,
            'provider_pct_cap_ngn' => 700,
        ],
    ],

    'virtual_card' => [
        'creation' => [
            'label' => 'Mastercard Card Issuance',
            'fee_usd' => 3,
            'provider_cost_usd' => 1.5,
        ],
        'fund' => [
            'label' => 'Mastercard Card Funding',
            'fee_usd' => 1,
            'percentage_fee' => 0,
            'provider_cost_usd' => 1,
            'provider_pct' => 1,
            'fx_markup_ngn' => true,
        ],
        'visa_creation' => [
            'label' => 'Visa Card Issuance',
            'fee_usd' => 8,
            'provider_cost_usd' => 3,
        ],
        'visa_fund' => [
            'label' => 'Visa Card Funding',
            'fee_usd' => 1,
            'percentage_fee' => 0,
            'provider_cost_usd' => 1,
            'provider_pct' => 2,
            'fx_markup_ngn' => true,
        ],
        'decline_fee' => [
            'label' => 'Mastercard Decline Fee (2nd decline)',
            'fee_usd' => 1,
            'provider_cost_usd' => 0,
        ],
        'visa_decline_fee' => [
            'label' => 'Visa Decline Fee (2nd decline)',
            'fee_usd' => 1,
            'provider_cost_usd' => 0.75,
        ],
        'terminate' => [
            'label' => 'Mastercard Card Termination Refund',
            'fee_usd' => 1,
            'exchange_rate_ngn_per_usd' => 1420,
        ],
        'visa_terminate' => [
            'label' => 'Visa Card Termination Refund',
            'fee_usd' => 1,
            'exchange_rate_ngn_per_usd' => 1420,
        ],
    ],

    'crypto' => [
        'deposit' => [
            'label' => 'Crypto Receive',
            'fee_usd' => 1,
            'provider_cost_usd' => 0,
        ],
        'buy' => [
            'label' => 'Crypto Buy',
            'fixed_fee_ngn' => true,
            'provider_cost_usd' => 0,
            'notes' => 'Market rate + FX markup; spread captured in crypto_exchange_rates.',
        ],
        'sell' => [
            'label' => 'Crypto Sell',
            'fixed_fee_ngn' => true,
            'provider_cost_usd' => 0,
            'notes' => 'Market rate + FX markup; spread captured in crypto_exchange_rates.',
        ],
        /**
         * On-chain send fees per ledger asset + network (USD).
         * TRX / USDT_TRON: gas is variable — provider cost omitted.
         */
        'send_fees' => [
            ['asset' => 'SOL', 'network' => 'solana', 'fee_usd' => 0.10, 'provider_cost_usd' => 0.01],
            ['asset' => 'BTC', 'network' => 'bitcoin', 'fee_usd' => 2.0, 'provider_cost_usd' => 1.0],
            ['asset' => 'BSC', 'network' => 'bsc', 'fee_usd' => 0.10, 'provider_cost_usd' => 0.05],
            ['asset' => 'DOGE', 'network' => 'dogecoin', 'fee_usd' => 0.50, 'provider_cost_usd' => 0.15],
            ['asset' => 'ETH', 'network' => 'ethereum', 'fee_usd' => 1.0, 'provider_cost_usd' => 0.50],
            ['asset' => 'TRX', 'network' => 'tron', 'fee_usd' => 1.0, 'provider_cost_usd' => 0.0],
            ['asset' => 'USDT_TRON', 'network' => 'tron', 'fee_usd' => 1.0, 'provider_cost_usd' => 0.0],
            ['asset' => 'USDT', 'network' => 'ethereum', 'fee_usd' => 1.5, 'provider_cost_usd' => 0.50],
        ],
    ],

    /**
     * Profit Center catalog order — mirrors the client PDF (no scaffold / per-asset duplicates).
     * Each entry is either a `platform_rates` slug or a synthetic `type` row.
     */
    'catalog' => [
        ['group' => 'Fiat & transfers', 'slug' => 'fiat|withdrawal|||'],
        ['group' => 'Fiat & transfers', 'slug' => 'fiat|deposit|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|visa_creation|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|creation|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|visa_fund|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|fund|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|visa_decline_fee|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|decline_fee|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|visa_terminate|||'],
        ['group' => 'Virtual cards', 'slug' => 'virtual_card|terminate|||'],
        ['group' => 'Crypto', 'type' => 'crypto_buy_sell'],
        ['group' => 'Crypto', 'slug' => 'crypto|deposit|||'],
        ['group' => 'Bill payments', 'type' => 'commission_airtime'],
        ['group' => 'Bill payments', 'type' => 'commission_betting'],
    ],

    /**
     * Maps `service_profit_settings.service_key` → platform_rate slug for admin profit reporting.
     */
    'service_profit_links' => [
        'withdrawal' => 'fiat|withdrawal|||',
        'deposit' => 'fiat|deposit|||',
        'palmpay_deposit' => 'fiat|deposit|||',
        'bill_payment' => 'fiat|bill_payment|||',
        'crypto_deposit' => 'crypto|deposit|||',
        'crypto_withdrawal' => 'crypto|withdrawal|||',
        'external_send' => 'crypto|withdrawal|||',
        'crypto_buy' => 'crypto|buy|||',
        'crypto_sell' => 'crypto|sell|||',
        'card_creation' => 'virtual_card|creation|||',
        'card_funding' => 'virtual_card|fund|||',
        'card_decline' => 'virtual_card|decline_fee|||',
    ],

];
