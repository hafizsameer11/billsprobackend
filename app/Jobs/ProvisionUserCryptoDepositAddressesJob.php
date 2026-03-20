<?php

namespace App\Jobs;

use App\Models\VirtualAccount;
use App\Services\Tatum\DepositAddressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionUserCryptoDepositAddressesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 60;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return 'provision-user-crypto-deposits-'.$this->userId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(DepositAddressService $depositAddressService): void
    {
        if (config('tatum.use_mock')) {
            Log::info('ProvisionUserCryptoDepositAddressesJob skipped: TATUM_USE_MOCK is true.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $accounts = VirtualAccount::query()
            ->where('user_id', $this->userId)
            ->where('active', true)
            ->orderBy('id')
            ->get();

        foreach ($accounts as $virtualAccount) {
            try {
                $depositAddressService->ensureDepositAddressForVirtualAccount($virtualAccount);
            } catch (\Throwable $e) {
                Log::error('ProvisionUserCryptoDepositAddressesJob: failed for virtual account.', [
                    'user_id' => $this->userId,
                    'virtual_account_id' => $virtualAccount->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
