<?php

namespace App\Services\VirtualCard;

use App\Helpers\NotificationHelper;
use App\Models\PagocardsRawWebhook;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardProviderWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PagocardsVirtualCardWebhookService
{
    public const EVENT_TOKENIZATION = 'cardTokenization.deliverActivationCode';

    public const EVENT_3DS_CREATED = 'cardAuthentication.created';

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
            $externalEventId = (string) ($payload['eventId'] ?? '');
            if ($externalEventId === '') {
                $this->markRaw($rawId, 'Missing eventId');

                return ['success' => true, 'message' => 'Ignored: missing eventId'];
            }

            if (VirtualCardProviderWebhookEvent::query()->where('external_event_id', $externalEventId)->exists()) {
                $this->markRaw($rawId, null);

                return ['success' => true, 'message' => 'Duplicate event ignored', 'duplicate' => true];
            }

            $eventName = (string) ($payload['eventName'] ?? '');
            $pagocardsCardId = (string) ($payload['cardId'] ?? '');
            $pagocardsUserId = isset($payload['userId']) ? (string) $payload['userId'] : null;
            $eventTargetId = isset($payload['eventTargetId']) ? (string) $payload['eventTargetId'] : null;

            $card = $pagocardsCardId !== ''
                ? VirtualCard::query()->where('provider_card_id', $pagocardsCardId)->first()
                : null;

            $event = VirtualCardProviderWebhookEvent::query()->create([
                'external_event_id' => $externalEventId,
                'event_name' => $eventName !== '' ? $eventName : 'unknown',
                'event_target_id' => $eventTargetId,
                'pagocards_card_id' => $pagocardsCardId,
                'pagocards_user_id' => $pagocardsUserId,
                'virtual_card_id' => $card?->id,
                'user_id' => $card?->user_id,
                'status' => VirtualCardProviderWebhookEvent::STATUS_PENDING,
                'payload' => $payload,
            ]);

            if ($card && $card->user_id) {
                $user = User::query()->find($card->user_id);
                if ($user) {
                    $this->notifyUser($user, $eventName, $payload, $card->id);
                }
            }

            $this->markRaw($rawId, null);

            return ['success' => true, 'message' => 'Webhook processed', 'event_db_id' => $event->id];
        } catch (\Throwable $e) {
            Log::error('pagocards_webhook.process_failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->markRaw($rawId, $e->getMessage());

            return ['success' => false, 'message' => 'Processing error'];
        }
    }

    protected function notifyUser(User $user, string $eventName, array $payload, int $virtualCardId): void
    {
        if ($eventName === self::EVENT_3DS_CREATED) {
            $merchant = (string) ($payload['merchantName'] ?? 'Merchant');
            $amount = (string) ($payload['merchantAmount'] ?? '');
            $currency = (string) ($payload['merchantCurrency'] ?? '');
            $title = 'Card security approval needed';
            $message = $amount !== '' && $currency !== ''
                ? "Approve payment to {$merchant} for {$amount} {$currency}."
                : "Approve a payment to {$merchant}.";

            NotificationHelper::create(
                $user->id,
                'virtual_card',
                $title,
                $message,
                [
                    'kind' => 'pagocards_3ds',
                    'virtual_card_id' => $virtualCardId,
                    'event_target_id' => $payload['eventTargetId'] ?? null,
                    'merchant_name' => $payload['merchantName'] ?? null,
                    'merchant_amount' => $payload['merchantAmount'] ?? null,
                    'merchant_currency' => $payload['merchantCurrency'] ?? null,
                    'masked_pan' => $payload['maskedPan'] ?? null,
                ]
            );

            return;
        }

        if ($eventName === self::EVENT_TOKENIZATION) {
            $wallet = (string) ($payload['digitalWalletName'] ?? 'wallet');
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
                    'digital_wallet_name' => $payload['digitalWalletName'] ?? null,
                    'activation_method' => $payload['activationMethod'] ?? null,
                    'event_target_id' => $payload['eventTargetId'] ?? null,
                ]
            );

            return;
        }
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
