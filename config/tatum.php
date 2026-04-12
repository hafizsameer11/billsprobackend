<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tatum API
    |--------------------------------------------------------------------------
    */

    'api_key' => env('TATUM_API_KEY'),

    'base_url_v3' => rtrim(env('TATUM_BASE_URL', 'https://api.tatum.io/v3'), '/'),

    'base_url_v4' => rtrim(env('TATUM_BASE_URL_V4', 'https://api.tatum.io/v4'), '/'),

    'timeout' => (int) env('TATUM_HTTP_TIMEOUT', 120),

    /**
     * Public URL Tatum will POST webhooks to (V4 subscription `attr.url`).
     */
    'webhook_url' => env('TATUM_WEBHOOK_URL', rtrim((string) env('APP_URL', ''), '/').'/api/webhooks/tatum'),

    /**
     * Optional secret for GET /api/webhooks/tatum/replay/{id}?token=...
     * (re-dispatch ProcessTatumWebhookJob for a stored tatum_raw_webhooks row). Empty = disabled.
     */
    'raw_replay_token' => env('TATUM_RAW_REPLAY_TOKEN', ''),

    /**
     * When true, deposit addresses fall back to local mock strings (no Tatum calls).
     */
    'use_mock' => filter_var(env('TATUM_USE_MOCK', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | ERC-20 / BEP-20 / TRC-20 contract addresses (mainnet defaults)
    |--------------------------------------------------------------------------
    */
    'contracts' => [
        'ethereum' => [
            'USDT' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
            'USDC' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
        ],
        'bsc' => [
            'USDT' => '0x55d398326f99059fF775485246999027B3197955',
            'USDC' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
        ],
        'polygon' => [
            'USDT' => '0xc2132D05D31c914a87C6611C10748AaCB9fC6fC',
            'USDC' => '0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174',
        ],
        'tron' => [
            'USDT' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        ],
        'solana' => [
            'USDT' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB',
            'USDT_SOL' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB',
        ],
    ],

    'btc' => [
        'fee_satvb' => (int) env('TATUM_BTC_FEE_SATVB', 12),
    ],

    'tron' => [
        'fee_limit_sun' => (int) env('TATUM_TRON_FEE_LIMIT_SUN', 100_000_000),
    ],
];
