<?php

$programBillingMastercard = [
    'billing_address_street' => (string) env(
        'VIRTUAL_CARD_PROGRAM_BILLING_MC_STREET',
        env('VIRTUAL_CARD_PROGRAM_BILLING_STREET', '128 City Road')
    ),
    'billing_address_city' => (string) env(
        'VIRTUAL_CARD_PROGRAM_BILLING_MC_CITY',
        env('VIRTUAL_CARD_PROGRAM_BILLING_CITY', 'London')
    ),
    'billing_address_state' => (string) env(
        'VIRTUAL_CARD_PROGRAM_BILLING_MC_STATE',
        env('VIRTUAL_CARD_PROGRAM_BILLING_STATE', 'London')
    ),
    'billing_address_country' => (string) env(
        'VIRTUAL_CARD_PROGRAM_BILLING_MC_COUNTRY',
        env('VIRTUAL_CARD_PROGRAM_BILLING_COUNTRY', 'United Kingdom (GB)')
    ),
    'billing_address_postal_code' => (string) env(
        'VIRTUAL_CARD_PROGRAM_BILLING_MC_POSTAL',
        env('VIRTUAL_CARD_PROGRAM_BILLING_POSTAL', 'EC1V 2NX')
    ),
];

return [
    /*
    | Card creation fee (USD) — fallback when admin platform rate has no `fee_usd`.
    | Charged amount: fee_usd × exchange_rate_ngn_per_usd from the `virtual_card` / `creation` platform rate (no extra NGN processing).
    */
    'creation_fee_usd' => (float) env('VIRTUAL_CARD_CREATION_FEE_USD', 3.0),
    /** @deprecated No longer added to creation fee; kept for env compatibility only. */
    'creation_processing_fee_ngn' => (float) env('VIRTUAL_CARD_CREATION_PROCESSING_FEE_NGN', 0.0),
    'usd_to_ngn_rate' => (float) env('VIRTUAL_CARD_USD_TO_NGN_RATE', 1500.0),

    /*
    | Card load: user pays from Naira or Crypto before we call the provider fund API.
    | Naira charge = (principal_usd + optional load fee in USD) * rate + flat_processing_ngn.
    | Flat processing (admin Rates → Virtual card → Deposit / fund card): set **fee in USD** (`fee_usd`);
    | Naira debit adds `fee_usd × exchange_rate_ngn_per_usd` (legacy rows may use `fixed_fee_ngn` only).
    | Fallback when no platform row: fund_processing_fee_ngn.
    | Crypto charge = principal_usd + optional load fee (USD).
    |
    | When fund_include_provider_load_fee is true, user is also charged Pagocards-style
    | flat + percent on top of the principal (still sending `amount` = principal to the API).
    */
    'fund_processing_fee_ngn' => (float) env('VIRTUAL_CARD_FUND_PROCESSING_FEE_NGN', 500.0),
    'fund_include_provider_load_fee' => filter_var(env('VIRTUAL_CARD_FUND_INCLUDE_PROVIDER_LOAD_FEE', false), FILTER_VALIDATE_BOOLEAN),
    'fund_load_flat_fee_usd' => (float) env('VIRTUAL_CARD_FUND_LOAD_FLAT_FEE_USD', 1.0),
    'fund_load_percent' => (float) env('VIRTUAL_CARD_FUND_LOAD_PERCENT', 1.0),

    /*
    | Pagocards program billing — scheme-specific (Visa vs Mastercard).
    | `program_billing` mirrors Mastercard for backward compatibility (legacy env keys).
    */
    'program_billing' => $programBillingMastercard,
    'program_billing_mastercard' => $programBillingMastercard,
    'program_billing_visa' => [
        'billing_address_street' => (string) env('VIRTUAL_CARD_PROGRAM_BILLING_VISA_STREET', '3401 N. Miami Ave., Ste. 230'),
        'billing_address_city' => (string) env('VIRTUAL_CARD_PROGRAM_BILLING_VISA_CITY', 'Miami'),
        'billing_address_state' => (string) env('VIRTUAL_CARD_PROGRAM_BILLING_VISA_STATE', 'Florida'),
        'billing_address_country' => (string) env('VIRTUAL_CARD_PROGRAM_BILLING_VISA_COUNTRY', 'United States'),
        'billing_address_postal_code' => (string) env('VIRTUAL_CARD_PROGRAM_BILLING_VISA_POSTAL', '33127'),
    ],
];
