<?php

return [
    /*
    | Card initialization (creation) fee charged from user's naira or crypto wallet.
    | Fee matches previous app behavior: USD component + fixed NGN processing.
    */
    'creation_fee_usd' => (float) env('VIRTUAL_CARD_CREATION_FEE_USD', 3.0),
    'creation_processing_fee_ngn' => (float) env('VIRTUAL_CARD_CREATION_PROCESSING_FEE_NGN', 500.0),
    'usd_to_ngn_rate' => (float) env('VIRTUAL_CARD_USD_TO_NGN_RATE', 1500.0),

    /*
    | Card load: user pays from Naira or Crypto before we call the provider fund API.
    | Naira charge = (principal_usd + optional load fee in USD) * rate + processing_fee_ngn.
    | Crypto charge = principal_usd + optional load fee (USD).
    |
    | When fund_include_provider_load_fee is true, user is also charged Pagocards-style
    | flat + percent on top of the principal (still sending `amount` = principal to the API).
    */
    'fund_processing_fee_ngn' => (float) env('VIRTUAL_CARD_FUND_PROCESSING_FEE_NGN', 500.0),
    'fund_include_provider_load_fee' => filter_var(env('VIRTUAL_CARD_FUND_INCLUDE_PROVIDER_LOAD_FEE', false), FILTER_VALIDATE_BOOLEAN),
    'fund_load_flat_fee_usd' => (float) env('VIRTUAL_CARD_FUND_LOAD_FLAT_FEE_USD', 1.0),
    'fund_load_percent' => (float) env('VIRTUAL_CARD_FUND_LOAD_PERCENT', 1.0),
];
