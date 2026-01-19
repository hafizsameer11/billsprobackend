<?php

namespace App\Services\Transaction;

use App\Models\Transaction;
use App\Models\VirtualCardTransaction;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    /**
     * Create a transaction
     */
    public function createTransaction(array $data): Transaction
    {
        return Transaction::create([
            'user_id' => $data['user_id'],
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => $data['type'],
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'currency' => $data['currency'] ?? 'NGN',
            'amount' => $data['amount'],
            'fee' => $data['fee'] ?? 0,
            'total_amount' => $data['total_amount'] ?? ($data['amount'] + ($data['fee'] ?? 0)),
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_name' => $data['account_name'] ?? null,
        ]);
    }

    /**
     * Update transaction status
     */
    public function updateTransactionStatus(string $transactionId, string $status): bool
    {
        return Transaction::where('transaction_id', $transactionId)
            ->update(['status' => $status]);
    }

    /**
     * Get user transactions
     */
    public function getUserTransactions(int $userId, array $filters = [], int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $query = Transaction::where('user_id', $userId);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get user fiat transactions (NGN, USD, etc.)
     */
    public function getUserFiatTransactions(int $userId, array $filters = [], int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        // Fiat currencies (non-crypto)
        $fiatCurrencies = ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'GHS', 'ZAR', 'EGP'];
        
        $query = Transaction::where('user_id', $userId)
            ->whereIn('currency', $fiatCurrencies);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get transaction by ID (checks both Transaction and VirtualCardTransaction)
     */
    public function getTransaction(int $userId, string $transactionId)
    {
        // First, try to find in regular Transaction model
        $transaction = Transaction::where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->first();

        if ($transaction) {
            return $transaction;
        }

        // If not found, check VirtualCardTransaction model
        $cardTransaction = VirtualCardTransaction::where('user_id', $userId)
            ->where(function ($query) use ($transactionId) {
                $query->where('transaction_id', $transactionId)
                    ->orWhere('reference', $transactionId);
            })
            ->first();

        if ($cardTransaction) {
            // Convert VirtualCardTransaction to a format compatible with Transaction
            // We'll create a mock Transaction object or return an array
            return $cardTransaction;
        }

        return null;
    }

    /**
     * Get transaction statistics
     */
    public function getTransactionStats(int $userId, string $period = 'month'): array
    {
        $query = Transaction::where('user_id', $userId)
            ->where('status', 'completed');

        if ($period === 'month') {
            $query->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year);
        } elseif ($period === 'week') {
            $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($period === 'day') {
            $query->whereDate('created_at', now()->toDateString());
        }

        $totalDeposits = (clone $query)->where('type', 'deposit')->sum('amount');
        $totalWithdrawals = (clone $query)->where('type', 'withdrawal')->sum('amount');
        $totalBillPayments = (clone $query)->where('type', 'bill_payment')->sum('amount');
        $totalFees = (clone $query)->sum('fee');

        return [
            'total_deposits' => (float) $totalDeposits,
            'total_withdrawals' => (float) $totalWithdrawals,
            'total_bill_payments' => (float) $totalBillPayments,
            'total_fees' => (float) $totalFees,
            'period' => $period,
        ];
    }

    /**
     * Get all transactions (aggregated from all sources)
     * Includes: Regular transactions, Virtual Card transactions, Crypto transactions
     */
    public function getAllTransactions(int $userId, array $filters = [], int $limit = 50): array
    {
        $walletType = $filters['wallet_type'] ?? null; // naira, crypto, virtual_card
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;

        $allTransactions = collect();

        // Get regular transactions (Naira, Crypto, Bill Payments, etc.)
        if (!$walletType || $walletType === 'naira' || $walletType === 'crypto') {
            $transactionQuery = Transaction::where('user_id', $userId);

            // Filter by wallet type
            if ($walletType === 'naira') {
                // Fiat currencies only
                $fiatCurrencies = ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'GHS', 'ZAR', 'EGP'];
                $transactionQuery->whereIn('currency', $fiatCurrencies);
            } elseif ($walletType === 'crypto') {
                // Crypto currencies (exclude fiat or has crypto category or crypto transaction types)
                $fiatCurrencies = ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'GHS', 'ZAR', 'EGP'];
                $cryptoTypes = ['crypto_buy', 'crypto_sell', 'crypto_withdrawal'];
                $transactionQuery->where(function ($query) use ($fiatCurrencies, $cryptoTypes) {
                    $query->whereNotIn('currency', $fiatCurrencies)
                        ->orWhere('category', 'like', '%crypto%')
                        ->orWhereIn('type', $cryptoTypes);
                });
            }

            if ($type) {
                $transactionQuery->where('type', $type);
            }

            if ($status) {
                $transactionQuery->where('status', $status);
            }

            if ($startDate) {
                $transactionQuery->whereDate('created_at', '>=', $startDate);
            }

            if ($endDate) {
                $transactionQuery->whereDate('created_at', '<=', $endDate);
            }

            $transactions = $transactionQuery->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($transaction) {
                    $metadata = $transaction->metadata ?? [];
                    $isPrepaidElectricity = $transaction->type === 'bill_payment' 
                        && $transaction->category === 'electricity' 
                        && isset($metadata['accountType']) 
                        && $metadata['accountType'] === 'prepaid'
                        && isset($metadata['rechargeToken']);

                    $formatted = [
                        'id' => $transaction->id,
                        'transaction_id' => $transaction->transaction_id,
                        'wallet_type' => $this->determineWalletType($transaction),
                        'type' => $transaction->type,
                        'category' => $transaction->category,
                        'status' => $transaction->status,
                        'currency' => $transaction->currency,
                        'amount' => (float) $transaction->amount,
                        'fee' => (float) $transaction->fee,
                        'total_amount' => (float) $transaction->total_amount,
                        'reference' => $transaction->reference,
                        'description' => $transaction->description,
                        'metadata' => $metadata,
                        'created_at' => $transaction->created_at->toISOString(),
                        'updated_at' => $transaction->updated_at->toISOString(),
                    ];

                    // Add token field at top level for prepaid electricity
                    if ($isPrepaidElectricity) {
                        $formatted['token'] = $metadata['rechargeToken'];
                    }

                    return $formatted;
                });

            $allTransactions = $allTransactions->merge($transactions);
        }

        // Get virtual card transactions
        if (!$walletType || $walletType === 'virtual_card') {
            $cardTransactionQuery = VirtualCardTransaction::where('user_id', $userId);

            if ($type) {
                // Map transaction types to card transaction types
                if ($type === 'deposit' || $type === 'card_funding') {
                    $cardTransactionQuery->where('type', 'fund');
                } elseif ($type === 'withdrawal' || $type === 'card_withdrawal') {
                    $cardTransactionQuery->where('type', 'withdraw');
                } else {
                    $cardTransactionQuery->where('type', $type);
                }
            }

            if ($status) {
                $cardTransactionQuery->where('status', $status);
            }

            if ($startDate) {
                $cardTransactionQuery->whereDate('created_at', '>=', $startDate);
            }

            if ($endDate) {
                $cardTransactionQuery->whereDate('created_at', '<=', $endDate);
            }

            $cardTransactions = $cardTransactionQuery->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($cardTransaction) {
                    return [
                        'id' => $cardTransaction->id,
                        'transaction_id' => $cardTransaction->transaction_id ?? $cardTransaction->reference,
                        'wallet_type' => 'virtual_card',
                        'type' => $this->mapCardTransactionType($cardTransaction->type),
                        'category' => 'virtual_card',
                        'status' => $cardTransaction->status,
                        'currency' => $cardTransaction->currency,
                        'amount' => (float) $cardTransaction->amount,
                        'fee' => (float) $cardTransaction->fee,
                        'total_amount' => (float) $cardTransaction->total_amount,
                        'reference' => $cardTransaction->reference,
                        'description' => $cardTransaction->description,
                        'metadata' => array_merge($cardTransaction->metadata ?? [], [
                            'virtual_card_id' => $cardTransaction->virtual_card_id,
                            'payment_wallet_type' => $cardTransaction->payment_wallet_type,
                            'payment_wallet_currency' => $cardTransaction->payment_wallet_currency,
                            'exchange_rate' => $cardTransaction->exchange_rate,
                        ]),
                        'created_at' => $cardTransaction->created_at->toISOString(),
                        'updated_at' => $cardTransaction->updated_at->toISOString(),
                    ];
                });

            $allTransactions = $allTransactions->merge($cardTransactions);
        }

        // Sort by created_at descending and limit
        $sortedTransactions = $allTransactions->sortByDesc(function ($transaction) {
            return $transaction['created_at'];
        })->take($limit)->values();

        return $sortedTransactions->toArray();
    }

    /**
     * Determine wallet type from transaction
     */
    protected function determineWalletType(Transaction $transaction): string
    {
        // Check if it's crypto (category contains crypto OR currency is not fiat OR type is crypto)
        $fiatCurrencies = ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'GHS', 'ZAR', 'EGP'];
        $cryptoTypes = ['crypto_buy', 'crypto_sell', 'crypto_withdrawal'];
        $category = strtolower($transaction->category ?? '');
        
        if (in_array($transaction->type, $cryptoTypes) 
            || str_contains($category, 'crypto') 
            || !in_array($transaction->currency, $fiatCurrencies)) {
            return 'crypto';
        }

        return 'naira';
    }

    /**
     * Map virtual card transaction type to standard transaction type
     */
    public function mapCardTransactionType(string $cardType): string
    {
        return match($cardType) {
            'fund' => 'card_funding',
            'withdraw' => 'card_withdrawal',
            'payment' => 'card_payment',
            'refund' => 'card_refund',
            default => $cardType,
        };
    }
}
