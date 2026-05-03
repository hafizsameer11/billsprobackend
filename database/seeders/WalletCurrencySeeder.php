<?php

namespace Database\Seeders;

use App\Models\WalletCurrency;
use Illuminate\Database\Seeder;

/**
 * Active chains: ETH, USDT (ERC-20), USDC (ERC-20), BTC, DOGE, BSC (native `BSC` per Tatum + USDT + USDC), Tron (TRX + USDT), Solana (SOL).
 * Tatum V3: GET /v3/{chain}/wallet then address from xpub (EVM/BTC/DOGE/TRON) or single keypair (Solana). See https://docs.tatum.io/docs/address-management
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
            // TRON — native TRX (same chain address as TRC-20; DepositAddressService reuses per-chain address)
            [
                'blockchain' => 'tron',
                'currency' => 'TRX',
                'symbol' => 'TRX',
                'name' => 'TRON',
                'icon' => 'wallet_symbols/trx.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 0.12,
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 6,
                'is_token' => false,
                'blockchain_name' => 'TRON',
                'is_active' => true,
            ],
            // Ethereum — USDC (ERC-20)
            [
                'blockchain' => 'ethereum',
                'currency' => 'USDC',
                'symbol' => 'USDC',
                'name' => 'USD Coin',
                'icon' => 'wallet_symbols/usdc.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'ERC-20',
                'contract_address' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                'decimals' => 6,
                'is_token' => true,
                'blockchain_name' => 'Ethereum',
                'is_active' => true,
            ],
            // BSC — USDC (BEP-20); ledger currency USDC_BSC (same pattern as USDT_BSC)
            [
                'blockchain' => 'bsc',
                'currency' => 'USDC_BSC',
                'symbol' => 'USDC',
                'name' => 'USD Coin (BSC)',
                'icon' => 'wallet_symbols/usdc.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 1.0,
                'token_type' => 'BEP-20',
                'contract_address' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                'decimals' => 18,
                'is_token' => true,
                'blockchain_name' => 'Binance Smart Chain',
                'is_active' => true,
            ],
            // BSC — native (Tatum `/v3/bsc/transaction` uses currency `BSC`, not `BNB`; Beacon chain uses `BNB`.)
            [
                'blockchain' => 'bsc',
                'currency' => 'BSC',
                'symbol' => 'BSC',
                'name' => 'BNB Smart Chain (native)',
                'icon' => 'wallet_symbols/bsc.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 600.0,
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 18,
                'is_token' => false,
                'blockchain_name' => 'Binance Smart Chain',
                'is_active' => true,
            ],
            // Solana — native SOL
            [
                'blockchain' => 'solana',
                'currency' => 'SOL',
                'symbol' => 'SOL',
                'name' => 'Solana',
                'icon' => 'wallet_symbols/sol.png',
                'price' => null,
                'naira_price' => null,
                'rate' => 150.0,
                'token_type' => null,
                'contract_address' => null,
                'decimals' => 9,
                'is_token' => false,
                'blockchain_name' => 'Solana',
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
