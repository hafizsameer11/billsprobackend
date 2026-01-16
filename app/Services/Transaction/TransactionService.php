<?php

namespace App\Services\Transaction;

use App\Models\Transaction;
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
     * Get transaction by ID
     */
    public function getTransaction(int $userId, string $transactionId): ?Transaction
    {
        return Transaction::where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->first();
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
}
