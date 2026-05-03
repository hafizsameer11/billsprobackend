<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionUserCryptoDepositAddressesJob;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Services\Crypto\CryptoWalletService;
use App\Services\Tatum\DepositAddressService;
use Illuminate\Console\Command;

/**
 * After adding rows to `wallet_currencies`, backfill `virtual_accounts` for every user,
 * then provision Tatum deposit addresses (same flow as post email-verify).
 *
 * Tatum V3 wallet: GET https://api.tatum.io/v3/{chain}/wallet (see Tatum address management docs).
 */
class CryptoProvisionMissingVirtualAccountsCommand extends Command
{
    protected $signature = 'crypto:provision-missing-virtual-accounts
                            {--user= : Only this user ID}
                            {--with-tatum-addresses : Call Tatum synchronously for each virtual account (requires TATUM_USE_MOCK=false and API key; long-running)}
                            {--no-dispatch : Skip queueing ProvisionUserCryptoDepositAddressesJob when not using --with-tatum-addresses}
                            {--only-new-accounts : Only queue Tatum job for users who received new virtual_accounts this run (default: queue for every user scanned)}';

    protected $description = 'Create missing virtual_accounts, then queue Tatum user-wallet + deposit address provisioning for all users (or use --only-new-accounts to limit).';

    public function handle(CryptoWalletService $cryptoWalletService, DepositAddressService $depositAddressService): int
    {
        $userId = $this->option('user');
        $syncTatum = (bool) $this->option('with-tatum-addresses');
        $noDispatch = (bool) $this->option('no-dispatch');
        $onlyNewAccounts = (bool) $this->option('only-new-accounts');

        if (config('tatum.use_mock') && $syncTatum) {
            $this->error('Refusing --with-tatum-addresses while TATUM_USE_MOCK is true.');

            return self::FAILURE;
        }

        $query = User::query()->orderBy('id');
        if ($userId !== null && $userId !== '') {
            $query->whereKey((int) $userId);
        }

        $usersProcessed = 0;
        $accountsCreated = 0;
        $jobsDispatched = 0;

        foreach ($query->cursor() as $user) {
            $created = $cryptoWalletService->initializeUserCryptoWallets($user->id);
            $nNew = count($created);
            $accountsCreated += $nNew;

            if ($nNew > 0) {
                $this->info("User {$user->id}: +{$nNew} virtual account(s).");
            }

            if ($syncTatum) {
                $virtualAccounts = VirtualAccount::query()
                    ->where('user_id', $user->id)
                    ->where('active', true)
                    ->orderBy('id')
                    ->get();

                foreach ($virtualAccounts as $va) {
                    try {
                        $depositAddressService->ensureDepositAddressForVirtualAccount($va);
                    } catch (\Throwable $e) {
                        $this->warn("  virtual_account {$va->id} ({$va->blockchain}/{$va->currency}): {$e->getMessage()}");
                    }
                }
            } elseif (! $noDispatch && (! $onlyNewAccounts || $nNew > 0)) {
                ProvisionUserCryptoDepositAddressesJob::dispatch($user->id);
                $jobsDispatched++;
            }

            $usersProcessed++;
        }

        $this->info("Done. Users scanned: {$usersProcessed}. New virtual account rows created this run: {$accountsCreated}.");
        if (! $syncTatum && ! $noDispatch) {
            $scope = $onlyNewAccounts ? 'users with new virtual_accounts this run' : 'every user scanned';
            $this->comment("Queued ProvisionUserCryptoDepositAddressesJob for {$jobsDispatched} user(s) ({$scope}). Run `php artisan queue:work`.");
            $this->comment('Use --only-new-accounts to avoid re-queueing users who had no new ledger rows. Sync in-process: --with-tatum-addresses.');
        }

        return self::SUCCESS;
    }
}
