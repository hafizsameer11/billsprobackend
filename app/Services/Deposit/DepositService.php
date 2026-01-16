<?php

namespace App\Services\Deposit;

use App\Models\BankAccount;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\FiatWallet;
use App\Services\Transaction\TransactionService;
use Illuminate\Support\Facades\DB;

class DepositService
{

    /**
     * Get active bank account for deposits
     */
    public function getDepositBankAccount(string $currency = 'NGN', string $countryCode = 'NG'): ?BankAccount
    {
        return BankAccount::where('currency', $currency)
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Initiate deposit
     */
    public function initiateDeposit(int $userId, array $data): array
    {
        $amount = (float) $data['amount'];
        $fee = $this->calculateFee($amount);
        $totalAmount = $amount + $fee;

        // Get bank account for deposit
        $bankAccount = $this->getDepositBankAccount($data['currency'] ?? 'NGN', $data['country_code'] ?? 'NG');

        if (!$bankAccount) {
            return [
                'success' => false,
                'message' => 'No active bank account found for deposits',
            ];
        }

        // Generate deposit reference
        $reference = Deposit::generateReference();

        // Create deposit record
        $deposit = Deposit::create([
            'user_id' => $userId,
            'bank_account_id' => $bankAccount->id,
            'deposit_reference' => $reference,
            'currency' => $data['currency'] ?? 'NGN',
            'amount' => $amount,
            'fee' => $fee,
            'total_amount' => $totalAmount,
            'status' => 'pending',
            'payment_method' => $data['payment_method'] ?? 'bank_transfer',
            'metadata' => [
                'bank_name' => $bankAccount->bank_name,
                'account_number' => $bankAccount->account_number,
                'account_name' => $bankAccount->account_name,
            ],
        ]);

        return [
            'success' => true,
            'message' => 'Deposit initiated successfully',
            'deposit' => $deposit,
            'bank_account' => $bankAccount,
            'reference' => $reference,
        ];
    }

    /**
     * Confirm deposit (mark as completed and credit wallet)
     */
    public function confirmDeposit(int $userId, string $reference): array
    {
        $deposit = Deposit::where('user_id', $userId)
            ->where('deposit_reference', $reference)
            ->where('status', 'pending')
            ->first();

        if (!$deposit) {
            return [
                'success' => false,
                'message' => 'Deposit not found or already processed',
            ];
        }

        return DB::transaction(function () use ($deposit, $userId) {
            // Update deposit status
            $deposit->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'deposit',
                'category' => 'fiat_deposit',
                'status' => 'completed',
                'currency' => $deposit->currency,
                'amount' => $deposit->amount,
                'fee' => $deposit->fee,
                'total_amount' => $deposit->total_amount,
                'reference' => $deposit->deposit_reference,
                'description' => "Deposit of {$deposit->currency} {$deposit->amount}",
                'bank_name' => $deposit->bankAccount->bank_name ?? null,
                'account_number' => $deposit->bankAccount->account_number ?? null,
                'account_name' => $deposit->bankAccount->account_name ?? null,
                'metadata' => [
                    'deposit_id' => $deposit->id,
                    'payment_method' => $deposit->payment_method,
                ],
            ]);

            // Link transaction to deposit
            $deposit->update(['transaction_id' => $transaction->id]);

            // Credit user's fiat wallet
            $countryCode = $deposit->currency === 'NGN' ? 'NG' : ($deposit->currency === 'USD' ? 'US' : 'NG');
            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', $deposit->currency)
                ->where('country_code', $countryCode)
                ->first();

            if ($fiatWallet) {
                $fiatWallet->increment('balance', $deposit->amount);
            } else {
                // Create wallet if it doesn't exist
                $fiatWallet = FiatWallet::create([
                    'user_id' => $userId,
                    'currency' => $deposit->currency,
                    'country_code' => $countryCode,
                    'balance' => $deposit->amount,
                    'locked_balance' => 0,
                    'is_active' => true,
                ]);
            }

            return [
                'success' => true,
                'message' => 'Deposit confirmed and wallet credited successfully',
                'deposit' => $deposit->fresh(),
                'transaction' => $transaction,
            ];
        });
    }

    /**
     * Calculate deposit fee
     */
    protected function calculateFee(float $amount): float
    {
        // Fixed fee of N200 for instant transfer
        return 200.0;
    }

    /**
     * Get user deposits
     */
    public function getUserDeposits(int $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return Deposit::where('user_id', $userId)
            ->with(['bankAccount', 'transaction'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get deposit by reference
     */
    public function getDepositByReference(int $userId, string $reference): ?Deposit
    {
        return Deposit::where('user_id', $userId)
            ->where('deposit_reference', $reference)
            ->with(['bankAccount', 'transaction'])
            ->first();
    }
}
