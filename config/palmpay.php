<?php

return [
    'environment' => env('PALMPAY_ENVIRONMENT', 'sandbox'),

    'base_url' => env('PALMPAY_BASE_URL'),

    'app_id' => env('PALMPAY_APP_ID'),

    'private_key' => env('PALMPAY_PRIVATE_KEY'),
    'public_key' => env('PALMPAY_PUBLIC_KEY'),

    'country_code' => env('PALMPAY_COUNTRY_CODE', 'NG'),
    'version' => env('PALMPAY_VERSION', 'V2'),

    'webhook_url' => env('PALMPAY_WEBHOOK_URL'),

    'frontend_url' => env('FRONTEND_URL', config('app.url')),

    'verify_webhook_signature' => env('PALMPAY_VERIFY_WEBHOOK_SIGNATURE', true),

    'timeout' => (int) env('PALMPAY_HTTP_TIMEOUT', 30),

    'min_deposit_ngn' => (float) env('PALMPAY_MIN_DEPOSIT_NGN', 100),

    /*
     * When true (default), POST /api/deposit/initiate uses PalmPay checkout when credentials are set.
     * Set PALMPAY_LEGACY_MANUAL_FIAT_DEPOSIT=true to force the old merchant BankAccount flow.
     */
    'use_for_fiat_deposit' => filter_var(env('PALMPAY_USE_FOR_FIAT_DEPOSIT', true), FILTER_VALIDATE_BOOL),
    'legacy_manual_fiat_deposit' => filter_var(env('PALMPAY_LEGACY_MANUAL_FIAT_DEPOSIT', false), FILTER_VALIDATE_BOOL),

    /*
     * When true (default), legacy bill payment actions that hit the mock/simulated service are rejected
     * in favour of /api/bill-payment/palmpay/* (only when PalmPay credentials exist).
     */
    'use_for_bill_payment' => filter_var(env('PALMPAY_USE_FOR_BILL_PAYMENT', true), FILTER_VALIDATE_BOOL),

    /*
     * When true (default), POST /api/withdrawal uses PalmPay /api/v2/merchant/payment/payout when credentials exist.
     * Set PALMPAY_LEGACY_INTERNAL_WITHDRAWAL=true to keep the old instant ledger-only withdrawal.
     */
    'use_for_withdrawal' => filter_var(env('PALMPAY_USE_FOR_WITHDRAWAL', true), FILTER_VALIDATE_BOOL),
    'legacy_internal_withdrawal' => filter_var(env('PALMPAY_LEGACY_INTERNAL_WITHDRAWAL', false), FILTER_VALIDATE_BOOL),
];
