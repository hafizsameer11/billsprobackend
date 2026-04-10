<?php

namespace App\Services\PalmPay;

use App\Models\PalmPayBillOrder;
use App\Models\PalmPayDepositOrder;
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class PalmPayWebhookProcessor
{
    public function __construct(
        protected PalmPayDepositService $depositService,
        protected PalmPayBillPaymentOrchestrator $billOrchestrator,
        protected WithdrawalService $withdrawalService
    ) {}

    /**
     * Run PalmPay business logic after signature verification (or admin replay).
     *
     * @param  array<string, mixed>  $payload
     */
    public function processVerifiedPayload(array $payload): void
    {
        $orderId = $payload['orderId'] ?? null;
        $outOrderNo = $payload['outOrderNo'] ?? null;

        if (is_string($orderId) && str_starts_with($orderId, 'payout_')) {
            $this->withdrawalService->applyPalmPayPayoutWebhook($payload);
        } elseif (is_string($orderId) && str_starts_with($orderId, 'deposit_')) {
            $this->handleDeposit($payload);
        } elseif (is_string($outOrderNo) && str_starts_with($outOrderNo, 'bill_')) {
            $this->handleBill($payload, $outOrderNo);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleDeposit(array $payload): void
    {
        $orderId = (string) ($payload['orderId'] ?? '');
        $orderStatus = isset($payload['orderStatus']) ? (int) $payload['orderStatus'] : null;
        if ($orderId === '' || $orderStatus === null) {
            return;
        }

        $expectedAppId = (string) Config::get('palmpay.app_id', '');
        if ($expectedAppId !== '' && isset($payload['appId']) && (string) $payload['appId'] !== $expectedAppId) {
            Log::warning('PalmPay deposit webhook ignored: appId mismatch', [
                'orderId' => $orderId,
            ]);

            return;
        }

        $palm = PalmPayDepositOrder::where('merchant_order_id', $orderId)->first();
        if (! $palm) {
            return;
        }

        $deposit = $palm->deposit;
        if (! $deposit || $deposit->status === 'completed') {
            return;
        }

        $amountCents = isset($payload['amount']) ? (int) $payload['amount'] : 0;
        $orderNo = isset($payload['orderNo']) ? (string) $payload['orderNo'] : '';
        $completed = $payload['completedTime'] ?? $payload['completeTime'] ?? null;
        $completedMs = is_numeric($completed) ? (int) $completed : null;

        $virtual = [
            'bankName' => $payload['payerBankName'] ?? $palm->virtual_account['bankName'] ?? null,
            'accountName' => $payload['payerAccountName'] ?? $palm->virtual_account['accountName'] ?? null,
            'accountNumber' => $payload['payerVirtualAccNo'] ?? $palm->virtual_account['accountNumber'] ?? null,
        ];

        if ($orderStatus === 2) {
            $this->depositService->applyDepositSuccess(
                $deposit,
                $palm,
                $amountCents > 0 ? $amountCents : (int) round((float) $deposit->amount * 100),
                $orderNo,
                $completedMs,
                array_filter($virtual)
            );
        } elseif ($orderStatus === 3 || $orderStatus === 4) {
            $this->depositService->markDepositFailed($deposit, $palm, $orderStatus);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleBill(array $payload, string $outOrderNo): void
    {
        $row = PalmPayBillOrder::where('out_order_no', $outOrderNo)->first();
        if (! $row) {
            return;
        }

        $this->billOrchestrator->applyBillWebhook($row, $payload);
    }
}
