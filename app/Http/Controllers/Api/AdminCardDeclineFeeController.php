<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\CardDeclineFeeCharge;
use App\Models\FiatWallet;
use App\Services\VirtualCard\DeclineFeeRecoveryService;
use App\Services\VirtualCard\PagocardsAdminApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCardDeclineFeeController extends Controller
{
    public function __construct(
        protected DeclineFeeRecoveryService $recovery,
        protected PagocardsAdminApiClient $adminApi,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $fundingSource = trim((string) $request->query('funding_source', ''));
        $recoveryStatus = trim((string) $request->query('recovery_status', ''));
        $excludeWaived = $request->boolean('exclude_waived', true);

        $query = CardDeclineFeeCharge::query()
            ->with(['user:id,name,email', 'virtualCard:id,card_name,provider_card_id,is_frozen'])
            ->orderByDesc('id');

        if ($fundingSource !== '') {
            $query->where('funding_source', $fundingSource);
        }
        if ($recoveryStatus !== '') {
            $query->where('recovery_status', $recoveryStatus);
        } elseif ($excludeWaived) {
            $query->where('recovery_status', '!=', CardDeclineFeeCharge::STATUS_WAIVED);
        }

        $paginator = $query->paginate($perPage);

        return ResponseHelper::success($paginator, 'Card decline fee charges retrieved.');
    }

    public function summary(): JsonResponse
    {
        $merchantCharges = CardDeclineFeeCharge::query()
            ->where('funding_source', CardDeclineFeeCharge::FUNDING_MERCHANT)
            ->where('recovery_status', '!=', CardDeclineFeeCharge::STATUS_WAIVED);

        $negativeUsers = FiatWallet::query()
            ->where('currency', 'NGN')
            ->where('country_code', 'NG')
            ->where('balance', '<', 0)
            ->count();

        try {
            $walletBalances = $this->adminApi->getWalletBalances();
        } catch (\Throwable) {
            $walletBalances = [
                'visa_wallet_balance' => null,
                'master_wallet_balance' => null,
            ];
        }

        return ResponseHelper::success([
            'merchant_paid_count' => (clone $merchantCharges)->count(),
            'merchant_paid_total_ngn' => (float) (clone $merchantCharges)->sum('amount_ngn'),
            'outstanding_count' => (clone $merchantCharges)
                ->where('recovery_status', CardDeclineFeeCharge::STATUS_CHARGED)
                ->count(),
            'outstanding_total_ngn' => (float) (clone $merchantCharges)
                ->where('recovery_status', CardDeclineFeeCharge::STATUS_CHARGED)
                ->sum('amount_ngn'),
            'users_with_negative_naira' => $negativeUsers,
            'pagocards_wallet' => $walletBalances,
            'recovery_enabled' => $this->recovery->isEnabled(),
        ], 'Card decline fee summary retrieved.');
    }

    public function pagocardsWallet(): JsonResponse
    {
        try {
            $balances = $this->adminApi->getWalletBalances();
        } catch (\Throwable $e) {
            return ResponseHelper::error('Unable to fetch Pagocards wallet balances: '.$e->getMessage(), 502);
        }

        return ResponseHelper::success($balances, 'Pagocards wallet balances retrieved.');
    }

    public function reconcile(): JsonResponse
    {
        $processed = $this->recovery->reconcileAll();

        return ResponseHelper::success([
            'processed' => $processed,
        ], "Reconciliation complete. {$processed} new charge(s) processed.");
    }

    public function waive(CardDeclineFeeCharge $cardDeclineFee): JsonResponse
    {
        try {
            $charge = $this->recovery->waiveCharge(
                $cardDeclineFee,
                'Waived by admin'
            );
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        }

        return ResponseHelper::success($charge, 'Decline fee charge waived and wallet credited.');
    }
}
