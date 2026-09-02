<?php

namespace App\Services\VirtualCard;

use App\Helpers\NotificationHelper;
use App\Jobs\RefreshVirtualCardFromProviderJob;
use App\Models\PagocardsRawWebhook;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardProviderWebhookEvent;
use App\Models\VirtualCardTransaction;
use App\Services\VirtualCard\DeclineFeeRecoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PagocardsVirtualCardWebhookService
{
    public function __construct(
        protected DeclineFeeRecoveryService $declineFeeRecovery,
        protected VirtualCard493WithdrawalService $visa493Withdrawal,
    ) {}

    public const EVENT_TOKENIZATION = 'cardTokenization.deliverActivationCode';

    public const EVENT_3DS_CREATED = 'cardAuthentication.created';

    public const EVENT_AUTHORIZATION = 'virtualcard.transaction.authorization';

    public const EVENT_SETTLEMENT = 'virtualcard.transaction.settlement';

    public const EVENT_VERIFICATION = 'virtualcard.transaction.verification';

    public const EVENT_DECLINED = 'virtualcard.transaction.declined';

    public const EVENT_DECLINED_CHARGE = 'virtualcard.transaction.declined.charge';

    public const EVENT_CROSS_BORDER = 'virtualcard.transaction.crossborder';

    public const EVENT_TOPUP_COMPLETED = 'virtualcard.topup.completed';

    public const EVENT_AUTHORIZATION_FEE = 'virtualcard.authorization.fee';

    public const EVENT_REFUND = 'virtualcard.transaction.refund';

    public const EVENT_TRANSACTION_FEE = 'virtualcard.transaction.fee';

    /** 493 BIN app-initiated card unload result (`eventType` on v1 cards webhooks). */
    public const EVENT_CARD_WITHDRAW_RESULT = 'cardWithdrawResult';

    public const EVENT_CASH_WITHDRAWAL = 'virtualcard.transaction.cash_withdrawal';

    public const EVENT_CARD_APPLICATION = 'virtualcard.transaction.card_application';

    public const EVENT_CANCELLATION = 'virtualcard.transaction.cancellation';

    /** 493 BIN 3DS payloads use `eventType` instead of `event` / `eventName`. */
    public const EVENT_3DS_493 = '3ds';

    private const LEGACY_AUTHORIZATION_CONFIRMED = 'cardAuthorization.confirmed';

    private const LEGACY_AUTHORIZATION_REJECTED = 'cardAuthorization.rejected';

    private const SUPPORTED_EVENTS = [
        self::EVENT_3DS_CREATED,
        self::EVENT_TOKENIZATION,
        self::EVENT_AUTHORIZATION,
        self::EVENT_SETTLEMENT,
        self::EVENT_VERIFICATION,
        self::EVENT_DECLINED,
        self::EVENT_DECLINED_CHARGE,
        self::EVENT_CROSS_BORDER,
        self::EVENT_TOPUP_COMPLETED,
        self::EVENT_AUTHORIZATION_FEE,
        self::EVENT_REFUND,
        self::EVENT_TRANSACTION_FEE,
        self::EVENT_CASH_WITHDRAWAL,
        self::EVENT_CARD_APPLICATION,
        self::EVENT_CANCELLATION,
        self::EVENT_CARD_WITHDRAW_RESULT,
        self::LEGACY_AUTHORIZATION_CONFIRMED,
        self::LEGACY_AUTHORIZATION_REJECTED,
    ];

    public function handle(Request $request): array
    {
        $rawId = null;
        try {
            $raw = PagocardsRawWebhook::query()->create([
                'raw_data' => json_encode($request->all()) ?: '{}',
                'headers' => $request->headers->all(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'processed' => false,
            ]);
            $rawId = $raw->id;
        } catch (\Throwable $e) {
            Log::error('pagocards_webhook.raw_save_failed', ['error' => $e->getMessage()]);
        }

        try {
            $payload = $request->all();
            $parsed = $this->parseIncomingWebhook($payload);
            $externalEventId = $parsed['event_id'];
            if ($externalEventId === '') {
                $this->markRaw($rawId, 'Missing event_id/eventId');

                return ['success' => true, 'message' => 'Ignored: missing event id'];
            }

            if (VirtualCardProviderWebhookEvent::query()->where('external_event_id', $externalEventId)->exists()) {
                $this->markRaw($rawId, null);

                return ['success' => true, 'message' => 'Duplicate event ignored', 'duplicate' => true];
            }

            $eventName = $parsed['event_name'];
            if ($eventName === '' || ! in_array($eventName, self::SUPPORTED_EVENTS, true)) {
                $this->markRaw($rawId, 'Unknown event/eventName: '.$eventName);

                return ['success' => true, 'message' => 'Ignored: unknown event'];
            }

            $eventData = $parsed['event_data'];
            $payload = $parsed['stored_payload'];
            $pagocardsCardId = $parsed['card_id'];
            if ($pagocardsCardId === '') {
                $this->markRaw($rawId, 'Missing cardId');

                return ['success' => true, 'message' => 'Ignored: missing cardId'];
            }

            $card = VirtualCard::query()->where('provider_card_id', $pagocardsCardId)->first();
            if (! $card) {
                $this->markRaw($rawId, 'Unknown cardId');

                return ['success' => true, 'message' => 'Ignored: unknown card'];
            }

            $pagocardsUserId = isset($eventData['userId']) ? (string) $eventData['userId'] : null;
            $eventTargetId = trim((string) (
                $eventData['id']
                ?? $eventData['eventTargetId']
                ?? $eventData['reference']
                ?? ''
            )) ?: null;
            $duplicateTarget = $eventTargetId !== null
                && VirtualCardProviderWebhookEvent::query()
                    ->where('event_name', $eventName)
                    ->where('event_target_id', $eventTargetId)
                    ->where('virtual_card_id', $card->id)
                    ->exists();
            $isActionable = in_array($eventName, [self::EVENT_3DS_CREATED, self::EVENT_TOKENIZATION], true);

            $event = VirtualCardProviderWebhookEvent::query()->create([
                'external_event_id' => $externalEventId,
                'event_name' => $eventName,
                'event_target_id' => $eventTargetId,
                'pagocards_card_id' => $pagocardsCardId,
                'pagocards_user_id' => $pagocardsUserId,
                'virtual_card_id' => $card->id,
                'user_id' => $card->user_id,
                'status' => $isActionable
                    ? VirtualCardProviderWebhookEvent::STATUS_PENDING
                    : VirtualCardProviderWebhookEvent::STATUS_COMPLETED,
                'payload' => $payload,
                'processed_at' => $isActionable ? null : now(),
            ]);

            if (! $duplicateTarget) {
                $this->syncFinancialEvent($card, $eventName, $eventData, $payload, $externalEventId);
                $this->visa493Withdrawal->trySettleFromWebhook($card, $eventName, $eventData, $externalEventId);
            }

            if (! $duplicateTarget && $eventName === self::EVENT_DECLINED) {
                $this->declineFeeRecovery->scheduleDeclineCheck($card, $eventData);
            }

            if (! $duplicateTarget && $card->user_id) {
                $user = User::query()->find($card->user_id);
                if ($user && $eventName !== self::EVENT_DECLINED_CHARGE) {
                    $this->notifyUser($user, $eventName, $eventData, $card->id, $externalEventId);
                }
            }

            if (! $duplicateTarget && $this->eventCanChangeBalance($eventName)) {
                RefreshVirtualCardFromProviderJob::dispatch((int) $card->user_id, (int) $card->id);
            }

            $this->markRaw($rawId, null);

            return [
                'success' => true,
                'message' => $duplicateTarget
                    ? 'Webhook recorded; duplicate transaction side effects ignored'
                    : 'Webhook processed',
                'event_db_id' => $event->id,
                'duplicate_transaction' => $duplicateTarget,
            ];
        } catch (\Throwable $e) {
            Log::error('pagocards_webhook.process_failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->markRaw($rawId, $e->getMessage());

            return ['success' => false, 'message' => 'Processing error'];
        }
    }

    /**
     * New Pagocards events wrap their fields in `data`; legacy events are flat.
     * 493 BIN 3DS uses `eventType` + top-level `cardid` (see Pagocards v1 cards webhooks).
     *
     * @return array<string, mixed>
     */
    private function eventData(array $payload): array
    {
        return isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : $payload;
    }

    /**
     * Normalize Mastercard legacy, virtualcard.*, and 493 BIN 3DS webhook shapes.
     *
     * @return array{
     *     event_name: string,
     *     event_id: string,
     *     card_id: string,
     *     event_data: array<string, mixed>,
     *     stored_payload: array<string, mixed>
     * }
     */
    private function parseIncomingWebhook(array $payload): array
    {
        $eventType = strtolower(trim((string) ($payload['eventType'] ?? '')));
        if ($eventType === strtolower(self::EVENT_CARD_WITHDRAW_RESULT)) {
            $cardId = trim((string) ($payload['cardid'] ?? $payload['cardId'] ?? ''));
            $eventId = trim((string) ($payload['eventId'] ?? $payload['orderId'] ?? ''));
            if ($eventId === '') {
                $eventId = 'card-withdraw-'.hash('sha256', json_encode($payload) ?: uniqid('', true));
            }

            $normalized = array_merge($payload, [
                'eventName' => self::EVENT_CARD_WITHDRAW_RESULT,
                'eventId' => $eventId,
                'cardId' => $cardId,
                'pagocards_visa_api' => 'v1_493',
            ]);

            return [
                'event_name' => self::EVENT_CARD_WITHDRAW_RESULT,
                'event_id' => $eventId,
                'card_id' => $cardId,
                'event_data' => $normalized,
                'stored_payload' => $normalized,
            ];
        }

        if ($eventType === self::EVENT_3DS_493) {
            $cardId = trim((string) ($payload['cardid'] ?? $payload['cardId'] ?? ''));
            $authId = trim((string) ($payload['authId'] ?? ''));
            $otp = trim((string) ($payload['otp'] ?? ''));
            $merchant = trim((string) ($payload['merchantName'] ?? 'Merchant'));
            $amount = trim((string) ($payload['transactionAmount'] ?? ''));
            $currency = trim((string) ($payload['transactionCurrency'] ?? 'USD'));

            $normalized = array_merge($payload, [
                'eventName' => self::EVENT_3DS_CREATED,
                'eventId' => $payload['eventId'] ?? '',
                'cardId' => $cardId,
                'eventTargetId' => $authId,
                'merchantName' => $merchant,
                'merchantAmount' => $amount,
                'merchantCurrency' => $currency,
                'otp' => $otp,
                'verificationType' => $payload['verificationType'] ?? null,
                'pagocards_visa_api' => 'v1_493',
            ]);

            return [
                'event_name' => self::EVENT_3DS_CREATED,
                'event_id' => trim((string) ($payload['eventId'] ?? '')),
                'card_id' => $cardId,
                'event_data' => $normalized,
                'stored_payload' => $normalized,
            ];
        }

        $eventData = $this->eventData($payload);
        $eventName = trim((string) ($payload['event'] ?? $payload['eventName'] ?? ''));
        $cardId = trim((string) (
            $payload['cardid']
            ?? $payload['cardId']
            ?? $eventData['cardId']
            ?? $eventData['card_id']
            ?? ''
        ));

        return [
            'event_name' => $eventName,
            'event_id' => trim((string) ($payload['event_id'] ?? $payload['eventId'] ?? '')),
            'card_id' => $cardId,
            'event_data' => $eventData,
            'stored_payload' => $payload,
        ];
    }

    protected function notifyUser(
        User $user,
        string $eventName,
        array $data,
        int $virtualCardId,
        string $externalEventId
    ): void {
        if ($eventName === self::EVENT_3DS_CREATED) {
            $merchant = (string) ($data['merchantName'] ?? 'Merchant');
            $amount = (string) ($data['merchantAmount'] ?? $data['transactionAmount'] ?? '');
            $currency = (string) ($data['merchantCurrency'] ?? $data['transactionCurrency'] ?? '');
            $otp = trim((string) ($data['otp'] ?? ''));
            $title = $otp !== '' ? 'Card verification code' : 'Card security approval needed';
            $message = $otp !== ''
                ? "Use code {$otp} to verify your payment to {$merchant} in Apple Pay or Google Pay."
                : ($amount !== '' && $currency !== ''
                    ? "Approve payment to {$merchant} for {$amount} {$currency}."
                    : "Approve a payment to {$merchant}.");

            NotificationHelper::create(
                $user->id,
                'virtual_card',
                $title,
                $message,
                [
                    'kind' => 'pagocards_3ds',
                    'virtual_card_id' => $virtualCardId,
                    'provider_event_id' => $externalEventId,
                    'event_target_id' => $data['eventTargetId'] ?? $data['authId'] ?? null,
                    'merchant_name' => $data['merchantName'] ?? null,
                    'merchant_amount' => $data['merchantAmount'] ?? $data['transactionAmount'] ?? null,
                    'merchant_currency' => $data['merchantCurrency'] ?? $data['transactionCurrency'] ?? null,
                    'masked_pan' => $data['maskedPan'] ?? null,
                    'otp' => $otp !== '' ? $otp : null,
                    'verification_type' => $data['verificationType'] ?? null,
                ]
            );

            return;
        }

        if ($eventName === self::EVENT_TOKENIZATION) {
            $wallet = (string) ($data['digitalWalletName'] ?? 'wallet');
            $title = 'Wallet activation code';
            $message = "Open the app to view your {$wallet} activation code for your card.";

            NotificationHelper::create(
                $user->id,
                'virtual_card',
                $title,
                $message,
                [
                    'kind' => 'pagocards_wallet_tokenization',
                    'virtual_card_id' => $virtualCardId,
                    'provider_event_id' => $externalEventId,
                    'digital_wallet_name' => $data['digitalWalletName'] ?? null,
                    'activation_method' => $data['activationMethod'] ?? null,
                    'event_target_id' => $data['eventTargetId'] ?? null,
                ]
            );

            return;
        }

        $merchant = trim((string) ($data['merchant_name'] ?? $data['merchantName'] ?? 'Merchant'));
        $currency = strtoupper((string) ($data['currency'] ?? $data['merchantCurrency'] ?? 'USD'));
        $amount = $this->eventAmount($eventName, $data);
        $amountDisplay = $this->money($amount, $currency);
        $reason = trim((string) ($data['reason'] ?? $data['declineReasonCode'] ?? ''));

        [$title, $message, $kind] = match ($eventName) {
            self::EVENT_AUTHORIZATION, self::LEGACY_AUTHORIZATION_CONFIRMED => [
                'Card payment authorized',
                "{$amountDisplay} at {$merchant} was authorized.",
                'pagocards_authorization',
            ],
            self::EVENT_SETTLEMENT => [
                'Card payment completed',
                "{$amountDisplay} payment to {$merchant} was completed.",
                'pagocards_settlement',
            ],
            self::EVENT_VERIFICATION => [
                'Card verification successful',
                "Your card was verified successfully by {$merchant}.",
                'pagocards_verification',
            ],
            self::EVENT_DECLINED, self::LEGACY_AUTHORIZATION_REJECTED => [
                'Card payment declined',
                "{$amountDisplay} payment to {$merchant} was declined".($reason !== '' ? ": {$reason}." : '.'),
                'pagocards_declined',
            ],
            self::EVENT_DECLINED_CHARGE => [
                'Declined transaction fee charged',
                "{$amountDisplay} was charged after repeated declined card transactions.",
                'pagocards_decline_charge',
            ],
            self::EVENT_CROSS_BORDER => [
                'Cross-border card fee charged',
                "{$amountDisplay} cross-border fee was charged for your payment to {$merchant}.",
                'pagocards_cross_border_fee',
            ],
            self::EVENT_TOPUP_COMPLETED => [
                'Virtual card funded',
                "{$amountDisplay} was added to your virtual card.",
                'pagocards_topup_completed',
            ],
            self::EVENT_AUTHORIZATION_FEE => [
                'Authorization fee charged',
                "{$amountDisplay} authorization fee was charged for your payment to {$merchant}.",
                'pagocards_authorization_fee',
            ],
            self::EVENT_REFUND => [
                'Card refund received',
                "{$amountDisplay} was refunded to your card from {$merchant}.",
                'pagocards_refund',
            ],
            self::EVENT_TRANSACTION_FEE => [
                'Card transaction fee charged',
                "{$amountDisplay} transaction fee was charged for your payment to {$merchant}.",
                'pagocards_transaction_fee',
            ],
            self::EVENT_CASH_WITHDRAWAL => [
                'ATM withdrawal completed',
                "{$amountDisplay} was withdrawn from your card at {$merchant}.",
                'pagocards_cash_withdrawal',
            ],
            self::EVENT_CARD_APPLICATION => [
                'Card application processed',
                'Your virtual card application was processed by the provider.',
                'pagocards_card_application',
            ],
            self::EVENT_CANCELLATION => [
                'Card transaction cancelled',
                "A {$amountDisplay} transaction at {$merchant} was cancelled.",
                'pagocards_cancellation',
            ],
            default => ['', '', ''],
        };

        if ($title === '') {
            return;
        }

        NotificationHelper::create(
            (int) $user->id,
            'virtual_card',
            $title,
            $message,
            [
                'kind' => $kind,
                'virtual_card_id' => $virtualCardId,
                'provider_event_id' => $externalEventId,
                'event_target_id' => $data['id'] ?? $data['eventTargetId'] ?? $data['reference'] ?? null,
                'reference' => $data['reference'] ?? null,
                'merchant_name' => $merchant !== 'Merchant' ? $merchant : null,
                'amount' => $amount,
                'currency' => $currency,
                'reason' => $reason !== '' ? $reason : null,
                'transaction_type' => $data['transaction_type'] ?? null,
            ]
        );
    }

    private function syncFinancialEvent(VirtualCard $card, string $eventName, array $data, array $payload, string $externalEventId): void
    {
        if ($eventName === self::EVENT_DECLINED_CHARGE) {
            $payer = $this->declineFeeRecovery->handleDeclinedChargeWebhook($card, $data, $payload);
            if ($payer !== DeclineFeeRecoveryService::PAYER_CARD) {
                return;
            }
        }

        $definition = match ($eventName) {
            self::EVENT_SETTLEMENT => ['payment', 'completed', 'Card payment settlement'],
            self::EVENT_DECLINED, self::LEGACY_AUTHORIZATION_REJECTED => ['payment', 'failed', 'Declined card payment'],
            self::EVENT_DECLINED_CHARGE => ['fee', 'completed', 'Declined transaction fee'],
            self::EVENT_CROSS_BORDER => ['fee', 'completed', 'Cross-border card fee'],
            self::EVENT_TOPUP_COMPLETED => ['fund', 'completed', 'Virtual card top-up'],
            self::EVENT_AUTHORIZATION_FEE => ['fee', 'completed', 'Authorization fee'],
            self::EVENT_REFUND => ['refund', 'completed', 'Card refund'],
            self::EVENT_TRANSACTION_FEE => ['fee', 'completed', 'Card transaction fee'],
            self::EVENT_CASH_WITHDRAWAL => ['payment', 'completed', 'ATM cash withdrawal'],
            self::EVENT_CANCELLATION => ['payment', 'failed', 'Cancelled card transaction'],
            default => null,
        };

        if ($definition === null) {
            return;
        }

        if ($eventName === self::EVENT_TOPUP_COMPLETED) {
            $meta = is_array($card->metadata) ? $card->metadata : [];
            $stripped = (float) ($meta['stripped_unexpected_initial_load_usd'] ?? 0);
            $amount = $this->eventAmount($eventName, $data);
            if ($stripped > 0 && abs($amount - $stripped) < 0.02) {
                return;
            }
        }

        [$type, $status, $defaultDescription] = $definition;
        $providerId = trim((string) ($data['id'] ?? $data['eventTargetId'] ?? $data['reference'] ?? ''));
        if ($providerId === '') {
            $providerId = 'webhook_'.hash('sha256', json_encode($payload) ?: uniqid('', true));
        }
        if (in_array($eventName, [self::EVENT_DECLINED_CHARGE, self::EVENT_CROSS_BORDER, self::EVENT_AUTHORIZATION_FEE, self::EVENT_TRANSACTION_FEE], true)) {
            $providerId .= ':'.$type.':'.$this->eventSlug($eventName);
        }

        $reference = trim((string) ($data['reference'] ?? $providerId));
        if (in_array($eventName, [self::EVENT_DECLINED_CHARGE, self::EVENT_CROSS_BORDER, self::EVENT_AUTHORIZATION_FEE, self::EVENT_TRANSACTION_FEE], true)) {
            $reference .= ':'.$this->eventSlug($eventName);
        }
        $amount = $this->eventAmount($eventName, $data);
        $fee = in_array($eventName, [self::EVENT_DECLINED_CHARGE, self::EVENT_CROSS_BORDER, self::EVENT_AUTHORIZATION_FEE, self::EVENT_TRANSACTION_FEE], true)
            ? $amount
            : 0.0;
        $transactionAmount = $fee > 0 ? 0.0 : $amount;
        $merchant = trim((string) ($data['merchant_name'] ?? $data['merchantName'] ?? ''));
        $description = trim((string) ($data['narrative'] ?? ''));
        if ($description === '') {
            $description = $merchant !== '' ? $defaultDescription.' · '.$merchant : $defaultDescription;
        }

        $transaction = VirtualCardTransaction::query()
            ->where('virtual_card_id', $card->id)
            ->where(function ($query) use ($providerId, $reference) {
                $query->where('provider_transaction_id', $providerId);
                if ($reference !== '') {
                    $query->orWhere('reference', $reference);
                }
            })
            ->first();

        $attributes = [
            'user_id' => $card->user_id,
            'provider_transaction_id' => $providerId,
            'type' => $type,
            'status' => $status,
            'currency' => strtoupper((string) ($data['currency'] ?? 'USD')),
            'amount' => $transactionAmount,
            'fee' => $fee,
            'total_amount' => $transactionAmount + $fee,
            'reference' => $reference,
            'description' => $description,
            'metadata' => array_filter([
                'source' => 'pagocards_webhook',
                'provider_event' => $eventName,
                'provider_created_at' => $data['timestamp'] ?? null,
                'merchant_name' => $merchant !== '' ? $merchant : null,
                'merchant_country' => $data['merchant_country'] ?? null,
                'merchant_mcc' => $data['merchant_mcc'] ?? null,
                'reason' => $data['reason'] ?? $data['declineReasonCode'] ?? null,
                'violation_count' => $data['violationCount'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'provider_payload' => $payload,
        ];

        if ($transaction) {
            $transaction->update($attributes);

            return;
        }

        VirtualCardTransaction::query()->create(array_merge([
            'virtual_card_id' => $card->id,
        ], $attributes));

        if ($eventName === self::EVENT_DECLINED_CHARGE && $card->user_id) {
            $user = User::query()->find($card->user_id);
            if ($user) {
                $this->notifyUser($user, $eventName, $data, $card->id, $externalEventId);
            }
        }
    }

    private function eventAmount(string $eventName, array $data): float
    {
        if (array_key_exists('display_amount', $data) && is_numeric($data['display_amount'])) {
            return (float) $data['display_amount'];
        }

        $raw = in_array($eventName, [self::EVENT_DECLINED_CHARGE, self::EVENT_AUTHORIZATION_FEE], true)
            ? ($data['feeAmount'] ?? $data['amount'] ?? 0)
            : ($data['chargedAmount'] ?? $data['amount'] ?? $data['merchantAmount'] ?? 0);

        if (! is_numeric($raw)) {
            return 0.0;
        }

        $amount = (float) $raw;

        return str_starts_with($eventName, 'virtualcard.') ? $amount / 1_000_000 : $amount;
    }

    private function money(float $amount, string $currency): string
    {
        return strtoupper($currency) === 'USD'
            ? '$'.number_format($amount, 2, '.', ',')
            : number_format($amount, 2, '.', ',').' '.strtoupper($currency);
    }

    private function eventCanChangeBalance(string $eventName): bool
    {
        return in_array($eventName, [
            self::EVENT_AUTHORIZATION,
            self::EVENT_SETTLEMENT,
            self::EVENT_DECLINED_CHARGE,
            self::EVENT_CROSS_BORDER,
            self::EVENT_TOPUP_COMPLETED,
            self::EVENT_AUTHORIZATION_FEE,
            self::EVENT_REFUND,
            self::EVENT_TRANSACTION_FEE,
            self::EVENT_CASH_WITHDRAWAL,
            self::EVENT_CANCELLATION,
            self::EVENT_CARD_WITHDRAW_RESULT,
            self::LEGACY_AUTHORIZATION_CONFIRMED,
        ], true);
    }

    private function eventSlug(string $eventName): string
    {
        return str_replace(['virtualcard.transaction.', '.'], ['', '-'], $eventName);
    }

    private function markRaw(?int $rawId, ?string $error): void
    {
        if ($rawId === null) {
            return;
        }
        PagocardsRawWebhook::query()->where('id', $rawId)->update([
            'processed' => true,
            'processed_at' => now(),
            'error_message' => $error,
        ]);
    }
}
