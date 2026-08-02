<?php

namespace App\Jobs;

use App\Models\VirtualCard;
use App\Services\VirtualCard\DeclineFeeRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckMerchantDeclineFeeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $virtualCardId,
        public ?string $declinedReference = null,
        public int $attempt = 0,
    ) {}

    public function handle(DeclineFeeRecoveryService $recovery): void
    {
        $card = VirtualCard::query()->find($this->virtualCardId);
        if (! $card || ! $card->provider_card_id) {
            return;
        }

        $processed = $recovery->pollAndProcessForCard($card, $this->declinedReference);

        $delays = config('virtual_card.decline_fee_check_delays_minutes', [2, 10, 30]);
        if (! is_array($delays)) {
            $delays = [2, 10, 30];
        }

        $nextAttempt = $this->attempt + 1;
        if ($processed === 0 && isset($delays[$nextAttempt])) {
            self::dispatch($this->virtualCardId, $this->declinedReference, $nextAttempt)
                ->delay(now()->addMinutes((int) $delays[$nextAttempt]));
        }
    }
}
