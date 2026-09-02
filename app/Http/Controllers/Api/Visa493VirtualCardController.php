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
use App\Services\VirtualCard\Visa493VirtualCardService;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: '493 BIN Visa Virtual Cards',
    description: '493 BIN Visa via Pagocards `POST /v1/cards/*`. Separate from legacy `/virtual-cards/visa-card` (`/visacard/*`).',
)]
class Visa493VirtualCardController extends Controller
{
    use VerifiesTransactionPin;

    public function __construct(
        protected Visa493VirtualCardService $visa493VirtualCardService,
        protected VirtualCardService $virtualCardService,
    ) {}

    #[OA\Get(path: '/api/virtual-cards/visa-493/creation-fee', summary: '493 BIN Visa creation fee quote', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function creationFee(Request $request): JsonResponse
    {
        try {
            $quote = $this->visa493VirtualCardService->getCreationFeeQuote();

            return ResponseHelper::success($quote, 'Visa creation fee quote retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('493 BIN Visa creation fee quote error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving the Visa creation fee.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-493/funding-estimate', summary: '493 BIN Visa funding estimate', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function fundingEstimate(FundingEstimateRequest $request): JsonResponse
    {
        try {
            $v = $request->validated();
            $estimate = $this->visa493VirtualCardService->estimateFunding(
                (float) $v['amount'],
                (string) $v['payment_wallet_type'],
                (string) ($v['payment_wallet_currency'] ?? 'NGN')
            );

            return ResponseHelper::success($estimate, 'Visa funding estimate retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('493 BIN Visa funding estimate error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while computing the Visa funding estimate.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-493', summary: 'Create 493 BIN Visa virtual card', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function create(CreateCardRequest $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $idempotency = app(IdempotencyService::class);
            $replay = $idempotency->resolveReplay($request, $userId, 'virtual-cards.visa-493.create');
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            $result = $this->visa493VirtualCardService->createCard($userId, $request->validated());

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
                    'Visa Card Created',
                    'Your Visa virtual card was created successfully.',
                    ['action' => 'create_visa_493_card']
                );
            } catch (\Throwable $e) {
                Log::warning('493 BIN Visa create notification failed: '.$e->getMessage());
            }

            $response = ResponseHelper::success($result['data'], $result['message'] ?? 'Visa card created successfully.');
            $idempotency->store($request, $userId, 'virtual-cards.visa-493.create', 200, $response->getData(true));

            return $response;
        } catch (\Exception $e) {
            Log::error('Create 493 BIN Visa card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while creating the Visa card.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-493/{id}', summary: 'Get 493 BIN Visa card details', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $card = $this->visa493VirtualCardService->getCard($request->user()->id, $id);

            if (! $card) {
                return ResponseHelper::notFound('Visa card not found.');
            }

            return ResponseHelper::success($card, 'Card details retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get 493 BIN Visa card details error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving card details.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-493/{id}/fund', summary: 'Fund 493 BIN Visa card', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function fund(FundCardRequest $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            if (! $this->visa493VirtualCardService->userOwnsVisa493Card($userId, $id)) {
                return ResponseHelper::notFound('Visa card not found.');
            }

            $idempotency = app(IdempotencyService::class);
            $replay = $idempotency->resolveReplay($request, $userId, 'virtual-cards.visa-493.fund');
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            $result = $this->visa493VirtualCardService->fundCard($userId, $id, $request->validated());

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card funding failed', $result['status'] ?? 400);
            }

            try {
                $amount = $request->validated()['amount'] ?? null;
                NotificationHelper::createTransactionNotification(
                    $request->user(),
                    'virtual_card',
                    'Visa Card Funded',
                    $amount ? 'Your Visa card was funded with '.MoneyFormatHelper::format($amount, 'USD').'.' : 'Your Visa card was funded successfully.',
                    ['action' => 'fund_visa_493_card', 'amount' => $amount]
                );
            } catch (\Throwable $e) {
                Log::warning('493 BIN Visa fund notification failed: '.$e->getMessage());
            }

            $response = ResponseHelper::success($result['data'], $result['message'] ?? 'Card funded successfully.');
            $idempotency->store($request, $userId, 'virtual-cards.visa-493.fund', 200, $response->getData(true));

            return $response;
        } catch (\Exception $e) {
            Log::error('Fund 493 BIN Visa card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while funding the card.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-493/{id}/freeze', summary: 'Freeze 493 BIN Visa card', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function freeze(Request $request, int $id): JsonResponse
    {
        return $this->freezeResponse($request, $id, true);
    }

    #[OA\Post(path: '/api/virtual-cards/visa-493/{id}/unfreeze', summary: 'Unfreeze 493 BIN Visa card', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function unfreeze(Request $request, int $id): JsonResponse
    {
        return $this->freezeResponse($request, $id, false);
    }

    #[OA\Get(path: '/api/virtual-cards/visa-493/{id}/terminate-estimate', summary: 'Estimate 493 BIN Visa termination refund', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function terminateEstimate(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visa493VirtualCardService->userOwnsVisa493Card($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa card not found.');
            }

            $result = $this->virtualCardService->estimateCardTermination($request->user()->id, $id);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Unable to estimate termination', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Termination estimate retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('493 BIN Visa terminate estimate error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while estimating card termination.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-493/{id}/terminate', summary: 'Terminate 493 BIN Visa card', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function terminate(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visa493VirtualCardService->userOwnsVisa493Card($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa card not found.');
            }

            $result = $this->virtualCardService->terminateCard($request->user()->id, $id);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card termination failed', $result['status'] ?? 400);
            }

            try {
                $refundNgn = (float) data_get($result, 'data.termination.refund_ngn', 0);
                $body = $refundNgn > 0
                    ? 'Your Visa card has been terminated. ₦'.number_format($refundNgn, 2).' will be credited to your Naira wallet after provider confirmation.'
                    : 'Your Visa card has been terminated successfully.';
                NotificationHelper::createTransactionNotification(
                    $request->user(),
                    'virtual_card',
                    'Visa Card Terminated',
                    $body,
                    ['action' => 'terminate_visa_493_card']
                );
            } catch (\Throwable $e) {
                Log::warning('493 BIN Visa terminate notification failed: '.$e->getMessage());
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card terminated successfully.');
        } catch (\Exception $e) {
            Log::error('Terminate 493 BIN Visa card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while terminating the card.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-493/{id}/withdraw-estimate', summary: 'Estimate 493 BIN Visa withdrawal refund', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function withdrawEstimate(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visa493VirtualCardService->userOwnsVisa493Card($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa card not found.');
            }

            $amount = (float) $request->query('amount', 0);
            $result = $this->virtualCardService->estimateCardWithdrawal($request->user()->id, $id, $amount);
            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Unable to estimate withdrawal', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Withdrawal estimate retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('493 BIN Visa withdraw estimate error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while estimating card withdrawal.');
        }
    }

    #[OA\Post(path: '/api/virtual-cards/visa-493/{id}/withdraw', summary: 'Withdraw from 493 BIN Visa card', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function withdraw(WithdrawCardRequest $request, int $id): JsonResponse
    {
        try {
            if (! $this->visa493VirtualCardService->userOwnsVisa493Card($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa card not found.');
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
                    'Visa withdrawal submitted',
                    $amount > 0
                        ? 'Your $'.number_format($amount, 2).' card withdrawal request has been processed. Your Naira wallet will be credited once the provider confirms.'
                        : 'Your card withdrawal request has been processed.',
                    ['action' => 'withdraw_from_visa_493_card', 'amount' => $amount]
                );
            } catch (\Throwable $e) {
                Log::warning('493 BIN Visa withdraw notification failed: '.$e->getMessage());
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Withdrawal request submitted.');
        } catch (\Exception $e) {
            Log::error('Withdraw 493 BIN Visa card error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while withdrawing from the card.');
        }
    }

    #[OA\Get(path: '/api/virtual-cards/visa-493/{id}/transactions', summary: '493 BIN Visa card transactions', security: [['sanctum' => []]], tags: ['493 BIN Visa Virtual Cards'])]
    public function transactions(Request $request, int $id): JsonResponse
    {
        try {
            if (! $this->visa493VirtualCardService->userOwnsVisa493Card($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa card not found.');
            }

            $limit = (int) $request->query('limit', 50);
            $limit = max(1, min(100, $limit));
            $data = $this->visa493VirtualCardService->getCardTransactions($request->user()->id, $id, $limit);

            return ResponseHelper::success($data, 'Transactions retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('493 BIN Visa card transactions error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving transactions.');
        }
    }

    protected function freezeResponse(Request $request, int $id, bool $freeze): JsonResponse
    {
        try {
            if (! $this->visa493VirtualCardService->userOwnsVisa493Card($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa card not found.');
            }

            $result = $this->visa493VirtualCardService->toggleFreeze($request->user()->id, $id, $freeze);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Request failed', $result['status'] ?? 400);
            }

            return ResponseHelper::success($result['data'] ?? [], $result['message'] ?? 'OK');
        } catch (\Exception $e) {
            Log::error('493 BIN Visa freeze toggle error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'card_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while updating the card.');
        }
    }
}
