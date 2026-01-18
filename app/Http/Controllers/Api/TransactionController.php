<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Transaction\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class TransactionController extends Controller
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Get transaction history
     */
    #[OA\Get(path: "/api/transactions", summary: "Get transaction history", description: "Get user's transaction history with optional filters.", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "type", in: "query", required: false, description: "Transaction type", schema: new OA\Schema(type: "string", enum: ["deposit", "withdrawal", "bill_payment", "transfer"]))]
    #[OA\Parameter(name: "status", in: "query", required: false, description: "Transaction status", schema: new OA\Schema(type: "string", enum: ["pending", "completed", "failed", "cancelled"]))]
    #[OA\Parameter(name: "category", in: "query", required: false, description: "Transaction category", schema: new OA\Schema(type: "string", example: "fiat_deposit"))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Transactions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'type' => $request->query('type'),
                'status' => $request->query('status'),
                'category' => $request->query('category'),
                'currency' => $request->query('currency'),
            ];

            $limit = (int) $request->query('limit', 50);
            $transactions = $this->transactionService->getUserTransactions($request->user()->id, $filters, $limit);

            $formattedTransactions = $transactions->map(function ($transaction) {
                return $this->formatTransaction($transaction);
            });

            return ResponseHelper::success($formattedTransactions, 'Transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get transactions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving transactions. Please try again.');
        }
    }

    /**
     * Get transaction by ID
     */
    #[OA\Get(path: "/api/transactions/{transactionId}", summary: "Get transaction details", description: "Get detailed information about a specific transaction.", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "transactionId", in: "path", required: true, description: "Transaction ID", schema: new OA\Schema(type: "string", example: "abc123def456"))]
    #[OA\Response(response: 200, description: "Transaction retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Transaction not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function show(Request $request, string $transactionId): JsonResponse
    {
        try {
            $transaction = $this->transactionService->getTransaction($request->user()->id, $transactionId);

            if (!$transaction) {
                return ResponseHelper::notFound('Transaction not found');
            }

            $formattedTransaction = $this->formatTransaction($transaction);

            return ResponseHelper::success($formattedTransaction, 'Transaction retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get transaction error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'transaction_id' => $transactionId,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving transaction. Please try again.');
        }
    }

    /**
     * Get fiat transactions
     */
    #[OA\Get(path: "/api/transactions/fiat", summary: "Get fiat transactions", description: "Get user's fiat currency transactions (NGN, USD, EUR, etc.) with optional filters.", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "type", in: "query", required: false, description: "Transaction type", schema: new OA\Schema(type: "string", enum: ["deposit", "withdrawal", "bill_payment", "transfer"]))]
    #[OA\Parameter(name: "status", in: "query", required: false, description: "Transaction status", schema: new OA\Schema(type: "string", enum: ["pending", "completed", "failed", "cancelled"]))]
    #[OA\Parameter(name: "category", in: "query", required: false, description: "Transaction category", schema: new OA\Schema(type: "string", example: "fiat_deposit"))]
    #[OA\Parameter(name: "currency", in: "query", required: false, description: "Currency code", schema: new OA\Schema(type: "string", example: "NGN"))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Fiat transactions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Fiat transactions retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getFiatTransactions(Request $request): JsonResponse
    {
        try {
            $filters = [
                'type' => $request->query('type'),
                'status' => $request->query('status'),
                'category' => $request->query('category'),
                'currency' => $request->query('currency'),
            ];

            $limit = (int) $request->query('limit', 50);
            $transactions = $this->transactionService->getUserFiatTransactions($request->user()->id, $filters, $limit);

            $formattedTransactions = $transactions->map(function ($transaction) {
                return $this->formatTransaction($transaction);
            });

            return ResponseHelper::success($formattedTransactions, 'Fiat transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get fiat transactions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving fiat transactions. Please try again.');
        }
    }

    /**
     * Get transaction statistics
     */
    #[OA\Get(path: "/api/transactions/stats", summary: "Get transaction statistics", description: "Get transaction statistics for the authenticated user.", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "period", in: "query", required: false, description: "Time period", schema: new OA\Schema(type: "string", enum: ["day", "week", "month"], example: "month"))]
    #[OA\Response(response: 200, description: "Statistics retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function stats(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', 'month');
            $stats = $this->transactionService->getTransactionStats($request->user()->id, $period);

            return ResponseHelper::success($stats, 'Transaction statistics retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get transaction stats error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving statistics. Please try again.');
        }
    }

    /**
     * Get all transactions (aggregated from all sources)
     */
    #[OA\Get(path: "/api/transactions/all", summary: "Get all transactions", description: "Get all transactions aggregated from all sources (Naira, Crypto, Virtual Card).", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "wallet_type", in: "query", required: false, description: "Wallet type filter", schema: new OA\Schema(type: "string", enum: ["naira", "crypto", "virtual_card"], example: "naira"))]
    #[OA\Parameter(name: "type", in: "query", required: false, description: "Transaction type", schema: new OA\Schema(type: "string", enum: ["deposit", "withdrawal", "bill_payment", "card_funding", "card_withdrawal", "transfer"]))]
    #[OA\Parameter(name: "status", in: "query", required: false, description: "Transaction status", schema: new OA\Schema(type: "string", enum: ["pending", "completed", "failed", "cancelled"]))]
    #[OA\Parameter(name: "start_date", in: "query", required: false, description: "Start date (YYYY-MM-DD)", schema: new OA\Schema(type: "string", example: "2025-01-01"))]
    #[OA\Parameter(name: "end_date", in: "query", required: false, description: "End date (YYYY-MM-DD)", schema: new OA\Schema(type: "string", example: "2025-01-31"))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "All transactions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "All transactions retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getAllTransactions(Request $request): JsonResponse
    {
        try {
            $filters = [
                'wallet_type' => $request->query('wallet_type'),
                'type' => $request->query('type'),
                'status' => $request->query('status'),
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
            ];

            $limit = (int) $request->query('limit', 50);
            $transactions = $this->transactionService->getAllTransactions($request->user()->id, $filters, $limit);

            return ResponseHelper::success($transactions, 'All transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get all transactions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving transactions. Please try again.');
        }
    }

    /**
     * Get bill payment transactions
     */
    #[OA\Get(path: "/api/transactions/bill-payments", summary: "Get bill payment transactions", description: "Get all bill payment transactions for the authenticated user.", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "status", in: "query", required: false, description: "Transaction status", schema: new OA\Schema(type: "string", enum: ["pending", "completed", "failed", "cancelled"]))]
    #[OA\Parameter(name: "category", in: "query", required: false, description: "Bill payment category", schema: new OA\Schema(type: "string", enum: ["airtime", "data", "electricity", "cable_tv", "internet"], example: "airtime"))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Bill payment transactions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Bill payment transactions retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getBillPaymentTransactions(Request $request): JsonResponse
    {
        try {
            $filters = [
                'type' => 'bill_payment',
                'status' => $request->query('status'),
                'category' => $request->query('category'),
            ];

            $limit = (int) $request->query('limit', 50);
            $transactions = $this->transactionService->getUserTransactions($request->user()->id, $filters, $limit);

            $formattedTransactions = $transactions->map(function ($transaction) {
                return $this->formatTransaction($transaction);
            });

            return ResponseHelper::success($formattedTransactions, 'Bill payment transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get bill payment transactions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving bill payment transactions. Please try again.');
        }
    }

    /**
     * Get naira withdrawal transactions
     */
    #[OA\Get(path: "/api/transactions/withdrawals", summary: "Get naira withdrawal transactions", description: "Get all naira withdrawal transactions for the authenticated user.", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "status", in: "query", required: false, description: "Transaction status", schema: new OA\Schema(type: "string", enum: ["pending", "completed", "failed", "cancelled"]))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Withdrawal transactions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Withdrawal transactions retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getWithdrawalTransactions(Request $request): JsonResponse
    {
        try {
            $filters = [
                'type' => 'withdrawal',
                'status' => $request->query('status'),
                'category' => 'fiat_withdrawal',
                'currency' => 'NGN',
            ];

            $limit = (int) $request->query('limit', 50);
            $transactions = $this->transactionService->getUserFiatTransactions($request->user()->id, $filters, $limit);

            $formattedTransactions = $transactions->map(function ($transaction) {
                return $this->formatTransaction($transaction);
            });

            return ResponseHelper::success($formattedTransactions, 'Withdrawal transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get withdrawal transactions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving withdrawal transactions. Please try again.');
        }
    }

    /**
     * Get naira deposit transactions
     */
    #[OA\Get(path: "/api/transactions/deposits", summary: "Get naira deposit transactions", description: "Get all naira deposit transactions for the authenticated user.", security: [["sanctum" => []]], tags: ["Transactions"])]
    #[OA\Parameter(name: "status", in: "query", required: false, description: "Transaction status", schema: new OA\Schema(type: "string", enum: ["pending", "completed", "failed", "cancelled"]))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Deposit transactions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Deposit transactions retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getDepositTransactions(Request $request): JsonResponse
    {
        try {
            $filters = [
                'type' => 'deposit',
                'status' => $request->query('status'),
                'category' => 'fiat_deposit',
                'currency' => 'NGN',
            ];

            $limit = (int) $request->query('limit', 50);
            $transactions = $this->transactionService->getUserFiatTransactions($request->user()->id, $filters, $limit);

            $formattedTransactions = $transactions->map(function ($transaction) {
                return $this->formatTransaction($transaction);
            });

            return ResponseHelper::success($formattedTransactions, 'Deposit transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get deposit transactions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving deposit transactions. Please try again.');
        }
    }

    /**
     * Format transaction to include token for prepaid electricity
     */
    protected function formatTransaction($transaction): array
    {
        $metadata = $transaction->metadata ?? [];
        $isPrepaidElectricity = $transaction->type === 'bill_payment' 
            && $transaction->category === 'electricity' 
            && isset($metadata['accountType']) 
            && $metadata['accountType'] === 'prepaid'
            && isset($metadata['rechargeToken']);

        $formatted = [
            'id' => $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            'type' => $transaction->type,
            'category' => $transaction->category,
            'status' => $transaction->status,
            'currency' => $transaction->currency,
            'amount' => (float) $transaction->amount,
            'fee' => (float) $transaction->fee,
            'total_amount' => (float) $transaction->total_amount,
            'reference' => $transaction->reference,
            'description' => $transaction->description,
            'bank_name' => $transaction->bank_name,
            'account_number' => $transaction->account_number,
            'account_name' => $transaction->account_name,
            'metadata' => $metadata,
            'created_at' => $transaction->created_at?->toISOString(),
            'updated_at' => $transaction->updated_at?->toISOString(),
            'completed_at' => $transaction->completed_at?->toISOString(),
        ];

        // Add token field at top level for prepaid electricity
        if ($isPrepaidElectricity) {
            $formatted['token'] = $metadata['rechargeToken'];
        }

        return $formatted;
    }
}
