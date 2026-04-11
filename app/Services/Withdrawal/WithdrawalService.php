<?php

namespace App\Services\Withdrawal;

use App\Models\BankAccount;
use App\Models\FiatWallet;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PalmPay\PalmPayPayoutService;
use App\Services\Platform\PlatformRateResolver;
use App\Services\Wallet\WalletService;
use App\Support\PalmPayConfig;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class WithdrawalService
{
    protected WalletService $walletService;

    protected float $withdrawalFee = 200.00; // N200 withdrawal fee

    public function __construct(
        WalletService $walletService,
        protected PalmPayPayoutService $palmPayPayout,
        protected PlatformRateResolver $platformRates
    ) {
        $this->walletService = $walletService;
    }

    /**
     * Get all bank accounts for a user
     */
    public function getUserBankAccounts(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return BankAccount::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Add a new bank account for a user
     */
    public function addBankAccount(int $userId, array $data): BankAccount
    {
        try {
            // Check if account already exists for this user
            $existingAccount = BankAccount::where('user_id', $userId)
                ->where('account_number', $data['account_number'])
                ->first();

            if ($existingAccount) {
                throw new \Exception('Bank account already exists');
            }

            // Check if this is the first account for the user (set as default)
            $hasExistingAccounts = BankAccount::where('user_id', $userId)
                ->where('is_active', true)
                ->exists();

            return BankAccount::create([
                'user_id' => $userId,
                'bank_name' => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'],
                'currency' => $data['currency'] ?? 'NGN',
                'country_code' => $data['country_code'] ?? 'NG',
                'is_active' => true,
                'is_default' => ! $hasExistingAccounts, // Set as default if first account
                'metadata' => $data['metadata'] ?? null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Log the actual database error
            Log::error('Database error while adding bank account', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql' => $e->getSql() ?? 'N/A',
            ]);

            // Check for unique constraint violation
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new \Exception('Bank account already exists');
            }

            // Re-throw with more context
            throw new \Exception('Database error: '.$e->getMessage());
        } catch (\Exception $e) {
            // Re-throw other exceptions as-is
            throw $e;
        }
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

        $wasDefault = $bankAccount->is_default;

        // Soft delete by deactivating
        $bankAccount->update(['is_active' => false, 'is_default' => false]);

        // If this was the default account, set the first remaining account as default
        if ($wasDefault) {
            $firstRemaining = BankAccount::where('user_id', $userId)
                ->where('is_active', true)
                ->orderBy('created_at', 'asc')
                ->first();

            if ($firstRemaining) {
                $firstRemaining->update(['is_default' => true]);
            }
        }

        return true;
    }

    /**
     * Set a bank account as default
     */
    public function setDefaultBankAccount(int $userId, int $bankAccountId): BankAccount
    {
        $bankAccount = BankAccount::where('user_id', $userId)
            ->where('id', $bankAccountId)
            ->where('is_active', true)
            ->firstOrFail();

        // Remove default from all other accounts
        BankAccount::where('user_id', $userId)
            ->where('id', '!=', $bankAccountId)
            ->update(['is_default' => false]);

        // Set this account as default
        $bankAccount->update(['is_default' => true]);

        return $bankAccount->fresh();
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
        if (PalmPayConfig::usePalmPayForWithdrawal()) {
            return $this->processPalmPayWithdrawal($userId, $bankAccountId, $amount, $pin);
        }

        return $this->processLegacyInternalWithdrawal($userId, $bankAccountId, $amount, $pin);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPalmPayBanks(int $businessType = 0): array
    {
        return $this->palmPayPayout->queryBankList($businessType);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPalmPayAccount(string $bankCode, string $accountNumber): array
    {
        return $this->palmPayPayout->verifyAccount($bankCode, $accountNumber);
    }

    public function processPalmPayWithdrawalDirect(
        int $userId,
        float $amount,
        string $pin,
        string $bankCode,
        string $accountNumber,
        string $accountName,
        ?string $phoneNumber = null
    ): array {
        if (! PalmPayConfig::usePalmPayForWithdrawal()) {
            throw new \RuntimeException('PalmPay withdrawals are disabled.');
        }

        $user = User::findOrFail($userId);
        if (! $user->pin || ! Hash::check($pin, $user->pin)) {
            throw new \Exception('Invalid PIN');
        }
        if ($amount <= 0) {
            throw new \Exception('Invalid withdrawal amount');
        }

        $normalizedAccount = preg_replace('/\D/', '', $accountNumber) ?? '';
        if ($normalizedAccount === '') {
            throw new \Exception('Invalid account number');
        }
        if (trim($bankCode) === '') {
            throw new \Exception('Bank code is required');
        }
        if (trim($accountName) === '') {
            throw new \Exception('Account name is required');
        }

        $fee = $this->getWithdrawalFee();
        $totalAmount = $amount + $fee;

        $webhookBase = rtrim((string) Config::get('palmpay.webhook_url'), '/');
        if ($webhookBase === '') {
            throw new \RuntimeException('PALMPAY_WEBHOOK_URL is not configured.');
        }

        $transactionId = Transaction::generateTransactionId();
        $reference = 'WDR'.time().strtoupper(substr($transactionId, 0, 8));
        $palmMerchantOrderId = 'payout_'.substr(bin2hex(random_bytes(12)), 0, 24);

        $transaction = Transaction::create([
            'user_id' => $userId,
            'transaction_id' => $transactionId,
            'type' => 'withdrawal',
            'category' => 'fiat_withdrawal',
            'status' => 'pending',
            'currency' => 'NGN',
            'amount' => $amount,
            'fee' => $fee,
            'total_amount' => $totalAmount,
            'reference' => $reference,
            'description' => "Withdrawal to {$bankCode} - {$normalizedAccount}",
            'bank_name' => $bankCode,
            'account_number' => $normalizedAccount,
            'account_name' => trim($accountName),
            'metadata' => [
                'withdrawal_type' => 'palmpay_payout',
                'provider' => 'palmpay',
                'palmpay_merchant_order_id' => $palmMerchantOrderId,
                'palmpay_bank_code' => trim($bankCode),
            ],
        ]);

        $amountCents = (int) round($amount * 100);
        $payeePhone = $this->formatPayeePhone($phoneNumber ?: $user->phone_number);

        try {
            $resp = $this->palmPayPayout->initiatePayout([
                'orderId' => $palmMerchantOrderId,
                'title' => 'Withdrawal',
                'description' => "Withdrawal to {$normalizedAccount}",
                'payeeName' => trim($accountName),
                'payeeBankCode' => trim($bankCode),
                'payeeBankAccNo' => $normalizedAccount,
                'payeePhoneNo' => $payeePhone,
                'currency' => 'NGN',
                'amount' => $amountCents,
                'notifyUrl' => $webhookBase,
                'remark' => 'Withdrawal user '.$userId.' tx '.$transactionId,
            ]);
        } catch (\Throwable $e) {
            $transaction->update([
                'status' => 'failed',
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'error' => $e->getMessage(),
                ]),
            ]);
            throw $e;
        }

        $orderStatus = isset($resp['orderStatus']) ? (int) $resp['orderStatus'] : 0;
        $orderNo = isset($resp['orderNo']) ? (string) $resp['orderNo'] : '';

        if ($orderStatus === 3 || $orderStatus === 4) {
            $transaction->update([
                'status' => 'failed',
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'palmpay_response' => $resp,
                ]),
            ]);

            throw new \Exception($resp['message'] ?? $resp['errorMsg'] ?? 'PalmPay rejected the payout');
        }

        return DB::transaction(function () use ($userId, $totalAmount, $transaction, $resp, $orderStatus, $orderNo, $bankCode, $normalizedAccount, $accountName, $amount, $fee) {
            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', 'NGN')
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();

            if (! $fiatWallet) {
                throw new \Exception('Fiat wallet not found');
            }

            $availableBalance = (float) $fiatWallet->balance - (float) $fiatWallet->locked_balance;
            if ($availableBalance < $totalAmount) {
                throw new \Exception('Insufficient balance');
            }

            $fiatWallet->decrement('balance', $totalAmount);

            $meta = array_merge($transaction->metadata ?? [], [
                'palmpay_order_no' => $orderNo,
                'palmpay_order_status' => $orderStatus,
                'palmpay_response' => $resp,
                'wallet_debited' => true,
                'palmpay_session_id' => $resp['sessionId'] ?? null,
            ]);

            $transaction->update([
                'status' => $orderStatus === 2 ? 'completed' : 'pending',
                'completed_at' => $orderStatus === 2 ? now() : null,
                'metadata' => $meta,
            ]);

            return [
                'transaction' => $transaction->fresh(),
                'bank_account' => [
                    'bank_name' => $bankCode,
                    'bank_code' => $bankCode,
                    'account_number' => $normalizedAccount,
                    'account_name' => trim($accountName),
                ],
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'payout_status' => $orderStatus === 2 ? 'completed' : 'pending',
                'provider' => 'palmpay',
                'palmpay' => [
                    'orderNo' => $orderNo,
                    'orderStatus' => $orderStatus,
                    'sessionId' => $resp['sessionId'] ?? null,
                ],
            ];
        });
    }

    /**
     * Legacy: instant ledger debit only (no outbound bank API).
     */
    private function processLegacyInternalWithdrawal(
        int $userId,
        int $bankAccountId,
        float $amount,
        string $pin
    ): array {
        return DB::transaction(function () use ($userId, $bankAccountId, $amount, $pin) {
            // Verify PIN
            $user = User::findOrFail($userId);
            if (! $user->pin || ! Hash::check($pin, $user->pin)) {
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
            $fee = $this->getWithdrawalFee();
            $totalAmount = $amount + $fee;

            // Get fiat wallet with lock
            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', 'NGN')
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();

            if (! $fiatWallet) {
                throw new \Exception('Fiat wallet not found');
            }

            // Check sufficient balance inside transaction with lock
            $availableBalance = (float) $fiatWallet->balance - (float) $fiatWallet->locked_balance;
            if ($availableBalance < $totalAmount) {
                throw new \Exception('Insufficient balance');
            }

            // Generate transaction ID
            $transactionId = Transaction::generateTransactionId();
            $reference = 'WDR'.time().strtoupper(substr($transactionId, 0, 8));

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'type' => 'withdrawal',
                'category' => 'fiat_withdrawal',
                'status' => 'completed',
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
                    'provider' => 'internal',
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
                'payout_status' => 'completed',
                'provider' => 'internal',
            ];
        });
    }

    /**
     * PalmPay merchant payout to user's bank account.
     */
    private function processPalmPayWithdrawal(
        int $userId,
        int $bankAccountId,
        float $amount,
        string $pin
    ): array {
        $user = User::findOrFail($userId);
        if (! $user->pin || ! Hash::check($pin, $user->pin)) {
            throw new \Exception('Invalid PIN');
        }

        if ($amount <= 0) {
            throw new \Exception('Invalid withdrawal amount');
        }

        $bankAccount = BankAccount::where('user_id', $userId)
            ->where('id', $bankAccountId)
            ->where('is_active', true)
            ->firstOrFail();

        $bankCode = $this->resolvePalmPayBankCode($bankAccount);
        if ($bankCode === '') {
            throw new \Exception('bank_code is required for withdrawals. Add the PalmPay bank code on your bank account (e.g. 058 for GTBank).');
        }

        $fee = $this->getWithdrawalFee();
        $totalAmount = $amount + $fee;

        $webhookBase = rtrim((string) Config::get('palmpay.webhook_url'), '/');
        if ($webhookBase === '') {
            throw new \RuntimeException('PALMPAY_WEBHOOK_URL is not configured.');
        }

        $transactionId = Transaction::generateTransactionId();
        $reference = 'WDR'.time().strtoupper(substr($transactionId, 0, 8));
        $palmMerchantOrderId = 'payout_'.substr(bin2hex(random_bytes(12)), 0, 24);

        $transaction = Transaction::create([
            'user_id' => $userId,
            'transaction_id' => $transactionId,
            'type' => 'withdrawal',
            'category' => 'fiat_withdrawal',
            'status' => 'pending',
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
                'withdrawal_type' => 'palmpay_payout',
                'provider' => 'palmpay',
                'palmpay_merchant_order_id' => $palmMerchantOrderId,
            ],
        ]);

        $amountCents = (int) round($amount * 100);
        $payeePhone = $this->formatPayeePhone($user->phone_number);

        try {
            $resp = $this->palmPayPayout->initiatePayout([
                'orderId' => $palmMerchantOrderId,
                'title' => 'Withdrawal',
                'description' => "Withdrawal to {$bankAccount->account_number}",
                'payeeName' => $bankAccount->account_name,
                'payeeBankCode' => $bankCode,
                'payeeBankAccNo' => $bankAccount->account_number,
                'payeePhoneNo' => $payeePhone,
                'currency' => 'NGN',
                'amount' => $amountCents,
                'notifyUrl' => $webhookBase,
                'remark' => 'Withdrawal user '.$userId.' tx '.$transactionId,
            ]);
        } catch (\Throwable $e) {
            $transaction->update([
                'status' => 'failed',
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'error' => $e->getMessage(),
                ]),
            ]);
            Log::error('PalmPay payout failed', ['user_id' => $userId, 'e' => $e->getMessage()]);

            throw $e;
        }

        $orderStatus = isset($resp['orderStatus']) ? (int) $resp['orderStatus'] : 0;
        $orderNo = isset($resp['orderNo']) ? (string) $resp['orderNo'] : '';

        if ($orderStatus === 3 || $orderStatus === 4) {
            $transaction->update([
                'status' => 'failed',
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'palmpay_response' => $resp,
                ]),
            ]);

            throw new \Exception($resp['message'] ?? $resp['errorMsg'] ?? 'PalmPay rejected the payout');
        }

        return DB::transaction(function () use ($userId, $totalAmount, $transaction, $resp, $orderStatus, $orderNo, $bankAccount, $amount, $fee) {
            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', 'NGN')
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();

            if (! $fiatWallet) {
                throw new \Exception('Fiat wallet not found');
            }

            $availableBalance = (float) $fiatWallet->balance - (float) $fiatWallet->locked_balance;
            if ($availableBalance < $totalAmount) {
                throw new \Exception('Insufficient balance');
            }

            $fiatWallet->decrement('balance', $totalAmount);

            $meta = array_merge($transaction->metadata ?? [], [
                'palmpay_order_no' => $orderNo,
                'palmpay_order_status' => $orderStatus,
                'palmpay_response' => $resp,
                'wallet_debited' => true,
                'palmpay_session_id' => $resp['sessionId'] ?? null,
            ]);

            $transaction->update([
                'status' => $orderStatus === 2 ? 'completed' : 'pending',
                'completed_at' => $orderStatus === 2 ? now() : null,
                'metadata' => $meta,
            ]);

            Log::info('PalmPay withdrawal debited', [
                'user_id' => $userId,
                'transaction_id' => $transaction->transaction_id,
                'order_status' => $orderStatus,
            ]);

            return [
                'transaction' => $transaction->fresh(),
                'bank_account' => $bankAccount,
                'amount' => $amount,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'payout_status' => $orderStatus === 2 ? 'completed' : 'pending',
                'provider' => 'palmpay',
                'palmpay' => [
                    'orderNo' => $orderNo,
                    'orderStatus' => $orderStatus,
                    'sessionId' => $resp['sessionId'] ?? null,
                ],
            ];
        });
    }

    private function resolvePalmPayBankCode(BankAccount $bankAccount): string
    {
        if (filled($bankAccount->bank_code)) {
            return trim((string) $bankAccount->bank_code);
        }

        $meta = $bankAccount->metadata ?? [];

        return isset($meta['bank_code']) ? trim((string) $meta['bank_code']) : '';
    }

    private function formatPayeePhone(?string $phone): string
    {
        if (empty($phone)) {
            return '08000000000';
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return '08000000000';
        }
        if (str_starts_with($digits, '234')) {
            return '0'.substr($digits, 3);
        }
        if (str_starts_with($digits, '0')) {
            return $digits;
        }

        return '0'.$digits;
    }

    /**
     * PalmPay payout webhook: complete pending withdrawals or refund on terminal failure.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyPalmPayPayoutWebhook(array $payload): void
    {
        $orderId = isset($payload['orderId']) ? (string) $payload['orderId'] : '';
        if ($orderId === '' || ! str_starts_with($orderId, 'payout_')) {
            return;
        }

        $orderStatus = isset($payload['orderStatus']) ? (int) $payload['orderStatus'] : null;
        if ($orderStatus === null) {
            return;
        }

        $txn = Transaction::where('type', 'withdrawal')
            ->where('metadata->palmpay_merchant_order_id', $orderId)
            ->first();

        if (! $txn) {
            return;
        }

        $meta = $txn->metadata ?? [];
        if (! empty($meta['refunded_after_failed_payout'])) {
            return;
        }

        if ($orderStatus === 2) {
            if ($txn->status === 'pending') {
                $txn->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'metadata' => array_merge($meta, [
                        'palmpay_order_status' => 2,
                        'palmpay_webhook' => $payload,
                    ]),
                ]);
            }

            return;
        }

        if (($orderStatus === 3 || $orderStatus === 4) && $txn->status === 'pending' && ! empty($meta['wallet_debited'])) {
            DB::transaction(function () use ($txn, $orderStatus, $payload) {
                $locked = Transaction::where('id', $txn->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'pending') {
                    return;
                }

                $m = $locked->metadata ?? [];
                if (! empty($m['refunded_after_failed_payout'])) {
                    return;
                }

                $fiatWallet = FiatWallet::where('user_id', $locked->user_id)
                    ->where('currency', 'NGN')
                    ->where('country_code', 'NG')
                    ->lockForUpdate()
                    ->first();

                if ($fiatWallet) {
                    $fiatWallet->increment('balance', (float) $locked->total_amount);
                }

                $locked->update([
                    'status' => 'failed',
                    'metadata' => array_merge($m, [
                        'palmpay_order_status' => $orderStatus,
                        'palmpay_webhook' => $payload,
                        'refunded_after_failed_payout' => true,
                    ]),
                ]);
            });
        }
    }

    /**
     * Get withdrawal fee
     */
    public function getWithdrawalFee(): float
    {
        return $this->platformRates->fiatWithdrawalFeeNgn($this->withdrawalFee);
    }

    /**
     * Get transaction history for user
     */
    public function getTransactionHistory(int $userId, ?string $type = null, int $limit = 50): \Illuminate\Database\Eloquent\Collection
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
