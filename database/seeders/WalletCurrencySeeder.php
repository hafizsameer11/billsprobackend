<?php

namespace Database\Seeders;

use App\Models\WalletCurrency;
use Illuminate\Database\Seeder;

class WalletCurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            // Ethereum
            [
                'blockchain' => 'ethereum',
                'currency' => 'ETH',
                'symbol' => 'ETH',
                'name' => 'Ethereum',
                'icon' => 'wallet_symbols/ETH.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 3000.0, // Price per ETH in USD (example: 1 ETH = 3000 USD)
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
                'rate' => 1.0, // USDT is pegged to USD
                'token_type' => 'ERC-20',
                'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
                'decimals' => 6,
                'is_token' => true,
                'blockchain_name' => 'Ethereum',
                'is_active' => true,
            ],
            [
                'blockchain' => 'ethereum',
                'currency' => 'USDC',
                'symbol' => 'USDC',
                'name' => 'USD Coin',
                'icon' => 'wallet_symbols/TUSDT.png', // Using USDT icon as placeholder
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'ERC-20',
                'contract_address' => '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
                'decimals' => 6,
                'is_token' => true,
                'blockchain_name' => 'Ethereum',
                'is_active' => true,
            ],
            // TRON
            [
                'blockchain' => 'tron',
                'currency' => 'TRX',
                'symbol' => 'TRX',
                'name' => 'TRON',
                'icon' => 'wallet_symbols/trx.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 0.1, // Price per TRX in USD (example: 1 TRX = 0.1 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 18,
                'is_token' => false,
                'blockchain_name' => 'TRON',
                'is_active' => true,
            ],
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
            // BSC
            [
                'blockchain' => 'bsc',
                'currency' => 'BNB',
                'symbol' => 'BNB',
                'name' => 'Binance Coin',
                'icon' => 'wallet_symbols/BSC.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 300.0, // Price per BNB in USD (example: 1 BNB = 300 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 18,
                'is_token' => false,
                'blockchain_name' => 'Binance Smart Chain',
                'is_active' => true,
            ],
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
            // Bitcoin
            [
                'blockchain' => 'bitcoin',
                'currency' => 'BTC',
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'icon' => 'wallet_symbols/btc.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 50000.0, // Price per BTC in USD (example: 1 BTC = 50000 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 8,
                'is_token' => false,
                'blockchain_name' => 'Bitcoin',
                'is_active' => true,
            ],
            // Solana
            [
                'blockchain' => 'solana',
                'currency' => 'SOL',
                'symbol' => 'SOL',
                'name' => 'Solana',
                'icon' => 'wallet_symbols/sol.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 100.0, // Price per SOL in USD (example: 1 SOL = 100 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 9,
                'is_token' => false,
                'blockchain_name' => 'Solana',
                'is_active' => true,
            ],
            [
                'blockchain' => 'solana',
                'currency' => 'USDT_SOL',
                'symbol' => 'USDT',
                'name' => 'Tether USD (Solana)',
                'icon' => 'wallet_symbols/TUSDT.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'SPL',
                'contract_address' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB',
                'decimals' => 6,
                'is_token' => true,
                'blockchain_name' => 'Solana',
                'is_active' => true,
            ],
            // Polygon
            [
                'blockchain' => 'polygon',
                'currency' => 'MATIC',
                'symbol' => 'MATIC',
                'name' => 'Polygon',
                'icon' => 'wallet_symbols/polygon-matic-logo.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 0.8, // Price per MATIC in USD (example: 1 MATIC = 0.8 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 18,
                'is_token' => false,
                'blockchain_name' => 'Polygon',
                'is_active' => true,
            ],
            [
                'blockchain' => 'polygon',
                'currency' => 'USDT_POLYGON',
                'symbol' => 'USDT',
                'name' => 'Tether USD (Polygon)',
                'icon' => 'wallet_symbols/TUSDT.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'ERC-20',
                'contract_address' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
                'decimals' => 6,
                'is_token' => true,
                'blockchain_name' => 'Polygon',
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
                'rate' => 0.1, // Price per DOGE in USD (example: 1 DOGE = 0.1 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 8,
                'is_token' => false,
                'blockchain_name' => 'Dogecoin',
                'is_active' => true,
            ],
            // XRP
            [
                'blockchain' => 'xrp',
                'currency' => 'XRP',
                'symbol' => 'XRP',
                'name' => 'Ripple',
                'icon' => 'wallet_symbols/xrp-xrp-logo.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 0.5, // Price per XRP in USD (example: 1 XRP = 0.5 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 6,
                'is_token' => false,
                'blockchain_name' => 'XRP Ledger',
                'is_active' => true,
            ],
            // Litecoin
            [
                'blockchain' => 'litecoin',
                'currency' => 'LTC',
                'symbol' => 'LTC',
                'name' => 'Litecoin',
                'icon' => 'wallet_symbols/btc.png', // Using BTC icon as placeholder
                'price' => null,
                'naira_price' => null,
                'rate' => 80.0, // Price per LTC in USD (example: 1 LTC = 80 USD)
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 8,
                'is_token' => false,
                'blockchain_name' => 'Litecoin',
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
