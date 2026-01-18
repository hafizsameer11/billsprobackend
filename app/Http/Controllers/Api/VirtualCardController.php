<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\VirtualCard\CreateCardRequest;
use App\Http\Requests\VirtualCard\FundCardRequest;
use App\Http\Requests\VirtualCard\WithdrawCardRequest;
use App\Http\Requests\VirtualCard\UpdateCardLimitsRequest;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class VirtualCardController extends Controller
{
    protected VirtualCardService $virtualCardService;

    public function __construct(VirtualCardService $virtualCardService)
    {
        $this->virtualCardService = $virtualCardService;
    }

    /**
     * Get user's virtual cards
     */
    #[OA\Get(path: "/api/virtual-cards", summary: "Get user's virtual cards", description: "Get all active virtual cards for the authenticated user.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Response(response: 200, description: "Cards retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function index(Request $request): JsonResponse
    {
        try {
            $cards = $this->virtualCardService->getUserCards($request->user()->id);
            return ResponseHelper::success($cards, 'Virtual cards retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get virtual cards error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving virtual cards. Please try again.');
        }
    }

    /**
     * Create virtual card
     */
    #[OA\Post(path: "/api/virtual-cards", summary: "Create virtual card", description: "Create a new virtual card. Requires card creation fee of $3.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["card_name", "payment_wallet_type"], properties: [new OA\Property(property: "card_name", type: "string", example: "Qamardeen Abdulmalik"), new OA\Property(property: "card_color", type: "string", nullable: true, enum: ["green", "brown", "purple"], example: "green"), new OA\Property(property: "payment_wallet_type", type: "string", enum: ["naira_wallet", "crypto_wallet"], example: "naira_wallet"), new OA\Property(property: "payment_wallet_currency", type: "string", nullable: true, example: "NGN"), new OA\Property(property: "billing_address_street", type: "string", nullable: true), new OA\Property(property: "billing_address_city", type: "string", nullable: true), new OA\Property(property: "billing_address_state", type: "string", nullable: true), new OA\Property(property: "billing_address_country", type: "string", nullable: true), new OA\Property(property: "billing_address_postal_code", type: "string", nullable: true)]))]
    #[OA\Response(response: 200, description: "Card created successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Insufficient balance or invalid request")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function create(CreateCardRequest $request): JsonResponse
    {
        try {
            $result = $this->virtualCardService->createCard($request->user()->id, $request->validated());

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card creation failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Virtual card created successfully.');
        } catch (\Exception $e) {
            Log::error('Create virtual card error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while creating virtual card. Please try again.');
        }
    }

    /**
     * Get card details
     */
    #[OA\Get(path: "/api/virtual-cards/{id}", summary: "Get card details", description: "Get detailed information about a specific virtual card including card number, CVV, and expiry.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Card details retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Card not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (!$card) {
                return ResponseHelper::notFound('Virtual card not found');
            }

            return ResponseHelper::success($card, 'Card details retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get card details error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving card details. Please try again.');
        }
    }

    /**
     * Fund virtual card
     */
    #[OA\Post(path: "/api/virtual-cards/{id}/fund", summary: "Fund virtual card", description: "Add funds to virtual card from Naira or Crypto wallet.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["amount", "payment_wallet_type"], properties: [new OA\Property(property: "amount", type: "number", format: "float", example: 10.00, description: "Amount in USD"), new OA\Property(property: "payment_wallet_type", type: "string", enum: ["naira_wallet", "crypto_wallet"], example: "naira_wallet"), new OA\Property(property: "payment_wallet_currency", type: "string", nullable: true, example: "NGN")]))]
    #[OA\Response(response: 200, description: "Card funded successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Insufficient balance")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function fund(FundCardRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->fundCard($request->user()->id, $id, $request->validated());

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card funding failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card funded successfully.');
        } catch (\Exception $e) {
            Log::error('Fund card error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while funding card. Please try again.');
        }
    }

    /**
     * Withdraw from virtual card
     */
    #[OA\Post(path: "/api/virtual-cards/{id}/withdraw", summary: "Withdraw from virtual card", description: "Withdraw funds from virtual card to Naira wallet.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["amount"], properties: [new OA\Property(property: "amount", type: "number", format: "float", example: 10.00, description: "Amount in USD")]))]
    #[OA\Response(response: 200, description: "Withdrawal successful", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Insufficient card balance")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function withdraw(WithdrawCardRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->withdrawFromCard($request->user()->id, $id, $request->validated());

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Withdrawal failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Withdrawal successful.');
        } catch (\Exception $e) {
            Log::error('Withdraw from card error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while withdrawing from card. Please try again.');
        }
    }

    /**
     * Get card transactions
     */
    #[OA\Get(path: "/api/virtual-cards/{id}/transactions", summary: "Get card transactions", description: "Get transaction history for a specific virtual card.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Parameter(name: "limit", in: "query", required: false, description: "Number of records", schema: new OA\Schema(type: "integer", example: 50))]
    #[OA\Response(response: 200, description: "Transactions retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function transactions(Request $request, int $id): JsonResponse
    {
        try {
            $limit = (int) $request->query('limit', 50);
            $transactions = $this->virtualCardService->getCardTransactions($request->user()->id, $id, $limit);
            return ResponseHelper::success($transactions, 'Card transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get card transactions error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving card transactions. Please try again.');
        }
    }

    /**
     * Get card billing address
     */
    #[OA\Get(path: "/api/virtual-cards/{id}/billing-address", summary: "Get card billing address", description: "Get billing address for a virtual card.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Billing address retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Card not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getBillingAddress(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (!$card) {
                return ResponseHelper::notFound('Virtual card not found');
            }

            $billingAddress = [
                'street' => $card->billing_address_street,
                'city' => $card->billing_address_city,
                'state' => $card->billing_address_state,
                'country' => $card->billing_address_country,
                'postal_code' => $card->billing_address_postal_code,
            ];

            return ResponseHelper::success($billingAddress, 'Billing address retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get billing address error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving billing address. Please try again.');
        }
    }

    /**
     * Update card billing address
     */
    #[OA\Put(path: "/api/virtual-cards/{id}/billing-address", summary: "Update card billing address", description: "Update billing address for a virtual card.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: "billing_address_street", type: "string", nullable: true), new OA\Property(property: "billing_address_city", type: "string", nullable: true), new OA\Property(property: "billing_address_state", type: "string", nullable: true), new OA\Property(property: "billing_address_country", type: "string", nullable: true), new OA\Property(property: "billing_address_postal_code", type: "string", nullable: true)]))]
    #[OA\Response(response: 200, description: "Billing address updated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Card not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function updateBillingAddress(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (!$card) {
                return ResponseHelper::notFound('Virtual card not found');
            }

            $data = $request->only([
                'billing_address_street',
                'billing_address_city',
                'billing_address_state',
                'billing_address_country',
                'billing_address_postal_code',
            ]);

            $card->update($data);

            return ResponseHelper::success($card->fresh(), 'Billing address updated successfully.');
        } catch (\Exception $e) {
            Log::error('Update billing address error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while updating billing address. Please try again.');
        }
    }

    /**
     * Get card limits
     */
    #[OA\Get(path: "/api/virtual-cards/{id}/limits", summary: "Get card limits", description: "Get spending and transaction limits for a virtual card.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Card limits retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Card not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getLimits(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (!$card) {
                return ResponseHelper::notFound('Virtual card not found');
            }

            $limits = [
                'daily' => [
                    'spending_limit' => $card->daily_spending_limit,
                    'transaction_limit' => $card->daily_transaction_limit,
                ],
                'monthly' => [
                    'spending_limit' => $card->monthly_spending_limit,
                    'transaction_limit' => $card->monthly_transaction_limit,
                ],
            ];

            return ResponseHelper::success($limits, 'Card limits retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get card limits error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while retrieving card limits. Please try again.');
        }
    }

    /**
     * Update card limits
     */
    #[OA\Put(path: "/api/virtual-cards/{id}/limits", summary: "Update card limits", description: "Update spending and transaction limits for a virtual card.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: "daily_spending_limit", type: "number", nullable: true), new OA\Property(property: "monthly_spending_limit", type: "number", nullable: true), new OA\Property(property: "daily_transaction_limit", type: "integer", nullable: true), new OA\Property(property: "monthly_transaction_limit", type: "integer", nullable: true)]))]
    #[OA\Response(response: 200, description: "Card limits updated successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Card not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function updateLimits(UpdateCardLimitsRequest $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->updateCardLimits($request->user()->id, $id, $request->validated());
            return ResponseHelper::success($card, 'Card limits updated successfully.');
        } catch (\Exception $e) {
            Log::error('Update card limits error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while updating card limits. Please try again.');
        }
    }

    /**
     * Freeze card
     */
    #[OA\Post(path: "/api/virtual-cards/{id}/freeze", summary: "Freeze virtual card", description: "Freeze a virtual card to make it inactive. Requires support to unfreeze.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Card frozen successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Card frozen successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Card not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function freeze(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->toggleFreeze($request->user()->id, $id, true);
            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card frozen successfully.');
        } catch (\Exception $e) {
            Log::error('Freeze card error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while freezing card. Please try again.');
        }
    }

    /**
     * Unfreeze card
     */
    #[OA\Post(path: "/api/virtual-cards/{id}/unfreeze", summary: "Unfreeze virtual card", description: "Unfreeze a virtual card to make it active again.", security: [["sanctum" => []]], tags: ["Virtual Cards"])]
    #[OA\Parameter(name: "id", in: "path", required: true, description: "Card ID", schema: new OA\Schema(type: "integer", example: 1))]
    #[OA\Response(response: 200, description: "Card unfrozen successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Card unfrozen successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 404, description: "Card not found")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function unfreeze(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->toggleFreeze($request->user()->id, $id, false);
            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card unfrozen successfully.');
        } catch (\Exception $e) {
            Log::error('Unfreeze card error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::serverError('An error occurred while unfreezing card. Please try again.');
        }
    }
}
