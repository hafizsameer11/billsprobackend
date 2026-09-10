<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReferralController extends Controller
{
    public function __construct(
        protected ReferralService $referralService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            $data = $this->referralService->getOverviewForUser($request->user());

            return ResponseHelper::success($data, 'Referral overview retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Throwable $e) {
            Log::error('referral.show_failed: '.$e->getMessage(), [
                'user_id' => $request->user()?->id,
            ]);

            return ResponseHelper::serverError('Unable to load referral details.');
        }
    }

    public function transferToWallet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $result = $this->referralService->transferToMainWallet(
                $request->user(),
                (float) $validated['amount']
            );

            if (! $result['success']) {
                return ResponseHelper::error(
                    $result['message'] ?? 'Transfer failed',
                    (int) ($result['status'] ?? 400)
                );
            }

            return ResponseHelper::success($result['data'] ?? [], $result['message'] ?? 'Transferred.');
        } catch (\Throwable $e) {
            Log::error('referral.transfer_failed: '.$e->getMessage(), [
                'user_id' => $request->user()?->id,
            ]);

            return ResponseHelper::serverError('Unable to transfer referral earnings.');
        }
    }
}
