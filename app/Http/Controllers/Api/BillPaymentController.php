<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\BillPayment\InitiateBillPaymentRequest;
use App\Http\Requests\BillPayment\ConfirmBillPaymentRequest;
use App\Http\Requests\BillPayment\ValidateMeterRequest;
use App\Http\Requests\BillPayment\ValidateAccountRequest;
use App\Http\Requests\BillPayment\CreateBeneficiaryRequest;
use App\Services\BillPayment\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class BillPaymentController extends Controller
{
    protected BillPaymentService $billPaymentService;

    public function __construct(BillPaymentService $billPaymentService)
    {
        $this->billPaymentService = $billPaymentService;
    }

    /**
     * Get all categories
     */
    #[OA\Get(path: "/api/bill-payment/categories", summary: "Get bill payment categories", description: "Get all available bill payment categories.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\Response(response: 200, description: "Categories retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getCategories(): JsonResponse
    {
        try {
            $categories = $this->billPaymentService->getCategories();
            return ResponseHelper::success($categories, 'Categories retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get categories error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ResponseHelper::serverError('An error occurred while retrieving categories. Please try again.');
        }
    }

    /**
     * Get providers by category
     */
    #[OA\Get(path: "/api/bill-payment/providers", summary: "Get providers by category", description: "Get providers for a specific category.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\Parameter(name: "categoryCode", in: "query", required: true, description: "Category code", schema: new OA\Schema(type: "string", example: "airtime"))]
    #[OA\Parameter(name: "countryCode", in: "query", required: false, description: "Country code", schema: new OA\Schema(type: "string", example: "NG"))]
    #[OA\Response(response: 200, description: "Providers retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 400, description: "Category code is required")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getProviders(Request $request): JsonResponse
    {
        try {
            $categoryCode = $request->query('categoryCode');
            if (!$categoryCode) {
                return ResponseHelper::error('Category code is required', 400);
            }

            $countryCode = $request->query('countryCode');
            $providers = $this->billPaymentService->getProvidersByCategory($categoryCode, $countryCode);
            return ResponseHelper::success($providers, 'Providers retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get providers error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ResponseHelper::serverError('An error occurred while retrieving providers. Please try again.');
        }
    }

    /**
     * Get plans by provider
     */
    #[OA\Get(path: "/api/bill-payment/plans", summary: "Get plans by provider", description: "Get available plans/bundles for a provider (Data and Cable TV only).", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\Parameter(name: "providerId", in: "query", required: true, description: "Provider ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Plans retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 400, description: "Provider ID is required")]
    #[OA\Response(response: 404, description: "Provider not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getPlans(Request $request): JsonResponse
    {
        try {
            $providerId = $request->query('providerId');
            if (!$providerId) {
                return ResponseHelper::error('Provider ID is required', 400);
            }

            $plans = $this->billPaymentService->getPlansByProvider((int) $providerId);
            return ResponseHelper::success($plans, 'Plans retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get plans error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ResponseHelper::serverError('An error occurred while retrieving plans. Please try again.');
        }
    }

    /**
     * Validate meter (Electricity)
     */
    #[OA\Post(path: "/api/bill-payment/validate-meter", summary: "Validate electricity meter", description: "Validate electricity meter number before payment.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["providerId", "meterNumber", "accountType"], properties: [new OA\Property(property: "providerId", type: "integer", example: 7), new OA\Property(property: "meterNumber", type: "string", example: "1234567890"), new OA\Property(property: "accountType", type: "string", enum: ["prepaid", "postpaid"], example: "prepaid")]))]
    #[OA\Response(response: 200, description: "Meter validated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Invalid meter number")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function validateMeter(ValidateMeterRequest $request): JsonResponse
    {
        try {
            $result = $this->billPaymentService->validateMeter(
                $request->providerId,
                $request->meterNumber,
                $request->accountType
            );

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Meter validation failed', 400);
            }

            return ResponseHelper::success($result['data'], 'Meter validated successfully.');
        } catch (\Exception $e) {
            Log::error('Validate meter error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ResponseHelper::serverError('An error occurred while validating meter. Please try again.');
        }
    }

    /**
     * Validate account (Betting)
     */
    #[OA\Post(path: "/api/bill-payment/validate-account", summary: "Validate betting account", description: "Validate betting account number before payment.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["providerId", "accountNumber"], properties: [new OA\Property(property: "providerId", type: "integer", example: 13), new OA\Property(property: "accountNumber", type: "string", example: "12345")]))]
    #[OA\Response(response: 200, description: "Account validated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Invalid account number")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function validateAccount(ValidateAccountRequest $request): JsonResponse
    {
        try {
            $result = $this->billPaymentService->validateAccount(
                $request->providerId,
                $request->accountNumber
            );

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Account validation failed', 400);
            }

            return ResponseHelper::success($result['data'], 'Account validated successfully.');
        } catch (\Exception $e) {
            Log::error('Validate account error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ResponseHelper::serverError('An error occurred while validating account. Please try again.');
        }
    }

    /**
     * Preview bill payment
     */
    #[OA\Post(path: "/api/bill-payment/preview", summary: "Preview bill payment", description: "Preview bill payment with fee calculation before confirming.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["categoryCode", "providerId"], properties: [new OA\Property(property: "categoryCode", type: "string", example: "airtime"), new OA\Property(property: "providerId", type: "integer", example: 1), new OA\Property(property: "amount", type: "number", nullable: true, example: 1000), new OA\Property(property: "planId", type: "integer", nullable: true, example: 1), new OA\Property(property: "currency", type: "string", nullable: true, example: "NGN")]))]
    #[OA\Response(response: 200, description: "Preview retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Invalid request")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function preview(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'categoryCode' => 'required|string',
                'providerId' => 'required|integer',
                'amount' => 'nullable|numeric|min:0.01',
                'planId' => 'nullable|integer',
                'currency' => 'nullable|string|max:10',
            ]);

            $result = $this->billPaymentService->previewBillPayment($request->user()->id, $data);

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Failed to preview payment', 400);
            }

            return ResponseHelper::success($result['data'], 'Preview retrieved successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::validationError($e->errors());
        } catch (\Exception $e) {
            Log::error('Preview bill payment error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while previewing payment. Please try again.');
        }
    }

    /**
     * Initiate bill payment
     */
    #[OA\Post(path: "/api/bill-payment/initiate", summary: "Initiate bill payment", description: "Create a pending transaction and return payment summary. Does NOT deduct balance yet.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["categoryCode", "providerId", "currency"], properties: [new OA\Property(property: "categoryCode", type: "string", example: "airtime"), new OA\Property(property: "providerId", type: "integer", example: 1), new OA\Property(property: "currency", type: "string", example: "NGN"), new OA\Property(property: "amount", type: "number", nullable: true, example: 1000), new OA\Property(property: "planId", type: "integer", nullable: true, example: 4), new OA\Property(property: "accountNumber", type: "string", nullable: true, example: "08012345678"), new OA\Property(property: "beneficiaryId", type: "integer", nullable: true), new OA\Property(property: "accountType", type: "string", nullable: true, enum: ["prepaid", "postpaid"])]))]
    #[OA\Response(response: 200, description: "Payment initiated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Invalid request")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function initiate(InitiateBillPaymentRequest $request): JsonResponse
    {
        try {
            $result = $this->billPaymentService->initiateBillPayment($request->user()->id, $request->validated());

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Payment initiation failed', 400);
            }

            return ResponseHelper::success($result['data'], 'Payment initiated successfully.');
        } catch (\Exception $e) {
            Log::error('Initiate payment error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while initiating payment. Please try again.');
        }
    }

    /**
     * Confirm bill payment
     */
    #[OA\Post(path: "/api/bill-payment/confirm", summary: "Confirm bill payment", description: "Complete the payment by confirming the pending transaction. Deducts balance and requires PIN.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["transactionId", "pin"], properties: [new OA\Property(property: "transactionId", type: "integer", example: 123), new OA\Property(property: "pin", type: "string", example: "1234")]))]
    #[OA\Response(response: 200, description: "Payment confirmed successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Invalid transaction or PIN")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function confirm(ConfirmBillPaymentRequest $request): JsonResponse
    {
        try {
            $result = $this->billPaymentService->confirmBillPayment(
                $request->user()->id,
                $request->transactionId,
                $request->pin
            );

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Payment confirmation failed', 400);
            }

            return ResponseHelper::success($result['data'], 'Payment confirmed successfully.');
        } catch (\Exception $e) {
            Log::error('Confirm payment error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while confirming payment. Please try again.');
        }
    }

    /**
     * Get beneficiaries
     */
    #[OA\Get(path: "/api/bill-payment/beneficiaries", summary: "Get beneficiaries", description: "Get user's saved beneficiaries.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\Parameter(name: "categoryCode", in: "query", required: false, description: "Filter by category code", schema: new OA\Schema(type: "string", example: "airtime"))]
    #[OA\Response(response: 200, description: "Beneficiaries retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getBeneficiaries(Request $request): JsonResponse
    {
        try {
            $categoryCode = $request->query('categoryCode');
            $beneficiaries = $this->billPaymentService->getBeneficiaries($request->user()->id, $categoryCode);
            return ResponseHelper::success($beneficiaries, 'Beneficiaries retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get beneficiaries error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving beneficiaries. Please try again.');
        }
    }

    /**
     * Create beneficiary
     */
    #[OA\Post(path: "/api/bill-payment/beneficiaries", summary: "Create beneficiary", description: "Save a beneficiary for future use.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["categoryCode", "providerId", "accountNumber"], properties: [new OA\Property(property: "categoryCode", type: "string", example: "airtime"), new OA\Property(property: "providerId", type: "integer", example: 1), new OA\Property(property: "name", type: "string", nullable: true, example: "My Phone"), new OA\Property(property: "accountNumber", type: "string", example: "08012345678"), new OA\Property(property: "accountType", type: "string", nullable: true, enum: ["prepaid", "postpaid"])]))]
    #[OA\Response(response: 200, description: "Beneficiary created successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Beneficiary already exists")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function createBeneficiary(CreateBeneficiaryRequest $request): JsonResponse
    {
        try {
            $beneficiary = $this->billPaymentService->createBeneficiary($request->user()->id, $request->validated());
            return ResponseHelper::success($beneficiary->load(['category', 'provider']), 'Beneficiary created successfully.');
        } catch (\Exception $e) {
            Log::error('Create beneficiary error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $message = str_contains($e->getMessage(), 'already exists') 
                ? 'Beneficiary already exists' 
                : 'An error occurred while creating beneficiary. Please try again.';
            return ResponseHelper::error($message, 400);
        }
    }

    /**
     * Update beneficiary
     */
    #[OA\Put(path: "/api/bill-payment/beneficiaries/{id}", summary: "Update beneficiary", description: "Update a beneficiary.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Beneficiary ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: "name", type: "string", nullable: true), new OA\Property(property: "accountNumber", type: "string", nullable: true), new OA\Property(property: "accountType", type: "string", nullable: true, enum: ["prepaid", "postpaid"])]))]
    #[OA\Response(response: 200, description: "Beneficiary updated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Beneficiary not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function updateBeneficiary(Request $request, int $id): JsonResponse
    {
        try {
            $data = $request->only(['name', 'accountNumber', 'accountType']);
            $beneficiary = $this->billPaymentService->updateBeneficiary($request->user()->id, $id, $data);
            return ResponseHelper::success($beneficiary->load(['category', 'provider']), 'Beneficiary updated successfully.');
        } catch (\Exception $e) {
            Log::error('Update beneficiary error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'beneficiary_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while updating beneficiary. Please try again.');
        }
    }

    /**
     * Delete beneficiary
     */
    #[OA\Delete(path: "/api/bill-payment/beneficiaries/{id}", summary: "Delete beneficiary", description: "Delete (soft delete) a beneficiary.", security: [["sanctum" => []]], tags: ["Bill Payment"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Beneficiary ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Beneficiary deleted successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Beneficiary deleted successfully")]))]
    #[OA\Response(response: 404, description: "Beneficiary not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function deleteBeneficiary(Request $request, int $id): JsonResponse
    {
        try {
            $this->billPaymentService->deleteBeneficiary($request->user()->id, $id);
            return ResponseHelper::success(null, 'Beneficiary deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Delete beneficiary error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'beneficiary_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while deleting beneficiary. Please try again.');
        }
    }
}
