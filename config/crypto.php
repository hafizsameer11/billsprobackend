<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fiat: NGN per 1 USD (used for buy/sell quotes; CoinMarketCap job can update)
    |--------------------------------------------------------------------------
    */
    'ngn_per_usd' => (float) env('CRYPTO_NGN_PER_USD', 1500),

    /*
    |--------------------------------------------------------------------------
    | Master wallet (custodial send)
    |--------------------------------------------------------------------------
    |
    | User /send debits the virtual account ledger; the actual on-chain transfer
    | is intended to be broadcast from a platform hot wallet (Tatum or custodian).
    | Wire ProcessCryptoMasterWalletSendJob or TatumClient when ready.
    |
    */

    'master_wallet_send' => [
        'enabled' => filter_var(env('CRYPTO_MASTER_WALLET_SEND_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    | Chains for `php artisan crypto:generate-master-wallet --all`
    | Must match Tatum V3 /{chain}/wallet endpoints.
    */
    'master_wallet_chains' => array_values(array_filter(explode(',', (string) env(
        'CRYPTO_MASTER_WALLET_CHAINS',
        'ethereum,bsc,bitcoin,dogecoin,tron'
    )))),

];
