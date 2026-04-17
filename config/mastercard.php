<?php

/**
 * Pagocards reseller API — base `https://pagocards.com/api`, paths below.
 *
 * Official routes (POST, JSON body, headers `publickey` + `secretkey`):
 *   /mastercard/createcard | fundcard | getcarddetails | getallcards
 *   /mastercard/blockdigital | unblockdigital
 *   /mastercard/check3ds | approve3ds | checkwallet
 *   /mastercard/spendcontrol | deletespendcontrol
 * Also used by this app (confirm with Pagocards if 404): terminatedigital, getcardtransactions.
 *
 * `env('KEY') ?: default` so blank `.env` lines cannot wipe paths.
 */
$pagoApiBase = 'https://pagocards.com/api';

return [
    'public_key' => env('MASTERCARD_API_PUBLIC_KEY'),
    'secret_key' => env('MASTERCARD_API_SECRET_KEY'),
    'timeout' => (int) (env('MASTERCARD_API_TIMEOUT') ?: 30),

    'base_url' => rtrim((string) (env('MASTERCARD_API_BASE_URL') ?: $pagoApiBase), '/'),

    'merchant_base_url' => rtrim((string) (env('MASTERCARD_API_MERCHANT_BASE_URL') ?: env('MASTERCARD_API_BASE_URL') ?: $pagoApiBase), '/'),

    'endpoints' => [
        'merchant_master_create' => env('MASTERCARD_API_CREATE_PATH') ?: '/mastercard/createcard',
        'merchant_master_get_all' => env('MASTERCARD_API_GET_ALL_PATH') ?: '/mastercard/getallcards',
        'merchant_master_get_card' => env('MASTERCARD_API_GET_CARD_PATH') ?: '/mastercard/getcarddetails',
        'merchant_master_fund' => env('MASTERCARD_API_FUND_PATH') ?: '/mastercard/fundcard',
        'merchant_master_block' => env('MASTERCARD_API_BLOCK_PATH') ?: '/mastercard/blockdigital',
        'merchant_master_unblock' => env('MASTERCARD_API_UNBLOCK_PATH') ?: '/mastercard/unblockdigital',
        'merchant_master_terminate' => env('MASTERCARD_API_TERMINATE_PATH') ?: '/mastercard/terminatedigital',
        'merchant_master_transactions' => env('MASTERCARD_API_TRANSACTIONS_PATH') ?: '/mastercard/getcardtransactions',
        'merchant_master_check_3ds' => env('MASTERCARD_API_CHECK_3DS_PATH') ?: '/mastercard/check3ds',
        'merchant_master_check_wallet' => env('MASTERCARD_API_CHECK_WALLET_PATH') ?: '/mastercard/checkwallet',
        'merchant_master_approve_3ds' => env('MASTERCARD_API_APPROVE_3DS_PATH') ?: '/mastercard/approve3ds',
        'merchant_master_spend_control' => env('MASTERCARD_API_SPEND_CONTROL_PATH') ?: '/mastercard/spendcontrol',
        'merchant_master_delete_spend_control' => env('MASTERCARD_API_DELETE_SPEND_CONTROL_PATH') ?: '/mastercard/deletespendcontrol',
    ],
];
