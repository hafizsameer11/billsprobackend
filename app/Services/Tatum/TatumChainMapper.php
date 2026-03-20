<?php

namespace App\Services\Tatum;

/**
 * Maps app blockchain names to Tatum V4 `chain` identifiers (subscription API).
 */
class TatumChainMapper
{
    /**
     * @var array<string, string>
     */
    protected static array $map = [
        'bitcoin' => 'bitcoin-mainnet',
        'ethereum' => 'ethereum-mainnet',
        'eth' => 'ethereum-mainnet',
        'tron' => 'tron-mainnet',
        'bsc' => 'bsc-mainnet',
        'solana' => 'solana-mainnet',
        'sol' => 'solana-mainnet',
        'litecoin' => 'litecoin-core-mainnet',
        'ltc' => 'litecoin-core-mainnet',
        'polygon' => 'polygon-mainnet',
        'matic' => 'polygon-mainnet',
        'dogecoin' => 'doge-mainnet',
        'doge' => 'doge-mainnet',
        'xrp' => 'ripple-mainnet',
        'ripple' => 'ripple-mainnet',
        'arbitrum' => 'arb-one-mainnet',
        'optimism' => 'optimism-mainnet',
        'base' => 'base-mainnet',
        'avalanche' => 'avax-mainnet',
        'avax' => 'avax-mainnet',
        'fantom' => 'fantom-mainnet',
        'celo' => 'celo-mainnet',
        'bitcoin-testnet' => 'bitcoin-testnet',
        'ethereum-sepolia' => 'ethereum-sepolia',
        'ethereum-holesky' => 'ethereum-holesky',
        'tron-testnet' => 'tron-testnet',
        'bsc-testnet' => 'bsc-testnet',
        'solana-devnet' => 'solana-devnet',
        'litecoin-testnet' => 'litecoin-core-testnet',
        'polygon-amoy' => 'polygon-amoy',
        'doge-testnet' => 'doge-testnet',
        'ripple-testnet' => 'ripple-testnet',
    ];

    public static function toV4Chain(string $blockchain): string
    {
        $normalized = strtolower(trim($blockchain));

        return self::$map[$normalized] ?? 'ethereum-mainnet';
    }
}
