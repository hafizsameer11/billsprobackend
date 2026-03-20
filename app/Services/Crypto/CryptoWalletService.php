<?php

namespace App\Services\Crypto;

use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CryptoWalletService
{
    /**
     * Initialize crypto wallets for a user
     * Creates virtual accounts for all active wallet currencies
     */
    public function initializeUserCryptoWallets(int $userId): array
    {
        $walletCurrencies = WalletCurrency::where('is_active', true)->get();
        $createdAccounts = [];

        foreach ($walletCurrencies as $currency) {
            // Check if virtual account already exists
            $existingAccount = VirtualAccount::where('user_id', $userId)
                ->where('blockchain', $currency->blockchain)
                ->where('currency', $currency->currency)
                ->first();

            if ($existingAccount) {
                continue;
            }

            // Generate unique account ID
            $accountId = $this->generateAccountId($userId, $currency->blockchain, $currency->currency);

            // Create virtual account
            $virtualAccount = VirtualAccount::create([
                'user_id' => $userId,
                'currency_id' => $currency->id,
                'blockchain' => $currency->blockchain,
                'currency' => $currency->currency,
                'customer_id' => 'CUST_'.$userId,
                'account_id' => $accountId,
                'account_code' => Str::random(10),
                'active' => true,
                'frozen' => false,
                'account_balance' => '0',
                'available_balance' => '0',
                'accounting_currency' => $currency->currency,
            ]);

            $createdAccounts[] = $virtualAccount;
        }

        return $createdAccounts;
    }

    /**
     * Generate unique account ID
     */
    protected function generateAccountId(int $userId, string $blockchain, string $currency): string
    {
        return strtoupper($blockchain).'_'.strtoupper($currency).'_'.$userId.'_'.time().'_'.Str::random(8);
    }

    /**
     * Get all virtual accounts for user
     */
    public function getUserVirtualAccounts(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return VirtualAccount::where('user_id', $userId)
            ->where('active', true)
            ->with('walletCurrency')
            ->get();
    }

    /**
     * Get virtual account by currency and blockchain
     */
    public function getVirtualAccount(int $userId, string $blockchain, string $currency): ?VirtualAccount
    {
        return VirtualAccount::where('user_id', $userId)
            ->where('blockchain', $blockchain)
            ->where('currency', $currency)
            ->where('active', true)
            ->with('walletCurrency')
            ->first();
    }

    /**
     * Get total crypto balance in USD
     */
    public function getTotalCryptoBalanceInUsd(int $userId): float
    {
        $virtualAccounts = $this->getUserVirtualAccounts($userId);
        $totalUsd = 0;

        foreach ($virtualAccounts as $account) {
            $balance = (float) $account->available_balance;

            if ($balance > 0 && $account->walletCurrency) {
                $rate = (float) ($account->walletCurrency->rate ?? 0);

                if ($rate > 0) {
                    // Convert to USD using rate
                    $usdValue = $balance * $rate;
                    $totalUsd += $usdValue;
                }
            }
        }

        return $totalUsd;
    }

    /**
     * Get crypto balance breakdown
     */
    public function getCryptoBalanceBreakdown(int $userId): array
    {
        $virtualAccounts = $this->getUserVirtualAccounts($userId);
        $breakdown = [];

        foreach ($virtualAccounts as $account) {
            $balance = (float) $account->available_balance;

            if ($balance > 0 && $account->walletCurrency) {
                $rate = (float) ($account->walletCurrency->rate ?? 0);
                $usdValue = $rate > 0 ? $balance * $rate : 0;

                $breakdown[] = [
                    'blockchain' => $account->blockchain,
                    'currency' => $account->currency,
                    'symbol' => $account->walletCurrency->symbol ?? $account->currency,
                    'balance' => $balance,
                    'rate' => $rate,
                    'usd_value' => $usdValue,
                ];
            }
        }

        return $breakdown;
    }

    /**
     * Deduct an amount expressed in USD from user's crypto virtual accounts (proportional by USD value).
     *
     * @return array{success: bool, deducted_usd: float, message?: string}
     */
    public function deductUsdEquivalent(int $userId, float $amountUsd): array
    {
        if ($amountUsd <= 0) {
            return ['success' => true, 'deducted_usd' => 0.0];
        }

        return DB::transaction(function () use ($userId, $amountUsd) {
            $accounts = VirtualAccount::where('user_id', $userId)
                ->where('active', true)
                ->with('walletCurrency')
                ->lockForUpdate()
                ->get();

            $totalAvailableUsd = 0.0;
            foreach ($accounts as $account) {
                $balance = (float) $account->available_balance;
                $rate = (float) ($account->walletCurrency->rate ?? 0);
                if ($balance > 0 && $rate > 0) {
                    $totalAvailableUsd += $balance * $rate;
                }
            }

            if ($totalAvailableUsd + 0.0000001 < $amountUsd) {
                return [
                    'success' => false,
                    'deducted_usd' => 0.0,
                    'message' => 'Insufficient crypto wallet balance for card fee.',
                ];
            }

            $remainingUsd = $amountUsd;

            foreach ($accounts as $account) {
                if ($remainingUsd <= 0) {
                    break;
                }

                $balance = (float) $account->available_balance;
                $rate = (float) ($account->walletCurrency->rate ?? 0);
                if ($balance <= 0 || $rate <= 0) {
                    continue;
                }

                $accountUsd = $balance * $rate;
                $takeUsd = min($remainingUsd, $accountUsd);
                $takeCrypto = $takeUsd / $rate;

                $account->decrement('available_balance', $takeCrypto);
                $account->decrement('account_balance', $takeCrypto);

                $remainingUsd -= $takeUsd;
            }

            if ($remainingUsd > 0.0001) {
                return [
                    'success' => false,
                    'deducted_usd' => 0.0,
                    'message' => 'Unable to complete crypto fee deduction.',
                ];
            }

            return ['success' => true, 'deducted_usd' => $amountUsd];
        });
    }
}
