<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\AddBankAccountRequest;
use App\Http\Requests\Withdrawal\UpdateBankAccountRequest;
use App\Http\Requests\Withdrawal\WithdrawRequest;
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class WithdrawalController extends Controller
{
    protected WithdrawalService $withdrawalService;

    public function __construct(WithdrawalService $withdrawalService)
    {
        $this->withdrawalService = $withdrawalService;
    }

    /**
     * Get all bank accounts for the authenticated user
     */
    #[OA\Get(path: "/api/withdrawal/bank-accounts", summary: "Get user bank accounts", description: "Get all active bank accounts for the authenticated user.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\Response(response: 200, description: "Bank accounts retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Bank accounts retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getBankAccounts(Request $request): JsonResponse
    {
        try {
            $bankAccounts = $this->withdrawalService->getUserBankAccounts($request->user()->id);

            return ResponseHelper::success($bankAccounts, 'Bank accounts retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get bank accounts error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving bank accounts. Please try again.');
        }
    }

    /**
     * Add a new bank account
     */
    #[OA\Post(path: "/api/withdrawal/bank-accounts", summary: "Add bank account", description: "Add a new bank account for the authenticated user.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["bank_name", "account_number", "account_name"], properties: [new OA\Property(property: "bank_name", type: "string", example: "Access Bank"), new OA\Property(property: "account_number", type: "string", example: "1234567890"), new OA\Property(property: "account_name", type: "string", example: "John Doe"), new OA\Property(property: "currency", type: "string", example: "NGN"), new OA\Property(property: "country_code", type: "string", example: "NG")]))]
    #[OA\Response(response: 201, description: "Bank account added successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Bank account added successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Bank account already exists")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function addBankAccount(AddBankAccountRequest $request): JsonResponse
    {
        try {
            $bankAccount = $this->withdrawalService->addBankAccount(
                $request->user()->id,
                $request->validated()
            );

            return ResponseHelper::success($bankAccount, 'Bank account added successfully.', 201);
        } catch (\Exception $e) {
            Log::error('Add bank account error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e->getMessage();
            if (str_contains($message, 'already exists')) {
                return ResponseHelper::error($message, 400);
            }

            return ResponseHelper::serverError('An error occurred while adding bank account. Please try again.');
        }
    }

    /**
     * Update a bank account
     */
    #[OA\Put(path: "/api/withdrawal/bank-accounts/{id}", summary: "Update bank account", description: "Update an existing bank account for the authenticated user.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Bank account ID", schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: "bank_name", type: "string", example: "Access Bank"), new OA\Property(property: "account_number", type: "string", example: "1234567890"), new OA\Property(property: "account_name", type: "string", example: "John Doe")]))]
    #[OA\Response(response: 200, description: "Bank account updated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Bank account updated successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Bank account not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function updateBankAccount(UpdateBankAccountRequest $request, int $id): JsonResponse
    {
        try {
            $bankAccount = $this->withdrawalService->updateBankAccount(
                $request->user()->id,
                $id,
                $request->validated()
            );

            return ResponseHelper::success($bankAccount, 'Bank account updated successfully.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ResponseHelper::notFound('Bank account not found.');
        } catch (\Exception $e) {
            Log::error('Update bank account error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'bank_account_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e->getMessage();
            if (str_contains($message, 'already exists')) {
                return ResponseHelper::error($message, 400);
            }

            return ResponseHelper::serverError('An error occurred while updating bank account. Please try again.');
        }
    }

    /**
     * Delete (deactivate) a bank account
     */
    #[OA\Delete(path: "/api/withdrawal/bank-accounts/{id}", summary: "Delete bank account", description: "Delete (deactivate) a bank account for the authenticated user.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Bank account ID", schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Bank account deleted successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Bank account deleted successfully")]))]
    #[OA\Response(response: 404, description: "Bank account not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function deleteBankAccount(Request $request, int $id): JsonResponse
    {
        try {
            $this->withdrawalService->deleteBankAccount($request->user()->id, $id);

            return ResponseHelper::success(null, 'Bank account deleted successfully.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ResponseHelper::notFound('Bank account not found.');
        } catch (\Exception $e) {
            Log::error('Delete bank account error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'bank_account_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while deleting bank account. Please try again.');
        }
    }

    /**
     * Set a bank account as default
     */
    #[OA\Post(path: "/api/withdrawal/bank-accounts/{id}/set-default", summary: "Set default bank account", description: "Set a bank account as the default account for withdrawals.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Bank account ID", schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Default bank account set successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Default bank account set successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Bank account not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function setDefaultBankAccount(Request $request, int $id): JsonResponse
    {
        try {
            $bankAccount = $this->withdrawalService->setDefaultBankAccount($request->user()->id, $id);

            return ResponseHelper::success($bankAccount, 'Default bank account set successfully.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ResponseHelper::notFound('Bank account not found.');
        } catch (\Exception $e) {
            Log::error('Set default bank account error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'bank_account_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while setting default bank account. Please try again.');
        }
    }

    /**
     * Get withdrawal fee
     */
    #[OA\Get(path: "/api/withdrawal/fee", summary: "Get withdrawal fee", description: "Get the withdrawal fee amount.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\Response(response: 200, description: "Withdrawal fee retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Withdrawal fee retrieved successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getWithdrawalFee(Request $request): JsonResponse
    {
        try {
            $fee = $this->withdrawalService->getWithdrawalFee();

            return ResponseHelper::success(['fee' => $fee], 'Withdrawal fee retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get withdrawal fee error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving withdrawal fee. Please try again.');
        }
    }

    /**
     * Process withdrawal
     */
    #[OA\Post(path: "/api/withdrawal", summary: "Process withdrawal", description: "Process a withdrawal to a bank account. Requires PIN verification.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["bank_account_id", "amount", "pin"], properties: [new OA\Property(property: "bank_account_id", type: "integer", example: 1), new OA\Property(property: "amount", type: "number", example: 200000), new OA\Property(property: "pin", type: "string", example: "1234")]))]
    #[OA\Response(response: 200, description: "Withdrawal processed successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Withdrawal processed successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Invalid PIN or insufficient balance")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        try {
            $result = $this->withdrawalService->processWithdrawal(
                $request->user()->id,
                $request->bank_account_id,
                (float) $request->amount,
                $request->pin
            );

            return ResponseHelper::success($result, 'You have successfully completed a withdrawal of N' . number_format($result['amount'], 2) . '.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ResponseHelper::notFound('Bank account not found.');
        } catch (\Exception $e) {
            Log::error('Withdrawal error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'bank_account_id' => $request->bank_account_id,
                'amount' => $request->amount,
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e->getMessage();
            if (str_contains($message, 'Invalid PIN') || 
                str_contains($message, 'Insufficient balance') || 
                str_contains($message, 'Invalid withdrawal amount')) {
                return ResponseHelper::error($message, 400);
            }

            return ResponseHelper::serverError('An error occurred while processing withdrawal. Please try again.');
        }
    }

    /**
     * Get transaction history
     */
    #[OA\Get(path: "/api/withdrawal/transactions", summary: "Get transaction history", description: "Get withdrawal transaction history for the authenticated user.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\Parameter(name: "type", in: "query", required: false, description: "Transaction type filter", schema: new OA\Schema(type: "string", example: "withdrawal"))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records to return", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Transaction history retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Transaction history retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getTransactionHistory(Request $request): JsonResponse
    {
        try {
            $type = $request->query('type');
            $limit = (int) $request->query('limit', 50);

            $transactions = $this->withdrawalService->getTransactionHistory(
                $request->user()->id,
                $type,
                $limit
            );

            return ResponseHelper::success($transactions, 'Transaction history retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get transaction history error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving transaction history. Please try again.');
        }
    }

    /**
     * Get transaction details
     */
    #[OA\Get(path: "/api/withdrawal/transactions/{transactionId}", summary: "Get transaction details", description: "Get details of a specific transaction.", security: [["sanctum" => []]], tags: ["Withdrawal"])]
    #[OA\Parameter(name: "transactionId", in: "path", required: true, description: "Transaction ID", schema: new OA\Schema(type: "string"))]
    #[OA\Response(response: 200, description: "Transaction details retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Transaction details retrieved successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Transaction not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getTransaction(Request $request, string $transactionId): JsonResponse
    {
        try {
            $transaction = $this->withdrawalService->getTransaction(
                $request->user()->id,
                $transactionId
            );

            if (!$transaction) {
                return ResponseHelper::notFound('Transaction not found.');
            }

            return ResponseHelper::success($transaction, 'Transaction details retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get transaction error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'transaction_id' => $transactionId,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving transaction details. Please try again.');
        }
    }
}
