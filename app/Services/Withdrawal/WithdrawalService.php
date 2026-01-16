<?php

namespace App\Services\Withdrawal;

use App\Models\BankAccount;
use App\Models\FiatWallet;
use App\Models\Transaction;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class WithdrawalService
{
    protected WalletService $walletService;
    protected float $withdrawalFee = 200.00; // N200 withdrawal fee

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Get all bank accounts for a user
     */
    public function getUserBankAccounts(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return BankAccount::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Add a new bank account for a user
     */
    public function addBankAccount(int $userId, array $data): BankAccount
    {
        // Check if account already exists for this user
        $existingAccount = BankAccount::where('user_id', $userId)
            ->where('account_number', $data['account_number'])
            ->first();

        if ($existingAccount) {
            throw new \Exception('Bank account already exists');
        }

        return BankAccount::create([
            'user_id' => $userId,
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_name' => $data['account_name'],
            'currency' => $data['currency'] ?? 'NGN',
            'country_code' => $data['country_code'] ?? 'NG',
            'is_active' => true,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Update a bank account
     */
    public function updateBankAccount(int $userId, int $bankAccountId, array $data): BankAccount
    {
        $bankAccount = BankAccount::where('user_id', $userId)
            ->where('id', $bankAccountId)
            ->firstOrFail();

        // If account_number is being changed, check for duplicates
        if (isset($data['account_number']) && $data['account_number'] !== $bankAccount->account_number) {
            $existingAccount = BankAccount::where('user_id', $userId)
                ->where('account_number', $data['account_number'])
                ->where('id', '!=', $bankAccountId)
                ->first();

            if ($existingAccount) {
                throw new \Exception('Bank account number already exists');
            }
        }

        $bankAccount->update($data);

        return $bankAccount->fresh();
    }

    /**
     * Delete (deactivate) a bank account
     */
    public function deleteBankAccount(int $userId, int $bankAccountId): bool
    {
        $bankAccount = BankAccount::where('user_id', $userId)
            ->where('id', $bankAccountId)
            ->firstOrFail();

        // Soft delete by deactivating
        $bankAccount->update(['is_active' => false]);

        return true;
    }

    /**
     * Process withdrawal with proper database transactions
     */
    public function processWithdrawal(
        int $userId,
        int $bankAccountId,
        float $amount,
        string $pin
    ): array {
        return DB::transaction(function () use ($userId, $bankAccountId, $amount, $pin) {
            // Verify PIN
            $user = \App\Models\User::findOrFail($userId);
            if (!$user->pin || !Hash::check($pin, $user->pin)) {
                throw new \Exception('Invalid PIN');
            }

            // Get bank account
            $bankAccount = BankAccount::where('user_id', $userId)
                ->where('id', $bankAccountId)
                ->where('is_active', true)
                ->firstOrFail();

            // Validate amount
            if ($amount <= 0) {
                throw new \Exception('Invalid withdrawal amount');
            }

            // Calculate total amount (amount + fee)
            $fee = $this->withdrawalFee;
            $totalAmount = $amount + $fee;

            // Get fiat wallet
            $fiatWallet = $this->walletService->getFiatWallet($userId, 'NGN', 'NG');
            if (!$fiatWallet) {
                throw new \Exception('Fiat wallet not found');
            }

            // Check sufficient balance
            $availableBalance = (float) $fiatWallet->balance - (float) $fiatWallet->locked_balance;
            if ($availableBalance < $totalAmount) {
                throw new \Exception('Insufficient balance');
            }

            // Generate transaction ID
            $transactionId = Transaction::generateTransactionId();
            $reference = 'WDR' . time() . strtoupper(substr($transactionId, 0, 8));

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'type' => 'withdrawal',
                'category' => 'fiat_withdrawal',
                'status' => 'completed', // Assuming instant transfer
                'currency' => 'NGN',
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'reference' => $reference,
                'description' => "Withdrawal to {$bankAccount->bank_name} - {$bankAccount->account_number}",
                'bank_name' => $bankAccount->bank_name,
                'account_number' => $bankAccount->account_number,
                'account_name' => $bankAccount->account_name,
                'metadata' => [
                    'bank_account_id' => $bankAccountId,
                    'withdrawal_type' => 'instant_transfer',
                ],
            ]);

            // Deduct from wallet balance
            $fiatWallet->decrement('balance', $totalAmount);

            Log::info('Withdrawal processed successfully', [
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
            ]);

            return [
                'transaction' => $transaction,
                'bank_account' => $bankAccount,
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
            ];
        });
    }

    /**
     * Get withdrawal fee
     */
    public function getWithdrawalFee(): float
    {
        return $this->withdrawalFee;
    }

    /**
     * Get transaction history for user
     */
    public function getTransactionHistory(int $userId, string $type = null, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $query = Transaction::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($type) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * Get transaction by ID
     */
    public function getTransaction(int $userId, string $transactionId): ?Transaction
    {
        return Transaction::where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->first();
    }
}
