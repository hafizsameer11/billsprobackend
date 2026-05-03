<?php

namespace App\Console\Commands;

use App\Models\VirtualAccount;
use App\Services\Tatum\DepositAddressService;
use Illuminate\Console\Command;

/**
 * For virtual accounts that never received on-chain provisioning (no crypto_deposit_addresses row),
 * run the same path as the queue job synchronously: UserWallet (Tatum HD) + deposit address + V4 webhooks.
 * Does not use ProvisionUserCryptoDepositAddressesJob.
 */
class CryptoSyncMissingTatumDepositAddressesCommand extends Command
{
    protected $signature = 'crypto:sync-missing-tatum-deposit-addresses
                            {--user= : Only virtual accounts for this user ID}
                            {--dry-run : List counts only, no Tatum calls}
                            {--chunk=100 : Chunk size for processing}';

    protected $description = 'Synchronously create Tatum user wallets, deposit addresses, and webhook subscriptions for virtual accounts that have none (no queue).';

    public function handle(DepositAddressService $depositAddressService): int
    {
        if (config('tatum.use_mock')) {
            $this->error('TATUM_USE_MOCK is true — no on-chain provisioning. Set TATUM_USE_MOCK=false and configure TATUM_API_KEY.');

            return self::FAILURE;
        }

        $userId = $this->option('user');
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $baseQuery = VirtualAccount::query()
            ->where('active', true)
            ->whereDoesntHave('cryptoDepositAddresses');

        if ($userId !== null && $userId !== '') {
            $baseQuery->where('user_id', (int) $userId);
        }

        $total = (clone $baseQuery)->count();
        $this->info("Virtual accounts missing a deposit address row: {$total}");

        if ($total === 0) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Dry run — no Tatum API calls made.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;

        (clone $baseQuery)->orderBy('id')->chunkById($chunkSize, function ($virtualAccounts) use ($depositAddressService, &$created, &$skipped, &$failed) {
            foreach ($virtualAccounts as $virtualAccount) {
                /** @var VirtualAccount $virtualAccount */
                if ($virtualAccount->cryptoDepositAddresses()->exists()) {
                    $skipped++;

                    continue;
                }

                try {
                    $depositAddressService->ensureDepositAddressForVirtualAccount($virtualAccount);
                    $created++;
                    $this->line("OK virtual_account_id={$virtualAccount->id} user_id={$virtualAccount->user_id} {$virtualAccount->blockchain}/{$virtualAccount->currency}");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("FAIL virtual_account_id={$virtualAccount->id} user_id={$virtualAccount->user_id} {$virtualAccount->blockchain}/{$virtualAccount->currency}: {$e->getMessage()}");
                }
            }
        });

        $this->newLine();
        $this->info("Finished. Provisioned: {$created}, skipped (race): {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
