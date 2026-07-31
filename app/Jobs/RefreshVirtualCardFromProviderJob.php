<?php

namespace App\Jobs;

use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pull the authoritative balance and recent transactions after a Pagocards webhook.
 */
class RefreshVirtualCardFromProviderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $userId,
        public int $virtualCardId,
    ) {}

    public function handle(VirtualCardService $virtualCards): void
    {
        $virtualCards->getCard($this->userId, $this->virtualCardId);
    }
}
