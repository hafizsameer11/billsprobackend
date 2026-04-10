<?php

namespace App\Services\PalmPay;

use App\Models\Deposit;
use App\Models\FiatWallet;
use App\Models\PalmPayDepositOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PalmPayDepositService
{
    public function __construct(
        protected PalmPayCheckoutService $checkout
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function initiate(int $userId, float $amount, string $currency = 'NGN'): array
    {
        $min = (float) Config::get('palmpay.min_deposit_ngn', 100);
        if ($amount < $min) {
            throw new RuntimeException("Minimum deposit amount is {$min} {$currency}.");
        }

        $amountKobo = (int) round($amount * 100);
        if ($amountKobo < 10000) {
            throw new RuntimeException('Minimum deposit is 100.00 NGN (10,000 kobo).');
        }

        $user = User::findOrFail($userId);

        $merchantOrderId = $this->makeMerchantOrderId();

        $deposit = Deposit::create([
            'user_id' => $userId,
            'bank_account_id' => null,
            'deposit_reference' => Deposit::generateReference(),
            'currency' => strtoupper($currency),
            'amount' => $amount,
            'fee' => 0,
            'total_amount' => $amount,
            'status' => 'pending',
            'payment_method' => 'palmpay',
            'metadata' => [
                'provider' => 'palmpay',
                'merchant_order_id' => $merchantOrderId,
            ],
        ]);

        $webhookBase = rtrim((string) Config::get('palmpay.webhook_url'), '/');
        if ($webhookBase === '') {
            throw new RuntimeException('PALMPAY_WEBHOOK_URL is not configured.');
        }

        $notifyUrl = $webhookBase;
        $frontend = rtrim((string) Config::get('palmpay.frontend_url', config('app.url')), '/');
        $callBackUrl = $frontend.'/deposit/success';

        $payload = [
            'orderId' => $merchantOrderId,
            'title' => 'Wallet Top-up',
            'description' => 'Deposit to '.$currency.' wallet',
            'amount' => $amountKobo,
            'currency' => strtoupper($currency),
            'notifyUrl' => $notifyUrl,
            'callBackUrl' => $callBackUrl,
            'productType' => 'bank_transfer',
            'goodsDetails' => json_encode([['goodsId' => '-1']]),
            'userId' => (string) $userId,
            'userMobileNo' => $user->phone_number ?? '',
            'remark' => 'Wallet top-up user '.$userId,
        ];

        $palmpay = $this->checkout->createOrder($payload);

        $virtualAccount = [
            'accountType' => $palmpay['payerAccountType'] ?? null,
            'accountId' => $palmpay['payerAccountId'] ?? null,
            'bankName' => $palmpay['payerBankName'] ?? null,
            'accountName' => $palmpay['payerAccountName'] ?? null,
            'accountNumber' => $palmpay['payerVirtualAccNo'] ?? null,
        ];

        $order = PalmPayDepositOrder::create([
            'user_id' => $userId,
            'deposit_id' => $deposit->id,
            'merchant_order_id' => $merchantOrderId,
            'palmpay_order_no' => $palmpay['orderNo'] ?? null,
            'order_status' => (int) ($palmpay['orderStatus'] ?? 1),
            'virtual_account' => $virtualAccount,
            'checkout_url' => $palmpay['checkoutUrl'] ?? null,
            'raw_create_response' => $palmpay,
        ]);

        $deposit->update([
            'metadata' => array_merge($deposit->metadata ?? [], [
                'palmpay_order_no' => $palmpay['orderNo'] ?? null,
                'checkout_url' => $palmpay['checkoutUrl'] ?? null,
            ]),
        ]);

        return [
            'deposit' => $deposit,
            'palmPayOrder' => $order,
            'virtualAccount' => $virtualAccount,
            'checkoutUrl' => $palmpay['checkoutUrl'] ?? null,
            'orderNo' => $palmpay['orderNo'] ?? null,
        ];
    }

    /**
     * Poll PalmPay for latest status. When the platform reports a terminal state, applies the same
     * wallet credit / failure handling as the webhook (idempotent if already completed).
     *
     * @return array<string, mixed>
     */
    public function refreshRemoteStatus(Deposit $deposit, PalmPayDepositOrder $order): array
    {
        $remote = $this->checkout->queryOrderStatus($order->merchant_order_id, $order->palmpay_order_no);

        $orderStatus = (int) ($remote['orderStatus'] ?? $order->order_status);

        $order->update([
            'palmpay_order_no' => $remote['orderNo'] ?? $order->palmpay_order_no,
            'order_status' => $orderStatus,
        ]);

        $deposit = $deposit->fresh();
        if (! $deposit || $deposit->status !== 'pending') {
            return $remote;
        }

        $order = $order->fresh();
        if (! $order) {
            return $remote;
        }

        if ($orderStatus === 2) {
            $amountRaw = $remote['amount'] ?? $remote['orderAmount'] ?? null;
            $amountCents = is_numeric($amountRaw)
                ? (int) $amountRaw
                : (int) round((float) $deposit->amount * 100);
            $orderNo = (string) ($remote['orderNo'] ?? $order->palmpay_order_no ?? '');
            $completed = $remote['completedTime'] ?? $remote['completeTime'] ?? null;
            $completedMs = is_numeric($completed) ? (int) $completed : null;

            $virtual = array_filter([
                'bankName' => $remote['payerBankName'] ?? null,
                'accountName' => $remote['payerAccountName'] ?? null,
                'accountNumber' => $remote['payerVirtualAccNo'] ?? null,
            ]);

            $this->applyDepositSuccess(
                $deposit,
                $order,
                $amountCents > 0 ? $amountCents : (int) round((float) $deposit->amount * 100),
                $orderNo,
                $completedMs,
                $virtual
            );
        } elseif ($orderStatus === 3 || $orderStatus === 4) {
            $this->markDepositFailed($deposit, $order, $orderStatus);
        }

        return $remote;
    }

    /**
     * Apply successful webhook / terminal success: credit wallet once.
     *
     * @param  array<string, mixed>  $virtualAccountMeta  optional VA display updates
     */
    public function applyDepositSuccess(
        Deposit $deposit,
        PalmPayDepositOrder $order,
        int $amountCents,
        string $palmpayOrderNo,
        ?int $completedTimeMs = null,
        array $virtualAccountMeta = []
    ): void {
        DB::transaction(function () use ($deposit, $order, $amountCents, $palmpayOrderNo, $completedTimeMs, $virtualAccountMeta) {
            $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'completed') {
                return;
            }

            $creditAmount = bcdiv((string) $amountCents, '100', 8);

            $userId = $deposit->user_id;
            $currency = $deposit->currency;
            $countryCode = $currency === 'NGN' ? 'NG' : 'NG';

            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('country_code', $countryCode)
                ->lockForUpdate()
                ->first();

            if (! $fiatWallet) {
                $fiatWallet = FiatWallet::create([
                    'user_id' => $userId,
                    'currency' => $currency,
                    'country_code' => $countryCode,
                    'balance' => 0,
                    'locked_balance' => 0,
                    'is_active' => true,
                ]);
                $fiatWallet = FiatWallet::where('id', $fiatWallet->id)->lockForUpdate()->first();
            }

            $fiatWallet->increment('balance', $creditAmount);

            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'deposit',
                'category' => 'fiat_deposit',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $creditAmount,
                'fee' => 0,
                'total_amount' => $creditAmount,
                'reference' => $deposit->deposit_reference,
                'description' => 'PalmPay wallet top-up',
                'metadata' => [
                    'deposit_id' => $deposit->id,
                    'payment_method' => 'palmpay',
                    'merchant_order_id' => $order->merchant_order_id,
                    'palmpay_order_no' => $palmpayOrderNo,
                ],
                'bank_name' => $virtualAccountMeta['bankName'] ?? $order->virtual_account['bankName'] ?? null,
                'account_number' => $virtualAccountMeta['accountNumber'] ?? $order->virtual_account['accountNumber'] ?? null,
                'account_name' => $virtualAccountMeta['accountName'] ?? $order->virtual_account['accountName'] ?? null,
                'completed_at' => $completedTimeMs ? now()->setTimestamp((int) floor($completedTimeMs / 1000)) : now(),
            ]);

            $deposit->update([
                'status' => 'completed',
                'transaction_id' => $transaction->id,
                'completed_at' => $transaction->completed_at,
                'metadata' => array_merge($deposit->metadata ?? [], [
                    'palmpay_order_no' => $palmpayOrderNo,
                ]),
            ]);

            $order->update([
                'palmpay_order_no' => $palmpayOrderNo,
                'order_status' => 2,
                'virtual_account' => array_merge($order->virtual_account ?? [], $virtualAccountMeta),
            ]);
        }, 5);
    }

    public function markDepositFailed(Deposit $deposit, PalmPayDepositOrder $order, int $orderStatus): void
    {
        if ($deposit->status === 'completed') {
            return;
        }

        $status = $orderStatus === 3 ? 'failed' : 'cancelled';

        $deposit->update([
            'status' => $status,
            'completed_at' => now(),
            'metadata' => array_merge($deposit->metadata ?? [], ['palmpay_terminal_status' => $orderStatus]),
        ]);

        $order->update(['order_status' => $orderStatus]);
    }

    private function makeMerchantOrderId(): string
    {
        $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 24);

        return 'deposit_'.$suffix;
    }
}
