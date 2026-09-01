<?php

namespace App\Http\Controllers\Api;

use App\Helpers\MoneyFormatHelper;
use App\Helpers\NotificationHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Concerns\VerifiesTransactionPin;
use App\Http\Controllers\Controller;
use App\Http\Requests\VirtualCard\CreateCardRequest;
use App\Http\Requests\VirtualCard\FundCardRequest;
use App\Http\Requests\VirtualCard\FundingEstimateRequest;
use App\Http\Requests\VirtualCard\WithdrawCardRequest;
use App\Models\VirtualCard;
use App\Services\Http\IdempotencyService;
use App\Services\VirtualCard\VisaVirtualCardService;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Visa Virtual Cards',
    description: 'Virtual Visa via Pagocards `POST /visacard/*`. Same reseller credentials as Mastercard. Fees use admin platform rates `virtual_card` / `visa_creation` and `visa_fund`.',
)]
class VisaVirtualCardController extends Controller
{
    use VerifiesTransactionPin;

    public function __construct(
        protected VisaVirtualCardService $visaVirtualCardService,
        protected VirtualCardService $virtualCardService,
    ) {}

    #[OA\Get(path: '/api/virtual-cards/visa-card/creation-fee', summary: 'Visa card creation fee quote', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function creationFee(Request $request): JsonResponse
    {
        try {
            $quote = $this->visaVirtualCardService->getCreationFeeQuote();

            return ResponseHelper::success($quote, 'Visa creation fee quote retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('Visa creation fee quote error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving the Visa creation fee.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-card/funding-estimate', summary: 'Visa funding estimate', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function fundingEstimate(FundingEstimateRequest $request): JsonResponse
    {
        try {
            $v = $request->validated();
            $estimate = $this->visaVirtualCardService->estimateFunding(
                (float) $v['amount'],
                (string) $v['payment_wallet_type'],
                (string) ($v['payment_wallet_currency'] ?? 'NGN')
            );

            return ResponseHelper::success($estimate, 'Visa funding estimate retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('Visa funding estimate error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while computing the Visa funding estimate.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-card', summary: 'List cards (includes Visa)', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = (int) $request->user()->id;
            $this->visaVirtualCardService->getUserCards($userId);
            $this->virtualCardService->refreshBalancesForUser($userId);
            $cards = VirtualCard::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->orderByDesc('created_at')
                ->get();

            return ResponseHelper::success($cards, 'Virtual cards retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Visa virtual cards list error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving virtual cards.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-card', summary: 'Create Visa virtual card', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function create(CreateCardRequest $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $idempotency = app(IdempotencyService::class);
            $replay = $idempotency->resolveReplay($request, $userId, 'virtual-cards.visa.create');
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            $result = $this->visaVirtualCardService->createCard($userId, $request->validated());

            if (! $result['success']) {
                $status = (int) ($result['status'] ?? 400);
                if ($status < 400 || $status > 599) {
                    $status = 422;
                }

                return ResponseHelper::error($result['message'] ?? 'Card creation failed', $status);
            }

            try {
                NotificationHelper::createTransactionNotification(
                    $request->user(),
                    'virtual_card',
                    'Visa Virtual Card Created',
                    'Your Visa virtual card was created successfully.',
                    ['action' => 'create_visa_card']
                );
            } catch (\Throwable $e) {
                Log::warning('Visa virtual card create notification failed: '.$e->getMessage());
            }

            $response = ResponseHelper::success($result['data'], $result['message'] ?? 'Virtual Visa card created successfully.');
            $idempotency->store($request, $userId, 'virtual-cards.visa.create', 200, $response->getData(true));

            return $response;
        } catch (\Exception $e) {
            Log::error('Create Visa virtual card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while creating the Visa virtual card.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-card/{id}', summary: 'Get Visa card details', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->visaVirtualCardService->getCard($request->user()->id, $id);

            if (! $card) {
                return ResponseHelper::notFound('Visa virtual card not found. Use the Mastercard card endpoint for Mastercard cards.');
            }

            return ResponseHelper::success($card, 'Card details retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get Visa card details error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving card details.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-card/{id}/fund', summary: 'Fund Visa virtual card', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function fund(FundCardRequest $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            if (! $this->visaVirtualCardService->userOwnsVisaCard($userId, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found. Use the Mastercard fund endpoint for Mastercard cards.');
            }

            $idempotency = app(IdempotencyService::class);
            $replay = $idempotency->resolveReplay($request, $userId, 'virtual-cards.visa.fund');
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            $result = $this->visaVirtualCardService->fundCard($userId, $id, $request->validated());

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card funding failed', $result['status'] ?? 400);
            }

            try {
                $amount = $request->validated()['amount'] ?? null;
                NotificationHelper::createTransactionNotification(
                    $request->user(),
                    'virtual_card',
                    'Visa Virtual Card Funded',
                    $amount ? 'Your Visa virtual card was funded with '.MoneyFormatHelper::format($amount, 'USD').'.' : 'Your Visa virtual card was funded successfully.',
                    ['action' => 'fund_visa_card', 'amount' => $amount]
                );
            } catch (\Throwable $e) {
                Log::warning('Visa virtual card fund notification failed: '.$e->getMessage());
            }

            $response = ResponseHelper::success($result['data'], $result['message'] ?? 'Card funded successfully.');
            $idempotency->store($request, $userId, 'virtual-cards.visa.fund', 200, $response->getData(true));

            return $response;
        } catch (\Exception $e) {
            Log::error('Fund Visa card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while funding the card.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-card/{id}/freeze', summary: 'Freeze Visa card', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function freeze(Request $request, int $id): JsonResponse
    {
        return $this->freezeResponse($request, $id, true);
    }

    #[OA\Get(path: '/api/virtual-cards/visa-card/{id}/terminate-estimate', summary: 'Estimate Visa card termination refund', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function terminateEstimate(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visaVirtualCardService->userOwnsVisaCard($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found.');
            }

            $result = $this->virtualCardService->estimateCardTermination($request->user()->id, $id);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Unable to estimate termination', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Termination estimate retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('Visa terminate estimate error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while estimating card termination.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-card/{id}/terminate', summary: 'Terminate Visa card', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function terminate(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visaVirtualCardService->userOwnsVisaCard($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found.');
            }

            $result = $this->virtualCardService->terminateCard($request->user()->id, $id);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card termination failed', $result['status'] ?? 400);
            }

            try {
                $refundNgn = (float) data_get($result, 'data.termination.refund_ngn', 0);
                $body = $refundNgn > 0
                    ? 'Your virtual card has been terminated. ₦'.number_format($refundNgn, 2).' was credited to your Naira wallet.'
                    : 'Your virtual card has been terminated successfully.';
                NotificationHelper::createTransactionNotification(
                    $request->user(),
                    'virtual_card',
                    'Virtual Card Terminated',
                    $body,
                    ['action' => 'terminate_card']
                );
            } catch (\Throwable $e) {
                Log::warning('Visa virtual card terminate notification failed: '.$e->getMessage());
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card terminated successfully.');
        } catch (\Exception $e) {
            Log::error('Terminate Visa card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while terminating the card.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-card/{id}/withdraw-estimate', summary: 'Estimate Visa card withdrawal refund', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function withdrawEstimate(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visaVirtualCardService->userOwnsVisaCard($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found.');
            }

            $amount = (float) $request->query('amount', 0);
            $result = $this->virtualCardService->estimateCardWithdrawal($request->user()->id, $id, $amount);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Unable to estimate withdrawal', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Withdrawal estimate retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('Visa withdraw estimate error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while estimating card withdrawal.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-card/{id}/withdraw', summary: 'Withdraw from 493 BIN Visa card', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function withdraw(WithdrawCardRequest $request, int $id): JsonResponse
    {
        try {
            if (! $this->visaVirtualCardService->userOwnsVisaCard($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found.');
            }

            $result = $this->virtualCardService->withdrawFromCard($request->user()->id, $id, $request->validated());
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Withdrawal failed', $result['status'] ?? 400);
            }

            try {
                $amount = (float) ($request->validated()['amount'] ?? 0);
                NotificationHelper::createTransactionNotification(
                    $request->user(),
                    'virtual_card',
                    'Card withdrawal submitted',
                    $amount > 0
                        ? 'Your $'.number_format($amount, 2).' card withdrawal request has been processed. Your Naira wallet will be credited once the provider confirms.'
                        : 'Your card withdrawal request has been processed.',
                    ['action' => 'withdraw_from_card', 'amount' => $amount]
                );
            } catch (\Throwable $e) {
                Log::warning('Visa virtual card withdraw notification failed: '.$e->getMessage());
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Withdrawal request submitted.');
        } catch (\Exception $e) {
            Log::error('Withdraw Visa card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while withdrawing from the card.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-card/{id}/unfreeze', summary: 'Unfreeze Visa card', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function unfreeze(Request $request, int $id): JsonResponse
    {
        return $this->freezeResponse($request, $id, false);
    }

    protected function freezeResponse(Request $request, int $id, bool $freeze): JsonResponse
    {
        try {
            if (! $this->visaVirtualCardService->userOwnsVisaCard($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found.');
            }

            $result = $this->visaVirtualCardService->toggleFreeze($request->user()->id, $id, $freeze);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Request failed', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'] ?? [], $result['message'] ?? 'OK');
        } catch (\Exception $e) {
            Log::error('Visa freeze toggle error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while updating the card.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-card/{id}/transactions', summary: 'Visa card transactions', security: [['sanctum' => []]], tags: ['Visa Virtual Cards'])]
    public function transactions(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visaVirtualCardService->userOwnsVisaCard($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found.');
            }

            $limit = (int) $request->query('limit', 50);
            $limit = max(1, min(100, $limit));
            $data = $this->visaVirtualCardService->getCardTransactions($request->user()->id, $id, $limit);

            return ResponseHelper::success($data, 'Transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Visa card transactions error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving transactions.');
        }
    }
}
