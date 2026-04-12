<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\CryptoSweepOrder;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Services\Crypto\CryptoTreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminCryptoTreasuryController extends Controller
{
    public function __construct(
        protected CryptoTreasuryService $treasury
    ) {}

    public function summary(): JsonResponse
    {
        try {
            return ResponseHelper::success($this->treasury->receivedSummary(), 'Summary retrieved.');
        } catch (\Throwable $e) {
            Log::error('Admin crypto summary failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not load treasury summary.');
        }
    }

    public function deposits(Request $request): JsonResponse
    {
        try {
            $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
            $paginator = $this->treasury->paginateDeposits($perPage, $request);

            $paginator->getCollection()->transform(function (Transaction $t) {
                $vaId = data_get($t->metadata, 'virtual_account_id');
                if ($vaId) {
                    $va = VirtualAccount::query()
                        ->select(['id', 'available_balance', 'account_balance', 'account_id', 'currency', 'blockchain', 'user_id'])
                        ->find((int) $vaId);
                    $t->setAttribute('virtual_account_hint', $va ? [
                        'id' => $va->id,
                        'account_id' => $va->account_id,
                        'available_balance' => (string) $va->available_balance,
                        'currency' => $va->currency,
                        'blockchain' => $va->blockchain,
                        'user_id' => $va->user_id,
                    ] : null);
                } else {
                    $t->setAttribute('virtual_account_hint', null);
                }

                return $t;
            });

            return ResponseHelper::success($paginator, 'Deposits retrieved.');
        } catch (\Throwable $e) {
            Log::error('Admin crypto deposits failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not load deposits.');
        }
    }

    public function receivedAssets(Request $request): JsonResponse
    {
        try {
            $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

            return ResponseHelper::success(
                $this->treasury->paginateReceivedAssets($perPage, $request),
                'Received assets retrieved.'
            );
        } catch (\Throwable $e) {
            Log::error('Admin received assets failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not load received assets.');
        }
    }

    public function sweeps(Request $request): JsonResponse
    {
        try {
            $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

            return ResponseHelper::success(
                $this->treasury->paginateSweeps($perPage),
                'Sweep orders retrieved.'
            );
        } catch (\Throwable $e) {
            Log::error('Admin crypto sweeps failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not load sweeps.');
        }
    }

    public function storeSweep(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sweep_target' => 'required|string|in:vendor,master',
            'vendor_id' => 'required_if:sweep_target,vendor|nullable|integer|exists:crypto_vendors,id',
            'virtual_account_id' => 'required|integer|exists:virtual_accounts,id',
            'amount' => 'required|numeric|min:0.00000001',
        ]);

        try {
            $order = $this->treasury->createSweepOrder(
                $request->user()->id,
                (int) $data['virtual_account_id'],
                (string) $data['amount'],
                (string) $data['sweep_target'],
                isset($data['vendor_id']) ? (int) $data['vendor_id'] : null
            );

            return ResponseHelper::success(
                $order->load(['vendor', 'masterWallet']),
                'Sweep order created (pending on-chain execution).'
            );
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            Log::error('Admin sweep create failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not create sweep order.');
        }
    }

    public function attachSweepTx(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tx_hash' => 'required|string|max:255',
        ]);

        $order = CryptoSweepOrder::query()->findOrFail($id);
        $updated = $this->treasury->markSweepTxHash($order, $data['tx_hash']);

        return ResponseHelper::success($updated, 'Sweep marked completed with tx hash.');
    }

    public function externalSends(Request $request): JsonResponse
    {
        try {
            $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

            return ResponseHelper::success(
                $this->treasury->paginateExternalSends($perPage),
                'Master wallet activity retrieved.'
            );
        } catch (\Throwable $e) {
            Log::error('Admin external sends failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not load external sends.');
        }
    }

    public function executeSweep(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->treasury->executeSweepOnChain($id, $request->user()->id);

            return ResponseHelper::success($order->load(['vendor', 'masterWallet']), 'Sweep broadcast on-chain.');
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            Log::error('Execute sweep failed', ['e' => $e->getMessage()]);

            return ResponseHelper::error('Sweep failed: '.$e->getMessage(), 500);
        }
    }
}
