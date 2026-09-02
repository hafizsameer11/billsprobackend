<?php

namespace App\Services\VirtualCard;

use App\Helpers\NotificationHelper;
use App\Models\FiatWallet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardTransaction;
use App\Services\Platform\PlatformRateResolver;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VirtualCard493WithdrawalService
{
    public function __construct(
        protected Visa493BinApiClient $visa493BinApiClient,
        protected WalletService $walletService,
        protected PlatformRateResolver $platformRates,
    ) {}

    /**
     * @return array{success: bool, message?: string, status?: int, data?: array<string, mixed>}
     */
    public function estimate(int $userId, int $cardId, float $amountUsd): array
    {
        $resolved = $this->resolveWithdrawableCard($userId, $cardId);
        if (is_array($resolved)) {
            return $resolved;
        }
        $card = $resolved;

        $amountUsd = round($amountUsd, 2);
        if ($amountUsd < 1.0) {
            return [
                'success' => false,
                'message' => 'Minimum withdrawal amount is $1.00.',
                'status' => 422,
            ];
        }

        $this->syncCardBalance($userId, $card);
        $card->refresh();

        $balanceUsd = round(max(0, (float) $card->balance), 2);
        if ($amountUsd > $balanceUsd + 0.0001) {
            return [
                'success' => false,
                'message' => 'Insufficient card balance for this withdrawal.',
                'status' => 422,
            ];
        }

        $rateQuote = $this->resolveWithdrawExchangeRate($cardId, $userId);
        $exchangeRate = $rateQuote['rate'];

        $refundNgn = round($amountUsd * $exchangeRate, 2);

        return [
            'success' => true,
            'message' => 'Withdrawal estimate retrieved successfully.',
            'data' => [
                'card_id' => $cardId,
                'withdrawal_usd' => $amountUsd,
                'card_balance_usd' => $balanceUsd,
                'exchange_rate_ngn_per_usd' => $exchangeRate,
                'exchange_rate_source' => $rateQuote['source'],
                'refund_ngn' => $refundNgn,
                'refund_currency' => 'NGN',
                'can_withdraw' => true,
            ],
        ];
    }

    /**
     * @return array{success: bool, message?: string, status?: int, data?: array<string, mixed>}
     */
    public function initiate(int $userId, int $cardId, float $amountUsd): array
    {
        return $this->initiateInternal($userId, $cardId, $amountUsd, false, true);
    }

    public function initiateForTermination(int $userId, int $cardId, float $amountUsd): array
    {
        return $this->initiateInternal($userId, $cardId, $amountUsd, true, false);
    }

    public function lastFundingExchangeRateNgnPerUsd(int $virtualCardId, int $userId): ?float
    {
        return $this->resolveWithdrawExchangeRate($virtualCardId, $userId)['rate'];
    }

    /**
     * @return array{rate: float, source: 'last_funding'|'visa_fund'}
     */
    public function resolveWithdrawExchangeRate(int $virtualCardId, int $userId): array
    {
        $fromFunding = $this->rateFromLastFundingTransaction($virtualCardId, $userId);
        if ($fromFunding !== null) {
            return ['rate' => $fromFunding, 'source' => 'last_funding'];
        }

        return ['rate' => $this->visaFundExchangeRateNgnPerUsd(), 'source' => 'visa_fund'];
    }

    protected function initiateInternal(
        int $userId,
        int $cardId,
        float $amountUsd,
        bool $forTermination,
        bool $useLock
    ): array {
        $runner = fn (): array => $this->executeWithdrawal($userId, $cardId, $amountUsd, $forTermination);

        if (! $useLock) {
            return $runner();
        }

        $lock = Cache::lock("virtual-card-withdraw:{$cardId}", 60);

        try {
            return $lock->block(10, $runner);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return [
                'success' => false,
                'message' => 'A withdrawal is already in progress for this card. Please wait a moment and try again.',
                'status' => 409,
            ];
        }
    }

    public function trySettleFromWebhook(VirtualCard $card, string $eventName, array $eventData, string $externalEventId): void
    {
        if (! $this->isVisa493BinCard($card)) {
            return;
        }

        $pending = $this->findOldestPendingWithdrawal((int) $card->id, (int) $card->user_id);
        if (! $pending) {
            return;
        }

        $expectedUsd = (float) data_get($pending->metadata, 'withdrawal_usd', 0);
        if ($expectedUsd <= 0) {
            return;
        }

        if (! $this->webhookLooksLikeAppUnload($eventName, $eventData, $expectedUsd)) {
            return;
        }

        $eventUsd = $this->resolveWebhookUsdAmount($eventName, $eventData);
        if ($eventUsd > 0 && abs($eventUsd - $expectedUsd) > 0.05) {
            return;
        }

        $providerTxId = trim((string) (
            $eventData['id']
            ?? $eventData['eventTargetId']
            ?? $eventData['reference']
            ?? $externalEventId
        ));

        try {
            $this->completePendingWithdrawal($pending, $providerTxId !== '' ? $providerTxId : $externalEventId, [
                'source' => 'pagocards_webhook',
                'provider_event' => $eventName,
                'provider_event_id' => $externalEventId,
                'provider_payload' => $eventData,
            ]);
        } catch (\Throwable $e) {
            Log::error('virtual_card.withdraw_webhook_settlement_failed', [
                'virtual_card_id' => $card->id,
                'user_id' => $card->user_id,
                'pending_transaction_id' => $pending->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{success: bool, message?: string, status?: int, data?: array<string, mixed>}
     */
    protected function executeWithdrawal(int $userId, int $cardId, float $amountUsd, bool $forTermination = false): array
    {
        $estimate = $this->estimate($userId, $cardId, $amountUsd);
        if (! $estimate['success']) {
            return $estimate;
        }

        $quote = $estimate['data'];
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->firstOrFail();
        $amountUsd = (float) $quote['withdrawal_usd'];
        $exchangeRate = (float) $quote['exchange_rate_ngn_per_usd'];
        $refundNgn = (float) $quote['refund_ngn'];
        $reference = 'WD'.strtoupper(substr(md5(uniqid((string) $userId, true)), 0, 12));

        if (! $forTermination && $this->findOldestPendingWithdrawal($cardId, $userId)) {
            return [
                'success' => false,
                'message' => 'You already have a withdrawal awaiting confirmation. Please wait for it to complete.',
                'status' => 409,
            ];
        }

        try {
            $pending = DB::transaction(function () use ($userId, $card, $amountUsd, $exchangeRate, $refundNgn, $reference, $quote, $forTermination) {
                $lockedCard = VirtualCard::where('id', $card->id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedCard->is_active || $lockedCard->is_frozen) {
                    throw new \RuntimeException('This card cannot accept withdrawals right now.');
                }

                $balanceUsd = round(max(0, (float) $lockedCard->balance), 2);
                if ($amountUsd > $balanceUsd + 0.0001) {
                    throw new \RuntimeException('Insufficient card balance for this withdrawal.');
                }

                $transaction = Transaction::create([
                    'user_id' => $userId,
                    'transaction_id' => Transaction::generateTransactionId(),
                    'type' => 'card_withdrawal',
                    'category' => 'virtual_card',
                    'status' => 'pending',
                    'currency' => 'USD',
                    'amount' => $amountUsd,
                    'fee' => 0,
                    'total_amount' => $amountUsd,
                    'reference' => $reference,
                    'description' => $forTermination
                        ? 'Card termination balance withdrawal pending provider confirmation'
                        : 'Virtual card withdrawal pending provider confirmation',
                    'metadata' => [
                        'virtual_card_id' => $lockedCard->id,
                        'provider_card_id' => $lockedCard->provider_card_id,
                        'withdrawal_usd' => $amountUsd,
                        'exchange_rate_ngn_per_usd' => $exchangeRate,
                        'expected_refund_ngn' => $refundNgn,
                        'refund_currency' => 'NGN',
                        'settlement_status' => 'awaiting_webhook',
                        'initiated_for_termination' => $forTermination,
                        'card_scheme' => 'visa',
                        'pagocards_visa_api' => VirtualCardService::PAGOCARDS_VISA_API_493,
                    ],
                ]);

                VirtualCardTransaction::create([
                    'virtual_card_id' => $lockedCard->id,
                    'user_id' => $userId,
                    'transaction_id' => $transaction->id,
                    'provider_transaction_id' => $reference,
                    'type' => 'withdraw',
                    'status' => 'pending',
                    'currency' => 'USD',
                    'amount' => $amountUsd,
                    'fee' => 0,
                    'total_amount' => $amountUsd,
                    'payment_wallet_type' => 'naira_wallet',
                    'payment_wallet_currency' => 'NGN',
                    'exchange_rate' => $exchangeRate,
                    'reference' => $reference,
                    'description' => $forTermination
                        ? 'Card termination balance withdrawal (pending confirmation)'
                        : 'Card withdrawal to Naira wallet (pending confirmation)',
                    'metadata' => [
                        'expected_refund_ngn' => $refundNgn,
                        'withdrawal_quote' => $quote,
                        'initiated_for_termination' => $forTermination,
                    ],
                ]);

                return $transaction;
            });
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'status' => 422,
            ];
        }

        try {
            $response = $this->visa493BinApiClient->withdrawCard([
                'card_id' => (string) $card->provider_card_id,
                'amount' => $amountUsd,
            ]);
        } catch (MastercardApiException $exception) {
            $pending->update([
                'status' => 'failed',
                'description' => 'Virtual card withdrawal failed at provider',
                'metadata' => array_merge(is_array($pending->metadata) ? $pending->metadata : [], [
                    'settlement_status' => 'provider_failed',
                    'provider_error' => $exception->getMessage(),
                ]),
            ]);
            VirtualCardTransaction::where('transaction_id', $pending->id)->update(['status' => 'failed']);

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'status' => $exception->getHttpStatus(),
            ];
        }

        try {
            $details = $this->visa493BinApiClient->getCardDetails((string) $card->provider_card_id);
            $card->update([
                'balance' => $this->extractBalanceFromProvider($details, max(0, (float) $card->balance - $amountUsd)),
                'provider_payload' => $details,
            ]);
        } catch (MastercardApiException) {
            $card->update([
                'balance' => max(0, round((float) $card->balance - $amountUsd, 2)),
            ]);
        }

        $providerRef = (string) (data_get($response, 'data.reference')
            ?? data_get($response, 'data.transaction_id')
            ?? data_get($response, 'reference')
            ?? '');
        $pending->update([
            'metadata' => array_merge(is_array($pending->metadata) ? $pending->metadata : [], array_filter([
                'provider_reference' => $providerRef !== '' ? $providerRef : null,
                'provider_payload' => $response,
            ])),
        ]);

        return [
            'success' => true,
            'message' => $forTermination
                ? 'Card balance withdrawn. Your card will now be terminated and your Naira wallet will be credited after provider confirmation.'
                : 'Your withdrawal request has been processed. Your Naira wallet will be credited once the provider confirms the transaction.',
            'data' => [
                'card' => $card->fresh(),
                'withdrawal' => $quote,
                'transaction' => $pending->fresh(),
                'provider_response' => $response,
            ],
        ];
    }

    protected function completePendingWithdrawal(Transaction $pending, string $providerTransactionId, array $context): void
    {
        if ($pending->status !== 'pending') {
            return;
        }

        DB::transaction(function () use ($pending, $providerTransactionId, $context) {
            $locked = Transaction::where('id', $pending->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return;
            }

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            $refundNgn = round((float) ($meta['expected_refund_ngn'] ?? 0), 2);
            $withdrawalUsd = (float) ($meta['withdrawal_usd'] ?? 0);
            $exchangeRate = (float) ($meta['exchange_rate_ngn_per_usd'] ?? 0);
            $userId = (int) $locked->user_id;
            $virtualCardId = (int) ($meta['virtual_card_id'] ?? 0);

            if ($refundNgn > 0) {
                $wallet = FiatWallet::where('user_id', $userId)
                    ->where('currency', 'NGN')
                    ->where('country_code', 'NG')
                    ->lockForUpdate()
                    ->first();

                if (! $wallet) {
                    $wallet = $this->walletService->getFiatWallet($userId, 'NGN', 'NG');
                    $wallet = FiatWallet::where('id', $wallet->id)->lockForUpdate()->first();
                }

                $wallet->increment('balance', $refundNgn);
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'description' => 'Virtual card withdrawal: $'.number_format($withdrawalUsd, 2).' USD credited to Naira wallet',
                'metadata' => array_merge($meta, [
                    'settlement_status' => 'completed',
                    'refund_ngn' => $refundNgn,
                    'settled_at' => now()->toIso8601String(),
                    'settlement_context' => $context,
                ]),
            ]);

            VirtualCardTransaction::where('transaction_id', $locked->id)->update([
                'status' => 'completed',
                'provider_transaction_id' => $providerTransactionId,
                'description' => 'Card withdrawal credited to Naira wallet',
                'metadata' => array_merge(
                    (array) (VirtualCardTransaction::where('transaction_id', $locked->id)->value('metadata') ?? []),
                    ['refund_ngn' => $refundNgn, 'settlement_context' => $context]
                ),
            ]);

            $user = User::find($userId);
            if ($user && $refundNgn > 0) {
                NotificationHelper::createTransactionNotification(
                    $user,
                    'virtual_card',
                    'Card withdrawal credited',
                    '₦'.number_format($refundNgn, 2).' from your $'.number_format($withdrawalUsd, 2).' card withdrawal has been added to your Naira wallet.',
                    [
                        'action' => 'card_withdrawal_credited',
                        'virtual_card_id' => $virtualCardId,
                        'amount_usd' => $withdrawalUsd,
                        'amount_ngn' => $refundNgn,
                        'exchange_rate_ngn_per_usd' => $exchangeRate,
                    ]
                );
            }
        });
    }

    protected function findOldestPendingWithdrawal(int $virtualCardId, int $userId): ?Transaction
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'card_withdrawal')
            ->where('status', 'pending')
            ->where('metadata->virtual_card_id', $virtualCardId)
            ->orderBy('created_at')
            ->first();
    }

    protected function visaFundExchangeRateNgnPerUsd(): float
    {
        $r = $this->platformRates->findVirtualCard(VirtualCardService::PLATFORM_VISA_FUND);
        if ($r && $r->exchange_rate_ngn_per_usd !== null) {
            return max(0.0001, (float) $r->exchange_rate_ngn_per_usd);
        }

        return max(0.0001, (float) config('virtual_card.usd_to_ngn_rate', 1500.0));
    }

    protected function rateFromLastFundingTransaction(int $virtualCardId, int $userId): ?float
    {
        $tx = Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'card_funding')
            ->where('status', 'completed')
            ->where(function ($query) use ($virtualCardId) {
                $query->where('metadata->virtual_card_id', $virtualCardId)
                    ->orWhere('metadata->card_id', $virtualCardId);
            })
            ->orderByDesc('created_at')
            ->first();

        if (! $tx) {
            return null;
        }

        $meta = is_array($tx->metadata) ? $tx->metadata : [];
        $rate = (float) (
            $meta['exchange_rate_ngn_per_usd']
            ?? data_get($meta, 'wallet_charge.exchange_rate_ngn_per_usd')
            ?? 0
        );

        return $rate > 0 ? $rate : null;
    }

    protected function webhookLooksLikeAppUnload(string $eventName, array $eventData, float $expectedUsd): bool
    {
        $type = strtolower((string) ($eventData['transaction_type'] ?? $eventData['type'] ?? ''));
        $narrative = strtolower((string) ($eventData['narrative'] ?? $eventData['description'] ?? ''));

        if (in_array($type, ['withdraw', 'withdrawal', 'unload', 'card_withdrawal', 'unload_card', 'card_unload'], true)) {
            return true;
        }

        if (str_contains($type, 'withdraw') || str_contains($type, 'unload')) {
            return true;
        }

        if (str_contains($narrative, 'withdraw') || str_contains($narrative, 'unload')) {
            return true;
        }

        if ($eventName === PagocardsVirtualCardWebhookService::EVENT_TOPUP_COMPLETED) {
            $amount = $this->resolveWebhookUsdAmount($eventName, $eventData);

            return $amount > 0 && abs($amount - $expectedUsd) < 0.05;
        }

        return false;
    }

    protected function resolveWebhookUsdAmount(string $eventName, array $eventData): float
    {
        if (array_key_exists('display_amount', $eventData) && is_numeric($eventData['display_amount'])) {
            return abs((float) $eventData['display_amount']);
        }

        $raw = $eventData['chargedAmount'] ?? $eventData['amount'] ?? $eventData['merchantAmount'] ?? 0;
        if (! is_numeric($raw)) {
            return 0.0;
        }

        $amount = (float) $raw;

        return str_starts_with($eventName, 'virtualcard.') ? abs($amount / 1_000_000) : abs($amount);
    }

    /**
     * @return VirtualCard|array{success: false, message: string, status: int}
     */
    protected function resolveWithdrawableCard(int $userId, int $cardId): VirtualCard|array
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->first();
        if (! $card) {
            return [
                'success' => false,
                'message' => 'Virtual card not found.',
                'status' => 404,
            ];
        }

        if (! $this->isVisa493BinCard($card)) {
            return [
                'success' => false,
                'message' => 'Card withdrawal is only available for new Visa cards.',
                'status' => 422,
            ];
        }

        if (! $card->is_active) {
            return [
                'success' => false,
                'message' => 'This card is inactive.',
                'status' => 422,
            ];
        }

        if ($card->is_frozen) {
            return [
                'success' => false,
                'message' => 'Unfreeze this card before withdrawing.',
                'status' => 422,
            ];
        }

        if (! $card->provider_card_id) {
            return [
                'success' => false,
                'message' => 'This card is missing provider metadata.',
                'status' => 422,
            ];
        }

        return $card;
    }

    protected function isVisa493BinCard(VirtualCard $card): bool
    {
        if (strtolower((string) $card->card_type) !== 'visa') {
            return false;
        }

        $meta = is_array($card->metadata) ? $card->metadata : [];

        return ($meta['pagocards_visa_api'] ?? null) === VirtualCardService::PAGOCARDS_VISA_API_493;
    }

    protected function syncCardBalance(int $userId, VirtualCard $card): void
    {
        if (! $card->provider_card_id) {
            return;
        }

        try {
            $details = $this->visa493BinApiClient->getCardDetails((string) $card->provider_card_id);
            $card->update([
                'balance' => $this->extractBalanceFromProvider($details, (float) $card->balance),
                'provider_payload' => $details,
            ]);
        } catch (MastercardApiException) {
            // keep cached balance
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function extractBalanceFromProvider(array $response, float $fallback = 0): float
    {
        foreach (
            [
                data_get($response, 'data.balance.display_amount'),
                data_get($response, 'data.balance'),
                data_get($response, 'data.available_balance'),
                data_get($response, 'balance'),
            ] as $candidate
        ) {
            if (is_numeric($candidate)) {
                return round((float) $candidate, 2);
            }
        }

        return round($fallback, 2);
    }
}
