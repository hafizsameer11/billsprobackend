<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\VirtualCard\CreateCardRequest;
use App\Http\Requests\VirtualCard\FundCardRequest;
use App\Http\Requests\VirtualCard\UpdateCardLimitsRequest;
use App\Http\Requests\VirtualCard\WithdrawCardRequest;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Virtual Cards',
    description: 'Virtual Mastercard via reseller API (`/api/mastercard/*`). **Create** debits `naira_wallet` or `crypto_wallet` using `VIRTUAL_CARD_*` fees, then calls the provider with `firstname`, `lastname`, `email`. **Fund / block / unblock / 3DS / wallet OTP** use provider `cardid` + `email`. Configure `MASTERCARD_API_*` env keys. Responses may include `provider_payload` and `fee_charged` on create.'
)]
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
    #[OA\Get(path: '/api/virtual-cards', summary: 'List virtual cards', description: 'Returns cached virtual Mastercards for the user and syncs with the provider when possible.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Response(response: 200, description: 'Cards retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))]))]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(Request $request): JsonResponse
    {
        try {
            $cards = $this->virtualCardService->getUserCards($request->user()->id);

            return ResponseHelper::success($cards, 'Virtual cards retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get virtual cards error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving virtual cards. Please try again.');
        }
    }

    /**
     * Create virtual card
     */
    #[OA\Post(
        path: '/api/virtual-cards',
        summary: 'Create virtual Mastercard',
        description: 'Issues a virtual Mastercard. **Wallet fee (required):** `payment_wallet_type` = `naira_wallet` or `crypto_wallet`. '
            .'Naira fee (NGN) = `VIRTUAL_CARD_CREATION_FEE_USD` × `VIRTUAL_CARD_USD_TO_NGN_RATE` + `VIRTUAL_CARD_CREATION_PROCESSING_FEE_NGN`. '
            .'Crypto fee (USD equivalent) matches the same total. Balance is checked **before** calling the provider; fee is debited **after** successful issue. '
            .'**Provider body:** `firstname`, `lastname`, `email` (defaults from the authenticated user; optional `email` / `useremail` override). KYC fields are optional for local/billing only.',
        security: [['sanctum' => []]],
        tags: ['Virtual Cards'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['payment_wallet_type'],
            properties: [
                new OA\Property(property: 'firstname', type: 'string', nullable: true, example: 'John'),
                new OA\Property(property: 'lastname', type: 'string', nullable: true, example: 'Doe'),
                new OA\Property(property: 'dob', description: 'YYYY-MM-DD', type: 'string', format: 'date', nullable: true, example: '1979-12-17'),
                new OA\Property(property: 'address1', type: 'string', nullable: true, example: '128 city road'),
                new OA\Property(property: 'postalcode', type: 'string', nullable: true, example: 'ec1v2nx'),
                new OA\Property(property: 'city', type: 'string', nullable: true, example: 'london'),
                new OA\Property(property: 'country', description: 'ISO-3166 alpha-2', type: 'string', nullable: true, example: 'GB'),
                new OA\Property(property: 'state', type: 'string', nullable: true, example: 'london'),
                new OA\Property(property: 'countrycode', description: 'Phone country code without +', type: 'string', nullable: true, example: '44'),
                new OA\Property(property: 'phone', type: 'string', nullable: true, example: '911266016115'),
                new OA\Property(property: 'payment_wallet_type', description: 'Where to debit the card creation fee', type: 'string', enum: ['naira_wallet', 'crypto_wallet'], example: 'naira_wallet'),
                new OA\Property(property: 'payment_wallet_currency', description: 'Fiat wallet currency when using naira_wallet (default NGN)', type: 'string', nullable: true, example: 'NGN'),
                new OA\Property(property: 'email', description: 'Overrides email sent to provider', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'useremail', description: 'Alias of email for provider', type: 'string', format: 'email', nullable: true, example: 'merchantuser@billspro.hmstech.org'),
                new OA\Property(property: 'card_name', type: 'string', nullable: true, example: 'John Doe Merchant Card'),
                new OA\Property(property: 'card_color', type: 'string', nullable: true, enum: ['green', 'brown', 'purple'], example: 'green'),
                new OA\Property(property: 'billing_address_street', type: 'string', nullable: true),
                new OA\Property(property: 'billing_address_city', type: 'string', nullable: true),
                new OA\Property(property: 'billing_address_state', type: 'string', nullable: true),
                new OA\Property(property: 'billing_address_country', type: 'string', nullable: true),
                new OA\Property(property: 'billing_address_postal_code', type: 'string', nullable: true),
            ],
            example: [
                'firstname' => 'John',
                'lastname' => 'Doe',
                'dob' => '1979-12-17',
                'address1' => '128 city road',
                'postalcode' => 'ec1v2nx',
                'city' => 'london',
                'country' => 'GB',
                'state' => 'london',
                'countrycode' => '44',
                'phone' => '911266016115',
                'payment_wallet_type' => 'naira_wallet',
                'payment_wallet_currency' => 'NGN',
                'useremail' => 'merchantuser@billspro.hmstech.org',
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Card issued; `data` includes `card`, `provider_response`, `transaction`, and `fee_charged` (amount/currency/payment_wallet_type).',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Card Issued Success'),
                new OA\Property(property: 'data', type: 'object'),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Insufficient wallet balance for creation fee, or provider rejected request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error or provider card id could not be resolved')]
    public function create(CreateCardRequest $request): JsonResponse
    {
        try {
            $result = $this->virtualCardService->createCard($request->user()->id, $request->validated());

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card creation failed', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Virtual card created successfully.');
        } catch (\Exception $e) {
            Log::error('Create virtual card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while creating virtual card. Please try again.');
        }
    }

    /**
     * Get card details
     */
    #[OA\Get(path: '/api/virtual-cards/{id}', summary: 'Get card details', description: 'Fetches virtual Mastercard details; refreshes from provider when possible. May include masked PAN/CVV in local cache.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: 'Card details retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Card not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (! $card) {
                return ResponseHelper::notFound('Virtual card not found');
            }

            return ResponseHelper::success($card, 'Card details retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get card details error: '.$e->getMessage(), [
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
    #[OA\Post(path: '/api/virtual-cards/{id}/fund', summary: 'Fund virtual card', description: 'Calls provider `fundcard` with `cardid`, `email`, `amount` (funding wallet / fees per provider). Optional `email` or `useremail` overrides the authenticated user email.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Local virtual card id', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['amount'],
            properties: [
                new OA\Property(property: 'amount', description: 'Fund amount (provider rules apply)', type: 'number', format: 'float', example: 50.0),
                new OA\Property(property: 'email', description: 'Email sent to provider', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'useremail', description: 'Alias of email for provider', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'payment_wallet_type', description: 'Optional label for client tracking', type: 'string', nullable: true, enum: ['naira_wallet', 'crypto_wallet', 'provider_balance']),
                new OA\Property(property: 'payment_wallet_currency', type: 'string', nullable: true, example: 'USD'),
            ],
            example: [
                'amount' => 50.0,
                'useremail' => 'user@billspro.hmstech.org',
            ],
        ),
    )]
    #[OA\Response(response: 200, description: 'Card funded successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Provider error, insufficient merchant balance, or misconfigured fund path')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function fund(FundCardRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->fundCard($request->user()->id, $id, $request->validated());

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card funding failed', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card funded successfully.');
        } catch (\Exception $e) {
            Log::error('Fund card error: '.$e->getMessage(), [
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
    #[OA\Post(path: '/api/virtual-cards/{id}/withdraw', summary: 'Withdraw from virtual card', description: 'Legacy endpoint. The current provider flow does not support direct withdraw through this API.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount'], properties: [new OA\Property(property: 'amount', type: 'number', format: 'float', example: 10.00, description: 'Amount in USD')]))]
    #[OA\Response(response: 200, description: 'Backward-compatibility response shape', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Operation not supported or invalid request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function withdraw(WithdrawCardRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->withdrawFromCard($request->user()->id, $id, $request->validated());

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Withdrawal failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Withdrawal successful.');
        } catch (\Exception $e) {
            Log::error('Withdraw from card error: '.$e->getMessage(), [
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
    #[OA\Get(path: '/api/virtual-cards/{id}/transactions', summary: 'Get card transactions', description: 'Merges provider card transactions (when available) with local `virtual_card_transactions` cache.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of records', schema: new OA\Schema(type: 'integer', example: 50))]
    #[OA\Response(response: 200, description: 'Transactions retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))]))]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function transactions(Request $request, int $id): JsonResponse
    {
        try {
            $limit = (int) $request->query('limit', 50);
            $transactions = $this->virtualCardService->getCardTransactions($request->user()->id, $id, $limit);

            return ResponseHelper::success($transactions, 'Card transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get card transactions error: '.$e->getMessage(), [
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
    #[OA\Get(path: '/api/virtual-cards/{id}/billing-address', summary: 'Get card billing address', description: 'Returns locally stored billing fields on the virtual card record (not necessarily synced from provider).', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: 'Billing address retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Card not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getBillingAddress(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (! $card) {
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
            Log::error('Get billing address error: '.$e->getMessage(), [
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
    #[OA\Put(path: '/api/virtual-cards/{id}/billing-address', summary: 'Update card billing address', description: 'Updates local billing fields only; does not call the external card provider.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'billing_address_street', type: 'string', nullable: true), new OA\Property(property: 'billing_address_city', type: 'string', nullable: true), new OA\Property(property: 'billing_address_state', type: 'string', nullable: true), new OA\Property(property: 'billing_address_country', type: 'string', nullable: true), new OA\Property(property: 'billing_address_postal_code', type: 'string', nullable: true)]))]
    #[OA\Response(response: 200, description: 'Billing address updated successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Card not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function updateBillingAddress(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (! $card) {
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
            Log::error('Update billing address error: '.$e->getMessage(), [
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
    #[OA\Get(path: '/api/virtual-cards/{id}/limits', summary: 'Get card limits', description: 'App-level spending/transaction limits stored on the local card row.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: 'Card limits retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Card not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getLimits(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->getCard($request->user()->id, $id);

            if (! $card) {
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
            Log::error('Get card limits error: '.$e->getMessage(), [
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
    #[OA\Put(path: '/api/virtual-cards/{id}/limits', summary: 'Update card limits', description: 'Updates local limit fields only; provider-side limits may differ.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'daily_spending_limit', type: 'number', nullable: true), new OA\Property(property: 'monthly_spending_limit', type: 'number', nullable: true), new OA\Property(property: 'daily_transaction_limit', type: 'integer', nullable: true), new OA\Property(property: 'monthly_transaction_limit', type: 'integer', nullable: true)]))]
    #[OA\Response(response: 200, description: 'Card limits updated successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Card not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function updateLimits(UpdateCardLimitsRequest $request, int $id): JsonResponse
    {
        try {
            $card = $this->virtualCardService->updateCardLimits($request->user()->id, $id, $request->validated());

            return ResponseHelper::success($card, 'Card limits updated successfully.');
        } catch (\Exception $e) {
            Log::error('Update card limits error: '.$e->getMessage(), [
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
    #[OA\Post(path: '/api/virtual-cards/{id}/freeze', summary: 'Block (freeze) card', description: 'Calls provider **block digital** (`blockdigital`); sets local `is_frozen`.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: 'Card frozen successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'message', type: 'string', example: 'Card frozen successfully'), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Card not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function freeze(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->toggleFreeze($request->user()->id, $id, true);
            if (! ($result['success'] ?? false)) {
                return ResponseHelper::error($result['message'] ?? 'Unable to freeze card', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card frozen successfully.');
        } catch (\Exception $e) {
            Log::error('Freeze card error: '.$e->getMessage(), [
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
    #[OA\Post(path: '/api/virtual-cards/{id}/unfreeze', summary: 'Unblock (unfreeze) card', description: 'Calls provider **unblock digital** (`unblockdigital`); clears local `is_frozen`.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: 'Card unfrozen successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'message', type: 'string', example: 'Card unfrozen successfully'), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Card not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function unfreeze(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->toggleFreeze($request->user()->id, $id, false);
            if (! ($result['success'] ?? false)) {
                return ResponseHelper::error($result['message'] ?? 'Unable to unfreeze card', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card unfrozen successfully.');
        } catch (\Exception $e) {
            Log::error('Unfreeze card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while unfreezing card. Please try again.');
        }
    }

    /**
     * Terminate card
     */
    #[OA\Post(path: '/api/virtual-cards/{id}/terminate', summary: 'Terminate virtual card', description: 'Calls provider **terminate digital** (`terminatedigital`); marks local card inactive.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: 'Card terminated successfully')]
    #[OA\Response(response: 400, description: 'Provider error or missing provider_card_id')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function terminate(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->terminateCard($request->user()->id, $id);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card termination failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card terminated successfully.');
        } catch (\Exception $e) {
            Log::error('Terminate card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while terminating card. Please try again.');
        }
    }

    /**
     * Check 3DS status
     */
    #[OA\Get(path: '/api/virtual-cards/{id}/check-3ds', summary: 'Check card 3DS status', description: 'Check 3DS status for the virtual Mastercard.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: '3DS status fetched successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'message', type: 'string', example: '3DS status fetched successfully'), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Provider rejected request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function check3ds(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->check3ds($request->user()->id, $id);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Unable to check 3DS status', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? '3DS status fetched successfully.');
        } catch (\Exception $e) {
            Log::error('Check 3DS error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while checking 3DS status. Please try again.');
        }
    }

    /**
     * Get wallet OTP
     */
    #[OA\Get(path: '/api/virtual-cards/{id}/check-wallet', summary: 'Get wallet OTP', description: 'Fetch latest wallet OTP for the virtual Mastercard.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(response: 200, description: 'Wallet OTP fetched successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'message', type: 'string', example: 'Wallet OTP fetched successfully'), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Provider rejected request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function checkWallet(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->virtualCardService->checkWalletOtp($request->user()->id, $id);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Unable to fetch wallet OTP', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Wallet OTP fetched successfully.');
        } catch (\Exception $e) {
            Log::error('Check wallet OTP error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while fetching wallet OTP. Please try again.');
        }
    }

    /**
     * Approve 3DS
     */
    #[OA\Post(path: '/api/virtual-cards/{id}/approve-3ds', summary: 'Approve 3DS challenge', description: 'Approve a pending 3DS challenge for the virtual Mastercard.', security: [['sanctum' => []]], tags: ['Virtual Cards'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Card ID', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['event_id'], properties: [new OA\Property(property: 'event_id', type: 'string', example: '3ds-3f41728f-cfd1-4cca-8404-bc8f5e9d71d6')]))]
    #[OA\Response(response: 200, description: '3DS approved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'message', type: 'string', example: '3DS approved successfully'), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Provider rejected request or validation error')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function approve3ds(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|string|max:255',
        ]);

        try {
            $result = $this->virtualCardService->approve3ds($request->user()->id, $id, (string) $request->input('event_id'));
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Unable to approve 3DS', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? '3DS approved successfully.');
        } catch (\Exception $e) {
            Log::error('Approve 3DS error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while approving 3DS. Please try again.');
        }
    }
}
