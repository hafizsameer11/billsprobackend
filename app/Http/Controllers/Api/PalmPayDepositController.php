<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\PalmPayDepositOrder;
use App\Services\PalmPay\PalmPayDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PalmPayDepositController extends Controller
{
    public function __construct(
        protected PalmPayDepositService $palmPayDepositService
    ) {}

    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'string', 'max:10'],
        ]);

        try {
            $currency = strtoupper($request->input('currency', 'NGN'));
            $result = $this->palmPayDepositService->initiate(
                $request->user()->id,
                (float) $request->input('amount'),
                $currency
            );

            /** @var \App\Models\Deposit $deposit */
            $deposit = $result['deposit'];

            return ResponseHelper::success([
                'depositReference' => $deposit->deposit_reference,
                'amount' => $deposit->amount,
                'currency' => $deposit->currency,
                'merchantOrderId' => $result['palmPayOrder']->merchant_order_id,
                'orderNo' => $result['orderNo'],
                'virtualAccount' => $result['virtualAccount'],
                'checkoutUrl' => $result['checkoutUrl'],
            ], 'PalmPay deposit initiated. Transfer to the virtual account to complete.');
        } catch (\Throwable $e) {
            Log::error('PalmPay deposit initiate failed', ['e' => $e->getMessage()]);

            return ResponseHelper::error($e->getMessage(), 400);
        }
    }

    public function status(Request $request, string $depositReference): JsonResponse
    {
        $deposit = Deposit::where('user_id', $request->user()->id)
            ->where('deposit_reference', $depositReference)
            ->first();

        if (! $deposit) {
            return ResponseHelper::error('Deposit not found', 404);
        }

        $order = PalmPayDepositOrder::where('deposit_id', $deposit->id)->first();
        if (! $order) {
            return ResponseHelper::error('PalmPay order not found for this deposit', 404);
        }

        if ($deposit->status === 'completed') {
            return ResponseHelper::success([
                'status' => $deposit->status,
                'depositReference' => $deposit->deposit_reference,
                'transactionId' => $deposit->transaction_id,
            ], 'Deposit completed.');
        }

        try {
            $remote = $this->palmPayDepositService->refreshRemoteStatus($deposit, $order);
            $deposit = $deposit->fresh();

            return ResponseHelper::success([
                'status' => $deposit->status,
                'depositReference' => $deposit->deposit_reference,
                'transactionId' => $deposit->transaction_id,
                'palmpay' => [
                    'orderStatus' => $remote['orderStatus'] ?? null,
                    'orderNo' => $remote['orderNo'] ?? null,
                ],
            ], 'Status retrieved.');
        } catch (\Throwable $e) {
            Log::warning('PalmPay deposit status poll failed', ['e' => $e->getMessage()]);

            return ResponseHelper::success([
                'status' => $deposit->status,
                'depositReference' => $deposit->deposit_reference,
                'message' => 'Could not refresh remote status; rely on webhook or retry.',
            ], 'Local status only.');
        }
    }
}
