<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\VirtualCard\CreateCardRequest;
use App\Http\Requests\VirtualCard\FundCardRequest;
use App\Http\Requests\VirtualCard\FundingEstimateRequest;
use App\Services\VirtualCard\VisaVirtualCardService;
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
    public function __construct(
        protected VisaVirtualCardService $visaVirtualCardService,
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
            $cards = $this->visaVirtualCardService->getUserCards($request->user()->id);

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
            $result = $this->visaVirtualCardService->createCard($request->user()->id, $request->validated());

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card creation failed', $result['status'] ?? 400);
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

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Virtual Visa card created successfully.');
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
            if (! $this->visaVirtualCardService->userOwnsVisaCard($request->user()->id, $id)) {
                return ResponseHelper::notFound('Visa virtual card not found. Use the Mastercard fund endpoint for Mastercard cards.');
            }

            $result = $this->visaVirtualCardService->fundCard($request->user()->id, $id, $request->validated());

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Card funding failed', $result['status'] ?? 400);
            }

            try {
                $amount = $request->validated()['amount'] ?? null;
                NotificationHelper::createTransactionNotification(
                    $request->user(),
                    'virtual_card',
                    'Visa Virtual Card Funded',
                    $amount ? "Your Visa virtual card was funded with USD {$amount}." : 'Your Visa virtual card was funded successfully.',
                    ['action' => 'fund_visa_card', 'amount' => $amount]
                );
            } catch (\Throwable $e) {
                Log::warning('Visa virtual card fund notification failed: '.$e->getMessage());
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Card funded successfully.');
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
