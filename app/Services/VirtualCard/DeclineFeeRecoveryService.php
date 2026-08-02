<?php

namespace App\Services\VirtualCard;

use App\Helpers\NotificationHelper;
use App\Jobs\CheckMerchantDeclineFeeJob;
use App\Models\CardDeclineFeeCharge;
use App\Models\FiatWallet;
use App\Models\PagocardsAdminSyncState;
use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Services\Platform\PlatformRateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeclineFeeRecoveryService
{
    public const PAYER_CARD = 'card';

    public const PAYER_MERCHANT = 'merchant';

    public const PAYER_PENDING = 'pending';

    public function __construct(
        protected PagocardsAdminApiClient $adminApi,
        protected VisaCardApiClient $visaCardApi,
        protected PlatformRateResolver $platformRates,
        protected VirtualCardService $virtualCardService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('virtual_card.decline_fee_recovery_enabled', true);
    }

    /**
     * @param  array<string, mixed>  $declineData
     */
    public function scheduleDeclineCheck(VirtualCard $card, array $declineData): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $delays = config('virtual_card.decline_fee_check_delays_minutes', [2, 10, 30]);
        if (! is_array($delays) || $delays === []) {
            $delays = [2, 10, 30];
        }

        $reference = trim((string) ($declineData['reference'] ?? ''));
        CheckMerchantDeclineFeeJob::dispatch(
            (int) $card->id,
            $reference !== '' ? $reference : null,
            0
        )->delay(now()->addMinutes((int) $delays[0]));
    }

    /**
     * @param  array<string, mixed>  $webhookData
     */
    public function handleDeclinedChargeWebhook(VirtualCard $card, array $webhookData, array $payload): string
    {
        if (! $this->isEnabled()) {
            return self::PAYER_CARD;
        }

        $payer = $this->resolveDeclineFeePayer($card, $webhookData);

        if ($payer === self::PAYER_CARD) {
            $this->recordUserCardDeclineFee($card, $webhookData, $payload);

            return self::PAYER_CARD;
        }

        if ($payer === self::PAYER_MERCHANT) {
            $this->processMerchantPaidFromWebhook($card, $webhookData);

            return self::PAYER_MERCHANT;
        }

        $this->scheduleDeclineCheck($card, $webhookData);

        return self::PAYER_PENDING;
    }

    /**
     * @param  array<string, mixed>  $webhookData
     */
    public function resolveDeclineFeePayer(VirtualCard $card, array $webhookData): string
    {
        if ($this->userCardPaidDeclineFee($card, $webhookData)) {
            return self::PAYER_CARD;
        }

        $adminTx = $this->findMatchingMerchantDebit($card, $webhookData);
        if ($adminTx !== null) {
            return self::PAYER_MERCHANT;
        }

        return self::PAYER_PENDING;
    }

    /**
     * @param  array<string, mixed>  $adminTx
     */
    public function processMerchantDeclineFeeDebit(
        array $adminTx,
        VirtualCard $card,
        string $detectionMethod = 'admin_api_poll',
        ?string $declinedReference = null,
    ): ?CardDeclineFeeCharge {
        if (! $this->isEnabled()) {
            return null;
        }

        $adminTxId = (int) ($adminTx['id'] ?? 0);
        if ($adminTxId <= 0) {
            return null;
        }

        $existing = CardDeclineFeeCharge::query()
            ->where('pagocards_admin_tx_id', $adminTxId)
            ->first();
        if ($existing) {
            return $existing;
        }

        if (! $this->shouldProcessAdminTx($adminTx)) {
            return null;
        }

        $providerCostUsd = round((float) ($adminTx['amount'] ?? 0), 4);
        $billableUsd = $this->resolveBillableUsd();
        $platformRate = $this->platformRates->findVirtualCard('visa_decline_fee');
        $exchangeRate = $this->resolveExchangeRateNgnPerUsd();
        $amountNgn = round($billableUsd * $exchangeRate, 2);

        return DB::transaction(function () use (
            $adminTx,
            $card,
            $adminTxId,
            $providerCostUsd,
            $billableUsd,
            $platformRate,
            $exchangeRate,
            $amountNgn,
            $detectionMethod,
            $declinedReference,
        ) {
            $subsidySequence = $this->nextMerchantSubsidySequence((int) $card->id);

            $wallet = FiatWallet::query()
                ->where('user_id', $card->user_id)
                ->where('currency', 'NGN')
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = FiatWallet::query()->create([
                    'user_id' => $card->user_id,
                    'currency' => 'NGN',
                    'country_code' => 'NG',
                    'balance' => 0,
                    'locked_balance' => 0,
                    'is_active' => true,
                ]);
                $wallet = FiatWallet::query()->where('id', $wallet->id)->lockForUpdate()->first();
            }

            $wallet->decrement('balance', $amountNgn);

            $reference = 'decline-fee-'.$adminTxId;
            $transaction = Transaction::query()->create([
                'user_id' => $card->user_id,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'card_decline_fee',
                'category' => 'virtual_card',
                'status' => 'completed',
                'currency' => 'NGN',
                'amount' => $amountNgn,
                'fee' => 0,
                'total_amount' => $amountNgn,
                'reference' => $reference,
                'description' => sprintf(
                    'Card decline fee ($%s → ₦%s)',
                    number_format($billableUsd, 2, '.', ''),
                    number_format($amountNgn, 2, '.', ',')
                ),
                'metadata' => [
                    'virtual_card_id' => $card->id,
                    'provider_card_id' => $card->provider_card_id,
                    'billable_usd' => $billableUsd,
                    'provider_cost_usd' => $providerCostUsd,
                    'exchange_rate_ngn_per_usd' => $exchangeRate,
                    'pagocards_admin_tx_id' => $adminTxId,
                    'declined_reference' => $declinedReference,
                    'card_subsidy_sequence' => $subsidySequence,
                ],
                'completed_at' => now(),
            ]);

            $charge = CardDeclineFeeCharge::query()->create([
                'user_id' => $card->user_id,
                'virtual_card_id' => $card->id,
                'pagocards_admin_tx_id' => $adminTxId,
                'pagocards_admin_tx_uuid' => $adminTx['uuid'] ?? null,
                'provider_card_id' => $card->provider_card_id,
                'declined_reference' => $declinedReference ?? $this->parseDeclinedReferenceFromAdminTx($adminTx),
                'provider_cost_usd' => $providerCostUsd,
                'billable_usd' => $billableUsd,
                'platform_rate_id' => $platformRate?->id,
                'exchange_rate_ngn_per_usd' => $exchangeRate,
                'amount_ngn' => $amountNgn,
                'funding_source' => CardDeclineFeeCharge::FUNDING_MERCHANT,
                'detection_method' => $detectionMethod,
                'recovery_status' => CardDeclineFeeCharge::STATUS_CHARGED,
                'naira_transaction_id' => $transaction->id,
                'card_subsidy_sequence' => $subsidySequence,
                'metadata' => [
                    'admin_tx' => $adminTx,
                ],
            ]);

            $this->maybeAutoFreezeCard($card, $subsidySequence);

            $user = User::query()->find($card->user_id);
            if ($user) {
                NotificationHelper::createTransactionNotification(
                    $user,
                    'card_decline_fee',
                    'Decline fee charged',
                    sprintf(
                        '$%s decline fee charged to your Naira wallet (₦%s).',
                        number_format($billableUsd, 2, '.', ''),
                        number_format($amountNgn, 2, '.', ',')
                    ),
                    [
                        'virtual_card_id' => $card->id,
                        'billable_usd' => $billableUsd,
                        'amount_ngn' => $amountNgn,
                        'card_decline_fee_charge_id' => $charge->id,
                        'transaction_id' => $transaction->transaction_id,
                    ]
                );
            }

            return $charge;
        });
    }

    /**
     * @param  array<string, mixed>  $webhookData
     * @param  array<string, mixed>  $payload
     */
    public function recordUserCardDeclineFee(VirtualCard $card, array $webhookData, array $payload): ?CardDeclineFeeCharge
    {
        $reference = trim((string) ($webhookData['reference'] ?? ''));
        $providerEventId = trim((string) ($payload['event_id'] ?? $payload['eventId'] ?? ''));

        $existing = CardDeclineFeeCharge::query()
            ->where('virtual_card_id', $card->id)
            ->where('funding_source', CardDeclineFeeCharge::FUNDING_CARD)
            ->when($reference !== '', fn ($q) => $q->where('declined_reference', $reference))
            ->when($providerEventId !== '', fn ($q) => $q->where('metadata->provider_event_id', $providerEventId))
            ->first();

        if ($existing) {
            return $existing;
        }

        $feeUsd = $this->webhookFeeUsd($webhookData);

        return CardDeclineFeeCharge::query()->create([
            'user_id' => $card->user_id,
            'virtual_card_id' => $card->id,
            'provider_card_id' => $card->provider_card_id,
            'declined_reference' => $reference !== '' ? $reference : null,
            'provider_cost_usd' => $feeUsd,
            'billable_usd' => $feeUsd,
            'exchange_rate_ngn_per_usd' => 0,
            'amount_ngn' => 0,
            'funding_source' => CardDeclineFeeCharge::FUNDING_CARD,
            'detection_method' => 'declined_charge_webhook',
            'recovery_status' => CardDeclineFeeCharge::STATUS_RECOVERED,
            'metadata' => [
                'provider_event_id' => $providerEventId,
                'webhook' => $webhookData,
            ],
        ]);
    }

    public function pollAndProcessForCard(VirtualCard $card, ?string $declinedReference = null): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $watermark = PagocardsAdminSyncState::watermarkFor(PagocardsAdminSyncState::KEY_DECLINE_FEE_VISA);
        $debits = $this->adminApi->findDeclineFeeDebitsSince(
            $watermark,
            (string) $card->provider_card_id
        );

        $processed = 0;
        $maxId = $watermark;

        foreach ($debits as $tx) {
            $id = (int) ($tx['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }

            $charge = $this->processMerchantDeclineFeeDebit(
                $tx,
                $card,
                'admin_api_poll',
                $declinedReference
            );
            if ($charge) {
                $processed++;
            }
        }

        if ($maxId > $watermark) {
            PagocardsAdminSyncState::advanceWatermark(PagocardsAdminSyncState::KEY_DECLINE_FEE_VISA, $maxId);
        }

        return $processed;
    }

    public function reconcileAll(): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $watermark = PagocardsAdminSyncState::watermarkFor(PagocardsAdminSyncState::KEY_DECLINE_FEE_VISA);
        $debits = $this->adminApi->findDeclineFeeDebitsSince($watermark);
        $processed = 0;
        $maxId = $watermark;

        foreach ($debits as $tx) {
            $id = (int) ($tx['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }

            $cardId = $this->adminApi->parseDeclineFeeCardId((string) ($tx['description'] ?? ''));
            if ($cardId === null) {
                continue;
            }

            $card = VirtualCard::query()->where('provider_card_id', $cardId)->first();
            if (! $card) {
                continue;
            }

            $charge = $this->processMerchantDeclineFeeDebit($tx, $card, 'admin_api_reconcile');
            if ($charge) {
                $processed++;
            }
        }

        if ($maxId > $watermark) {
            PagocardsAdminSyncState::advanceWatermark(PagocardsAdminSyncState::KEY_DECLINE_FEE_VISA, $maxId);
        }

        return $processed;
    }

    /**
     * After a deposit credits the wallet, mark outstanding decline-fee debt as recovered.
     */
    public function handlePostDepositRecovery(int $userId, float $balanceBefore, float $balanceAfter): void
    {
        if ($balanceBefore >= 0 || $balanceAfter < 0) {
            return;
        }

        $outstanding = CardDeclineFeeCharge::query()
            ->where('user_id', $userId)
            ->where('funding_source', CardDeclineFeeCharge::FUNDING_MERCHANT)
            ->where('recovery_status', CardDeclineFeeCharge::STATUS_CHARGED)
            ->orderBy('id')
            ->get();

        if ($outstanding->isEmpty()) {
            return;
        }

        $totalOutstandingNgn = (float) $outstanding->sum('amount_ngn');
        $recoveredAmount = min($totalOutstandingNgn, abs(min(0.0, $balanceBefore)));

        if ($recoveredAmount <= 0) {
            return;
        }

        CardDeclineFeeCharge::query()
            ->whereIn('id', $outstanding->pluck('id'))
            ->update([
                'recovery_status' => CardDeclineFeeCharge::STATUS_RECOVERED,
                'recovered_at' => now(),
            ]);

        $user = User::query()->find($userId);
        if ($user) {
            NotificationHelper::createTransactionNotification(
                $user,
                'card_decline_fee_recovery',
                'Decline fee balance cleared',
                sprintf(
                    '₦%s of your deposit covered outstanding card decline fees.',
                    number_format($recoveredAmount, 2, '.', ',')
                ),
                [
                    'recovered_amount_ngn' => $recoveredAmount,
                    'charge_ids' => $outstanding->pluck('id')->all(),
                ]
            );
        }
    }

    /**
     * Waive a merchant-paid charge and credit the user's Naira wallet back.
     */
    public function waiveCharge(CardDeclineFeeCharge $charge, ?string $reason = null): CardDeclineFeeCharge
    {
        if ($charge->funding_source !== CardDeclineFeeCharge::FUNDING_MERCHANT) {
            throw new \InvalidArgumentException('Only merchant-paid charges can be waived.');
        }

        if ($charge->recovery_status === CardDeclineFeeCharge::STATUS_WAIVED) {
            return $charge;
        }

        return DB::transaction(function () use ($charge, $reason) {
            $locked = CardDeclineFeeCharge::query()->where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->recovery_status === CardDeclineFeeCharge::STATUS_WAIVED) {
                return $locked;
            }

            if ($locked->recovery_status === CardDeclineFeeCharge::STATUS_CHARGED) {
                $wallet = FiatWallet::query()
                    ->where('user_id', $locked->user_id)
                    ->where('currency', 'NGN')
                    ->where('country_code', 'NG')
                    ->lockForUpdate()
                    ->first();

                if ($wallet) {
                    $wallet->increment('balance', (float) $locked->amount_ngn);
                }

                Transaction::query()->create([
                    'user_id' => $locked->user_id,
                    'transaction_id' => Transaction::generateTransactionId(),
                    'type' => 'admin_credit',
                    'category' => 'adjustment',
                    'status' => 'completed',
                    'currency' => 'NGN',
                    'amount' => $locked->amount_ngn,
                    'fee' => 0,
                    'total_amount' => $locked->amount_ngn,
                    'reference' => 'decline-fee-waive-'.$locked->id,
                    'description' => 'Decline fee waived (historical adjustment)',
                    'metadata' => [
                        'card_decline_fee_charge_id' => $locked->id,
                        'waive_reason' => $reason,
                        'original_naira_transaction_id' => $locked->naira_transaction_id,
                    ],
                    'completed_at' => now(),
                ]);
            }

            $locked->update([
                'recovery_status' => CardDeclineFeeCharge::STATUS_WAIVED,
                'recovered_at' => now(),
                'metadata' => array_merge($locked->metadata ?? [], [
                    'waived_at' => now()->toIso8601String(),
                    'waive_reason' => $reason,
                ]),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $adminTx
     */
    protected function shouldProcessAdminTx(array $adminTx): bool
    {
        $cutoff = trim((string) config('virtual_card.decline_fee_ignore_admin_before', ''));
        if ($cutoff === '') {
            return true;
        }

        $createdAt = trim((string) ($adminTx['created_at'] ?? ''));
        if ($createdAt === '') {
            return true;
        }

        try {
            return now()->parse($createdAt)->gte(now()->parse($cutoff));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $webhookData
     */
    protected function processMerchantPaidFromWebhook(VirtualCard $card, array $webhookData): void
    {
        $adminTx = $this->findMatchingMerchantDebit($card, $webhookData);
        $reference = trim((string) ($webhookData['reference'] ?? ''));

        if ($adminTx !== null) {
            $this->processMerchantDeclineFeeDebit(
                $adminTx,
                $card,
                'declined_charge_webhook',
                $reference !== '' ? $reference : null
            );

            return;
        }

        $this->scheduleDeclineCheck($card, $webhookData);
    }

    /**
     * @param  array<string, mixed>  $webhookData
     */
    protected function userCardPaidDeclineFee(VirtualCard $card, array $webhookData): bool
    {
        $user = User::query()->find($card->user_id);
        if (! $user) {
            return false;
        }

        try {
            $response = $this->visaCardApi->getCardDetails([
                'email' => (string) $user->email,
                'cardid' => (string) $card->provider_card_id,
            ]);
        } catch (MastercardApiException $e) {
            Log::warning('decline_fee_recovery.getcard_failed', [
                'virtual_card_id' => $card->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $transactions = $response['data']['transactions'] ?? $response['transactions'] ?? [];
        if (! is_array($transactions)) {
            return false;
        }

        $windowStart = now()->subHours(2);
        $reference = trim((string) ($webhookData['reference'] ?? ''));

        foreach ($transactions as $tx) {
            if (! is_array($tx)) {
                continue;
            }
            $type = strtolower((string) ($tx['type'] ?? ''));
            $description = (string) ($tx['description'] ?? '');
            $txReference = (string) ($tx['reference'] ?? '');
            $status = strtolower((string) ($tx['status'] ?? ''));

            if ($type !== 'withdrawal' || $status !== 'completed') {
                continue;
            }
            if (stripos($description, 'decline fee') === false && ! str_starts_with(strtolower($txReference), 'declinecharge-')) {
                continue;
            }

            $createdAt = $tx['created_at'] ?? $tx['transaction_date'] ?? null;
            if ($createdAt) {
                try {
                    if (now()->parse($createdAt)->lt($windowStart)) {
                        continue;
                    }
                } catch (\Throwable) {
                    // keep row if date parse fails
                }
            }

            if ($reference !== '' && $txReference !== '' && ! str_contains(strtolower($txReference), strtolower($reference))) {
                // decline fee refs are declinecharge-*; auth ref is CARD_AUTH_* — time window is enough
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $webhookData
     * @return array<string, mixed>|null
     */
    protected function findMatchingMerchantDebit(VirtualCard $card, array $webhookData): ?array
    {
        $watermark = max(0, PagocardsAdminSyncState::watermarkFor(PagocardsAdminSyncState::KEY_DECLINE_FEE_VISA) - 500);
        $debits = $this->adminApi->findDeclineFeeDebitsSince(
            $watermark,
            (string) $card->provider_card_id
        );

        if ($debits === []) {
            return null;
        }

        $reference = trim((string) ($webhookData['reference'] ?? ''));
        if ($reference !== '') {
            foreach ($debits as $tx) {
                $metaRef = (string) data_get($tx, 'metadata.reference', '');
                if ($metaRef === $reference) {
                    return $tx;
                }
            }
        }

        return $debits[array_key_last($debits)] ?? null;
    }

    protected function resolveBillableUsd(): float
    {
        /** @var PlatformRate|null $rate */
        $rate = $this->platformRates->findVirtualCard('visa_decline_fee');
        if ($rate && $rate->fee_usd !== null) {
            return max(0.0, round((float) $rate->fee_usd, 4));
        }

        return max(0.0, (float) config('virtual_card.decline_fee_billable_usd_fallback', 1.0));
    }

    protected function resolveExchangeRateNgnPerUsd(): float
    {
        $fundRate = $this->platformRates->findVirtualCard('visa_fund');
        if ($fundRate && $fundRate->exchange_rate_ngn_per_usd !== null) {
            return max(0.0001, (float) $fundRate->exchange_rate_ngn_per_usd);
        }

        return max(0.0001, (float) config('virtual_card.usd_to_ngn_rate', 1500.0));
    }

    protected function nextMerchantSubsidySequence(int $virtualCardId): int
    {
        $count = CardDeclineFeeCharge::query()
            ->where('virtual_card_id', $virtualCardId)
            ->where('funding_source', CardDeclineFeeCharge::FUNDING_MERCHANT)
            ->count();

        return $count + 1;
    }

    protected function maybeAutoFreezeCard(VirtualCard $card, int $subsidySequence): void
    {
        if (! (bool) config('virtual_card.auto_freeze_after_max_subsidies', true)) {
            return;
        }

        $max = (int) config('virtual_card.max_merchant_paid_decline_fees', 3);
        if ($subsidySequence < $max) {
            return;
        }

        $result = $this->virtualCardService->toggleFreeze((int) $card->user_id, (int) $card->id, true);
        if (($result['success'] ?? false) === true) {
            $user = User::query()->find($card->user_id);
            if ($user) {
                NotificationHelper::create(
                    (int) $user->id,
                    'virtual_card',
                    'Card frozen',
                    'Your card was frozen after repeated decline fees paid on your behalf.',
                    [
                        'kind' => 'card_frozen_decline_fees',
                        'virtual_card_id' => $card->id,
                        'subsidy_sequence' => $subsidySequence,
                    ]
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $webhookData
     */
    protected function webhookFeeUsd(array $webhookData): float
    {
        if (array_key_exists('display_amount', $webhookData) && is_numeric($webhookData['display_amount'])) {
            return round((float) $webhookData['display_amount'], 4);
        }

        $raw = $webhookData['feeAmount'] ?? 0;
        if (! is_numeric($raw)) {
            return 0.0;
        }

        return round((float) $raw / 1_000_000, 4);
    }

    /**
     * @param  array<string, mixed>  $adminTx
     */
    protected function parseDeclinedReferenceFromAdminTx(array $adminTx): ?string
    {
        $ref = data_get($adminTx, 'metadata.reference');

        return is_string($ref) && $ref !== '' ? $ref : null;
    }
}
