<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\PalmPay\PalmPayBillApiService;
use App\Services\PalmPay\PalmPayBillPaymentOrchestrator;
use App\Services\Http\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PalmPayBillPaymentController extends Controller
{
    public function __construct(
        protected PalmPayBillApiService $billApi,
        protected PalmPayBillPaymentOrchestrator $orchestrator
    ) {}

    public function billers(Request $request): JsonResponse
    {
        $request->validate([
            'sceneCode' => ['required', 'string', 'in:airtime,data,betting'],
        ]);

        try {
            $rows = $this->billApi->queryBillers($request->query('sceneCode'));

            return ResponseHelper::success([
                'sceneCode' => $request->query('sceneCode'),
                'billers' => $rows,
            ], 'Billers retrieved.');
        } catch (\Throwable $e) {
            Log::error('PalmPay billers failed', ['e' => $e->getMessage()]);

            return ResponseHelper::error($e->getMessage(), 400);
        }
    }

    public function items(Request $request): JsonResponse
    {
        $request->validate([
            'sceneCode' => ['required', 'string', 'in:airtime,data,betting'],
            'billerId' => ['required', 'string'],
        ]);

        try {
            $rows = $this->billApi->queryItems(
                $request->query('sceneCode'),
                $request->query('billerId')
            );

            return ResponseHelper::success([
                'sceneCode' => $request->query('sceneCode'),
                'billerId' => $request->query('billerId'),
                'items' => $rows,
            ], 'Items retrieved.');
        } catch (\Throwable $e) {
            Log::error('PalmPay items failed', ['e' => $e->getMessage()]);

            return ResponseHelper::error($e->getMessage(), 400);
        }
    }

    public function verifyAccount(Request $request): JsonResponse
    {
        $request->validate([
            'sceneCode' => ['required', 'string', 'in:airtime,data,betting'],
            'rechargeAccount' => ['required', 'string', 'max:50'],
            'billerId' => ['sometimes', 'string'],
            'itemId' => ['sometimes', 'string'],
        ]);

        try {
            $normalizedRechargeAccount = $this->normalizeRechargeAccount(
                (string) $request->input('rechargeAccount'),
                (string) $request->input('sceneCode')
            );
            $extra = array_filter([
                'billerId' => $request->input('billerId'),
                'itemId' => $request->input('itemId'),
            ]);

            $data = $this->billApi->queryRechargeAccount(
                $request->input('sceneCode'),
                $normalizedRechargeAccount,
                $extra
            );

            return ResponseHelper::success([
                'sceneCode' => $request->input('sceneCode'),
                'rechargeAccount' => $normalizedRechargeAccount,
                'result' => $data,
            ], 'Verification completed.');
        } catch (\Throwable $e) {
            Log::error('PalmPay verify account failed', ['e' => $e->getMessage()]);

            return ResponseHelper::error($e->getMessage(), 400);
        }
    }

    public function createOrder(Request $request): JsonResponse
    {
        $request->validate([
            'sceneCode' => ['required', 'string', 'in:airtime,data,betting'],
            'billerId' => ['required', 'string'],
            'itemId' => ['required', 'string'],
            'rechargeAccount' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'pin' => ['required', 'digits:4'],
        ]);

        try {
            $userId = $request->user()->id;
            $idempotency = app(IdempotencyService::class);
            $replay = $idempotency->resolveReplay($request, $userId, 'palmpay.bill.create-order');
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            $normalizedRechargeAccount = $this->normalizeRechargeAccount(
                (string) $request->input('rechargeAccount'),
                (string) $request->input('sceneCode')
            );
            $result = $this->orchestrator->createOrder($userId, [
                'sceneCode' => $request->input('sceneCode'),
                'billerId' => $request->input('billerId'),
                'itemId' => $request->input('itemId'),
                'rechargeAccount' => $normalizedRechargeAccount,
                'phoneNumber' => $request->input('phoneNumber'),
                'providerName' => $request->input('providerName'),
                'planName' => $request->input('planName'),
                'amount' => (float) $request->input('amount'),
                'currency' => $request->input('currency', 'NGN'),
                'pin' => $request->input('pin'),
            ]);

            $response = ResponseHelper::success($result, 'Bill order created.');
            $idempotency->store($request, $userId, 'palmpay.bill.create-order', 200, $response->getData(true));

            return $response;
        } catch (\Throwable $e) {
            Log::error('PalmPay bill create failed', ['e' => $e->getMessage()]);

            return ResponseHelper::error($e->getMessage(), 400);
        }
    }

    private function normalizeRechargeAccount(string $value, string $sceneCode = ''): string
    {
        $scene = strtolower(trim($sceneCode));
        if ($scene === 'betting') {
            return trim($value);
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }

        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;

        return '02340'.$last10;
    }
}
