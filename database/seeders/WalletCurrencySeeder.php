<?php

namespace Database\Seeders;

use App\Models\WalletCurrency;
use Illuminate\Database\Seeder;

/**
 * Launch set only (v1): ETH + USDT (ERC-20), BTC, DOGE, BSC USDT, Tron USDT.
 * Other chains stay out of seed; use migration `*_enforce_launch_wallet_currencies` to deactivate legacy rows.
 */
class WalletCurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            // Ethereum — native + USDT (ERC-20)
            [
                'blockchain' => 'ethereum',
                'currency' => 'ETH',
                'symbol' => 'ETH',
                'name' => 'Ethereum',
                'icon' => 'wallet_symbols/ETH.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 3000.0,
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 18,
                'is_token' => false,
                'blockchain_name' => 'Ethereum',
                'is_active' => true,
            ],
            [
                'blockchain' => 'ethereum',
                'currency' => 'USDT',
                'symbol' => 'USDT',
                'name' => 'Tether USD',
                'icon' => 'wallet_symbols/TUSDT.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'ERC-20',
                'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
                'decimals' => 6,
                'is_token' => true,
                'blockchain_name' => 'Ethereum',
                'is_active' => true,
            ],
            // Bitcoin
            [
                'blockchain' => 'bitcoin',
                'currency' => 'BTC',
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'icon' => 'wallet_symbols/btc.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 50000.0,
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 8,
                'is_token' => false,
                'blockchain_name' => 'Bitcoin',
                'is_active' => true,
            ],
            // Dogecoin
            [
                'blockchain' => 'dogecoin',
                'currency' => 'DOGE',
                'symbol' => 'DOGE',
                'name' => 'Dogecoin',
                'icon' => 'wallet_symbols/dogecoin-doge-logo.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 0.1,
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 8,
                'is_token' => false,
                'blockchain_name' => 'Dogecoin',
                'is_active' => true,
            ],
            // BSC — USDT (BEP-20) only for launch
            [
                'blockchain' => 'bsc',
                'currency' => 'USDT_BSC',
                'symbol' => 'USDT',
                'name' => 'Tether USD (BSC)',
                'icon' => 'wallet_symbols/TUSDT.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'BEP-20',
                'contract_address' => '0x55d398326f99059fF775485246999027B3197955',
                'decimals' => 18,
                'is_token' => true,
                'blockchain_name' => 'Binance Smart Chain',
                'is_active' => true,
            ],
            // TRON — USDT (TRC-20) only for launch
            [
                'blockchain' => 'tron',
                'currency' => 'USDT_TRON',
                'symbol' => 'USDT',
                'name' => 'Tether USD (TRON)',
                'icon' => 'wallet_symbols/TUSDT.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'TRC-20',
                'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'decimals' => 6,
                'is_token' => true,
                'blockchain_name' => 'TRON',
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            WalletCurrency::updateOrCreate(
                [
                    'blockchain' => $currency['blockchain'],
                    'currency' => $currency['currency'],
                ],
                $currency
            );
        }
    }
}
