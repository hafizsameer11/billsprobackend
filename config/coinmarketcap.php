<?php

return [

    'api_key' => env('COINMARKETCAP_API_KEY'),

    'base_url' => rtrim(env('COINMARKETCAP_BASE_URL', 'https://pro-api.coinmarketcap.com'), '/'),

    'timeout' => (int) env('COINMARKETCAP_HTTP_TIMEOUT', 30),

    /*
    | Maps `wallet_currencies.currency` to CoinMarketCap `symbol` for /v1/cryptocurrency/quotes/latest
    */
    'currency_to_symbol' => [
        'ETH' => 'ETH',
        'USDT' => 'USDT',
        'USDC' => 'USDC',
        'TRX' => 'TRX',
        'USDT_TRON' => 'USDT',
        'BNB' => 'BNB',
        /** BSC Smart Chain native — priced as BNB on CoinMarketCap */
        'BSC' => 'BNB',
        'USDT_BSC' => 'USDT',
        'BTC' => 'BTC',
        'SOL' => 'SOL',
        'USDT_SOL' => 'USDT',
        'MATIC' => 'MATIC',
        'USDT_POLYGON' => 'USDT',
        'DOGE' => 'DOGE',
        'XRP' => 'XRP',
        'LTC' => 'LTC',
    ],

];
