<?php

namespace App\Services\Wallet;

use App\Models\FiatWallet;
use App\Models\User;

class WalletService
{
    /**
     * Create a fiat wallet for user
     */
    public function createFiatWallet(int $userId, string $currency = 'NGN', string $countryCode = 'NG'): FiatWallet
    {
        // Check if wallet already exists
        $existingWallet = FiatWallet::where('user_id', $userId)
            ->where('currency', $currency)
            ->where('country_code', $countryCode)
            ->first();

        if ($existingWallet) {
            return $existingWallet;
        }

        return FiatWallet::create([
            'user_id' => $userId,
            'currency' => $currency,
            'country_code' => $countryCode,
            'balance' => 0,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Get fiat wallet for user
     */
    public function getFiatWallet(int $userId, string $currency = 'NGN', string $countryCode = 'NG'): ?FiatWallet
    {
        return FiatWallet::where('user_id', $userId)
            ->where('currency', $currency)
            ->where('country_code', $countryCode)
            ->first();
    }

    /**
     * Get all fiat wallets for user
     */
    public function getUserFiatWallets(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return FiatWallet::where('user_id', $userId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get total fiat balance
     */
    public function getTotalFiatBalance(int $userId, string $currency = 'NGN'): float
    {
        $wallet = $this->getFiatWallet($userId, $currency);
        
        return $wallet ? (float) $wallet->balance : 0;
    }
}
