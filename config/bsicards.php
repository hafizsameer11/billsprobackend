<?php

return [
    'public_key' => env('BSICARDS_PUBLIC_KEY'),
    'secret_key' => env('BSICARDS_SECRET_KEY'),
    'timeout' => (int) env('BSICARDS_TIMEOUT', 30),

    // Standard docs base URL.
    'base_url' => rtrim((string) env('BSICARDS_BASE_URL', 'https://cards.bsigroup.tech/api'), '/'),

    // Merchant base URL can differ from standard base URL.
    // Keep RESELLER env as fallback for backward compatibility.
    'merchant_base_url' => rtrim((string) env('BSICARDS_MERCHANT_BASE_URL', env('BSICARDS_RESELLER_BASE_URL', env('BSICARDS_BASE_URL', 'https://cards.bsigroup.tech/api'))), '/'),

    // Endpoint mapping for Merchant Digital Master.
    // Override in env if your provider gives a different path.
    'endpoints' => [
        'merchant_master_create' => env('BSICARDS_MERCHANT_MASTER_CREATE_PATH', '/digitalnewvirtualcard'),
        'merchant_master_get_all' => env('BSICARDS_MERCHANT_MASTER_GET_ALL_PATH', '/getalldigital'),
        'merchant_master_get_card' => env('BSICARDS_MERCHANT_MASTER_GET_CARD_PATH', '/getdigitalcard'),
        // Ambiguous in docs for reseller digital master; allow override.
        'merchant_master_fund' => env('BSICARDS_MERCHANT_MASTER_FUND_PATH', '/digitalfundcard'),
        'merchant_master_block' => env('BSICARDS_MERCHANT_MASTER_BLOCK_PATH', '/blockdigital'),
        'merchant_master_unblock' => env('BSICARDS_MERCHANT_MASTER_UNBLOCK_PATH', '/unblockdigital'),
        'merchant_master_terminate' => env('BSICARDS_MERCHANT_MASTER_TERMINATE_PATH', '/terminatedigital'),
        'merchant_master_transactions' => env('BSICARDS_MERCHANT_MASTER_TRANSACTIONS_PATH', '/digitaltransactions'),
        'merchant_master_check_3ds' => env('BSICARDS_MERCHANT_MASTER_CHECK_3DS_PATH', '/check3ds'),
        'merchant_master_check_wallet' => env('BSICARDS_MERCHANT_MASTER_CHECK_WALLET_PATH', '/checkwallet'),
        'merchant_master_approve_3ds' => env('BSICARDS_MERCHANT_MASTER_APPROVE_3DS_PATH', '/approve3ds'),
    ],
];
