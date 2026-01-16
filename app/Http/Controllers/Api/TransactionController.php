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

            return ResponseHelper::success($transactions, 'Transactions retrieved successfully.');
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

            return ResponseHelper::success($transaction, 'Transaction retrieved successfully.');
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
}
