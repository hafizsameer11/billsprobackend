<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdminBalanceAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAdjustmentController extends Controller
{
    public function __construct(
        protected AdminBalanceAdjustmentService $adjustments
    ) {}

    public function fiat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fiat_wallet_id' => 'required|integer|exists:fiat_wallets,id',
            'direction' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.00000001',
            'reason' => 'required|string|max:2000',
            'reference' => 'nullable|string|max:255',
        ]);

        $result = $this->adjustments->adjustFiat(
            (int) $request->user()->id,
            (int) $data['fiat_wallet_id'],
            $data['direction'],
            (string) $data['amount'],
            $data['reason'],
            $data['reference'] ?? null,
            $request
        );

        if (! $result['success']) {
            return ResponseHelper::error($result['message'] ?? 'Adjustment failed', 400);
        }

        return ResponseHelper::success($result['data'], 'Fiat adjustment applied.');
    }

    public function crypto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'virtual_account_id' => 'required|integer|exists:virtual_accounts,id',
            'direction' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.00000001',
            'reason' => 'required|string|max:2000',
            'reference' => 'nullable|string|max:255',
        ]);

        $result = $this->adjustments->adjustCrypto(
            (int) $request->user()->id,
            (int) $data['virtual_account_id'],
            $data['direction'],
            (string) $data['amount'],
            $data['reason'],
            $data['reference'] ?? null,
            $request
        );

        if (! $result['success']) {
            return ResponseHelper::error($result['message'] ?? 'Adjustment failed', 400);
        }

        return ResponseHelper::success($result['data'], 'Crypto adjustment applied.');
    }
}
