<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deposit\InitiateDepositRequest;
use App\Http\Requests\Deposit\ConfirmDepositRequest;
use App\Services\Deposit\DepositService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class DepositController extends Controller
{
    protected DepositService $depositService;

    public function __construct(DepositService $depositService)
    {
        $this->depositService = $depositService;
    }

    /**
     * Get deposit bank account details
     */
    #[OA\Get(path: "/api/deposit/bank-account", summary: "Get deposit bank account", description: "Get bank account details for making deposits.", security: [["sanctum" => []]], tags: ["Deposit"])]
    #[OA\Parameter(name: "currency", in: "query", required: false, description: "Currency code", schema: new OA\Schema(type: "string", example: "NGN"))]
    #[OA\Response(response: 200, description: "Bank account retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getBankAccount(Request $request): JsonResponse
    {
        try {
            $currency = $request->query('currency', 'NGN');
            $countryCode = $request->query('country_code', 'NG');

            $bankAccount = $this->depositService->getDepositBankAccount($currency, $countryCode);

            if (!$bankAccount) {
                return ResponseHelper::error('No active bank account found for deposits', 404);
            }

            return ResponseHelper::success($bankAccount, 'Bank account retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get bank account error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving bank account. Please try again.');
        }
    }

    /**
     * Initiate deposit
     */
    #[OA\Post(path: "/api/deposit/initiate", summary: "Initiate deposit", description: "Initiate a deposit request and get bank account details for payment.", security: [["sanctum" => []]], tags: ["Deposit"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["amount"], properties: [new OA\Property(property: "amount", type: "number", format: "float", example: 10000.00, description: "Deposit amount (minimum 100)"), new OA\Property(property: "currency", type: "string", nullable: true, example: "NGN"), new OA\Property(property: "payment_method", type: "string", nullable: true, enum: ["bank_transfer", "instant_transfer"], example: "instant_transfer")]))]
    #[OA\Response(response: 200, description: "Deposit initiated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Deposit initiated successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Invalid request")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function initiate(InitiateDepositRequest $request): JsonResponse
    {
        try {
            $result = $this->depositService->initiateDeposit($request->user()->id, $request->validated());

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Deposit initiation failed', 400);
            }

            return ResponseHelper::success([
                'deposit' => $result['deposit'],
                'bank_account' => $result['bank_account'],
                'reference' => $result['reference'],
                'amount' => $result['deposit']->amount,
                'fee' => $result['deposit']->fee,
                'total_amount' => $result['deposit']->total_amount,
            ], $result['message'] ?? 'Deposit initiated successfully.');
        } catch (\Exception $e) {
            Log::error('Initiate deposit error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while initiating deposit. Please try again.');
        }
    }

    /**
     * Confirm deposit payment
     */
    #[OA\Post(path: "/api/deposit/confirm", summary: "Confirm deposit payment", description: "Confirm that payment has been made and credit the user's wallet.", security: [["sanctum" => []]], tags: ["Deposit"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["reference"], properties: [new OA\Property(property: "reference", type: "string", example: "DEP2026011612345678", description: "Deposit reference number")]))]
    #[OA\Response(response: 200, description: "Deposit confirmed successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Deposit confirmed and wallet credited successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Deposit not found or already processed")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function confirm(ConfirmDepositRequest $request): JsonResponse
    {
        try {
            $result = $this->depositService->confirmDeposit($request->user()->id, $request->reference);

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Deposit confirmation failed', 400);
            }

            return ResponseHelper::success([
                'deposit' => $result['deposit'],
                'transaction' => $result['transaction'],
            ], $result['message'] ?? 'Deposit confirmed and wallet credited successfully.');
        } catch (\Exception $e) {
            Log::error('Confirm deposit error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'reference' => $request->reference,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while confirming deposit. Please try again.');
        }
    }

    /**
     * Get user deposits
     */
    #[OA\Get(path: "/api/deposit/history", summary: "Get deposit history", description: "Get user's deposit history.", security: [["sanctum" => []]], tags: ["Deposit"])]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records to return", schema: new OA\Schema(type: "integer", example: 20))]
    #[OA\Response(response: 200, description: "Deposits retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function history(Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->query('limit', 20);
            $deposits = $this->depositService->getUserDeposits($request->user()->id, $limit);

            return ResponseHelper::success($deposits, 'Deposits retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get deposit history error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving deposit history. Please try again.');
        }
    }

    /**
     * Get deposit by reference
     */
    #[OA\Get(path: "/api/deposit/{reference}", summary: "Get deposit by reference", description: "Get deposit details by reference number.", security: [["sanctum" => []]], tags: ["Deposit"])]
    #[OA\Parameter(name: "reference", in: "path", required: true, description: "Deposit reference number", schema: new OA\Schema(type: "string", example: "DEP2026011612345678"))]
    #[OA\Response(response: 200, description: "Deposit retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Deposit not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function show(Request $request, string $reference): JsonResponse
    {
        try {
            $deposit = $this->depositService->getDepositByReference($request->user()->id, $reference);

            if (!$deposit) {
                return ResponseHelper::notFound('Deposit not found');
            }

            return ResponseHelper::success($deposit, 'Deposit retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get deposit error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'reference' => $reference,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving deposit. Please try again.');
        }
    }
}
