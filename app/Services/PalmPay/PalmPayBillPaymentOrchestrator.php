<?php

namespace App\Services\PalmPay;

use App\Models\FiatWallet;
use App\Models\PalmPayBillOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PalmPayBillPaymentOrchestrator
{
    private const SCENES = ['airtime', 'data', 'betting'];

    public function __construct(
        protected PalmPayBillApiService $billApi,
        protected AuthService $authService,
        protected WalletService $walletService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createOrder(int $userId, array $data): array
    {
        $sceneCode = $data['sceneCode'];
        if (! in_array($sceneCode, self::SCENES, true)) {
            throw new RuntimeException('sceneCode must be one of: '.implode(', ', self::SCENES));
        }

        $user = User::findOrFail($userId);
        if (! $this->authService->verifyPin($user, $data['pin'])) {
            throw new RuntimeException('Invalid PIN');
        }

        $normalizedRechargeAccount = $this->normalizeRechargeAccount((string) ($data['rechargeAccount'] ?? ''));
        if ($normalizedRechargeAccount === '') {
            throw new RuntimeException('Invalid recharge account');
        }
        $data['rechargeAccount'] = $normalizedRechargeAccount;

        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw new RuntimeException('Invalid amount');
        }

        $amountKobo = (int) round($amount * 100);
        if ($amountKobo < 100) {
            throw new RuntimeException('Minimum amount is 1.00 NGN');
        }

        $currency = strtoupper($data['currency'] ?? 'NGN');
        $wallet = $this->walletService->getFiatWallet($userId, $currency)
            ?? $this->walletService->createFiatWallet($userId, $currency, 'NG');

        if ((float) $wallet->balance < $amount) {
            throw new RuntimeException('Insufficient balance');
        }

        $outOrderNo = 'bill_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 56);

        $webhookBase = rtrim((string) Config::get('palmpay.webhook_url'), '/');
        if ($webhookBase === '') {
            throw new RuntimeException('PALMPAY_WEBHOOK_URL is not configured.');
        }
        $notifyUrl = $webhookBase.'/bill-payment';

        [$transaction, $billRow] = DB::transaction(function () use ($userId, $sceneCode, $amount, $currency, $outOrderNo, $data, $wallet) {
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'bill_payment',
                'category' => $sceneCode,
                'status' => 'pending',
                'currency' => $currency,
                'amount' => $amount,
                'fee' => 0,
                'total_amount' => $amount,
                'reference' => $outOrderNo,
                'description' => 'PalmPay '.$sceneCode.' — '.$data['billerId'],
                'metadata' => [
                    'provider' => 'palmpay',
                    'sceneCode' => $sceneCode,
                    'billerId' => $data['billerId'],
                    'providerCode' => $data['billerId'],
                    'providerName' => $data['providerName'] ?? $data['billerId'],
                    'itemId' => $data['itemId'],
                    'planName' => $data['planName'] ?? null,
                    'phoneNumber' => $data['phoneNumber'] ?? null,
                    'accountNumber' => $data['phoneNumber'] ?? null,
                    'rechargeAccount' => $data['rechargeAccount'],
                    'outOrderNo' => $outOrderNo,
                ],
            ]);

            $billRow = PalmPayBillOrder::create([
                'user_id' => $userId,
                'transaction_id' => $transaction->id,
                'fiat_wallet_id' => $wallet->id,
                'out_order_no' => $outOrderNo,
                'scene_code' => $sceneCode,
                'biller_id' => $data['billerId'],
                'item_id' => $data['itemId'],
                'recharge_account' => $data['rechargeAccount'],
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
            ]);

            $w = FiatWallet::where('id', $wallet->id)->lockForUpdate()->first();
            if (! $w || (float) $w->balance < $amount) {
                throw new RuntimeException('Insufficient balance');
            }
            $w->decrement('balance', $amount);

            return [$transaction, $billRow];
        }, 5);

        try {
            $resp = $this->billApi->createBillOrder([
                'sceneCode' => $sceneCode,
                'outOrderNo' => $outOrderNo,
                'amount' => $amountKobo,
                'notifyUrl' => $notifyUrl,
                'billerId' => $data['billerId'],
                'itemId' => $data['itemId'],
                'rechargeAccount' => $data['rechargeAccount'],
                'title' => $sceneCode.' payment',
                'description' => $sceneCode.' payment',
                'relationId' => (string) $userId,
            ]);
        } catch (\Throwable $e) {
            $this->refundWallet($wallet->id, $amount);
            $transaction->update(['status' => 'failed', 'metadata' => array_merge($transaction->metadata ?? [], ['error' => $e->getMessage()])]);
            $billRow->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            throw $e;
        }

        $orderNo = $resp['orderNo'] ?? null;
        $orderStatus = isset($resp['orderStatus']) ? (int) $resp['orderStatus'] : null;

        $billRow->update([
            'palmpay_order_no' => $orderNo,
            'palmpay_status' => $orderStatus !== null ? (string) $orderStatus : null,
            'provider_response' => $resp,
        ]);

        $transaction->update([
            'metadata' => array_merge($transaction->metadata ?? [], [
                'palmpay_order_no' => $orderNo,
                'palmpay_order_status' => $orderStatus,
            ]),
        ]);

        if ($orderStatus === 2) {
            $transaction->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            $billRow->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }

        return [
            'transactionId' => $transaction->id,
            'billOrderId' => $billRow->id,
            'outOrderNo' => $outOrderNo,
            'orderNo' => $orderNo,
            'orderStatus' => $orderStatus,
            'status' => $orderStatus === 2 ? 'completed' : 'pending',
        ];
    }

    private function refundWallet(int $walletId, float $amount): void
    {
        DB::transaction(function () use ($walletId, $amount) {
            $w = FiatWallet::where('id', $walletId)->lockForUpdate()->first();
            if ($w) {
                $w->increment('balance', $amount);
            }
        }, 5);
    }

    private function normalizeRechargeAccount(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }

        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;

        return '02340'.$last10;
    }

    /**
     * Webhook-driven finalization for pending orders.
     */
    public function applyBillWebhook(PalmPayBillOrder $row, array $payload): void
    {
        $orderStatus = isset($payload['orderStatus']) ? (int) $payload['orderStatus'] : null;
        if ($orderStatus === null) {
            return;
        }

        $orderNo = $payload['orderNo'] ?? null;
        $completedTime = $payload['completedTime'] ?? $payload['completeTime'] ?? null;

        DB::transaction(function () use ($row, $orderStatus, $orderNo, $payload, $completedTime) {
            $locked = PalmPayBillOrder::where('id', $row->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            if ($locked->status === 'completed' && $orderStatus === 2) {
                return;
            }

            $tx = Transaction::where('id', $locked->transaction_id)->lockForUpdate()->first();
            if (! $tx) {
                return;
            }

            $newStatus = match ($orderStatus) {
                2 => 'completed',
                3 => 'failed',
                4 => 'cancelled',
                default => 'pending',
            };

            $completedAt = null;
            if ($newStatus === 'completed') {
                $completedAt = $completedTime
                    ? now()->setTimestamp((int) floor((int) $completedTime / 1000))
                    : now();
            }

            $locked->update([
                'palmpay_order_no' => $orderNo ?? $locked->palmpay_order_no,
                'palmpay_status' => (string) $orderStatus,
                'provider_response' => $payload,
                'status' => $newStatus === 'pending' ? $locked->status : $newStatus,
                'error_message' => $payload['errorMsg'] ?? null,
                'completed_at' => $completedAt ?? $locked->completed_at,
            ]);

            if ($newStatus !== 'pending') {
                $tx->update([
                    'status' => $newStatus,
                    'completed_at' => $completedAt ?? $tx->completed_at,
                    'metadata' => array_merge($tx->metadata ?? [], [
                        'palmpay_order_no' => $orderNo,
                        'palmpay_webhook_status' => $orderStatus,
                    ]),
                ]);
            }

            if (($newStatus === 'failed' || $newStatus === 'cancelled') && ! $locked->refunded) {
                $wallet = FiatWallet::where('id', $locked->fiat_wallet_id)->lockForUpdate()->first();
                if ($wallet) {
                    $wallet->increment('balance', (float) $locked->amount);
                }
                $locked->update([
                    'refunded' => true,
                    'refunded_at' => now(),
                    'refund_reason' => 'PalmPay '.$newStatus,
                ]);
            }
        }, 5);
    }
}
