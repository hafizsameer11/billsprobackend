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
                            {--no-dispatch : Skip queueing ProvisionUserCryptoDepositAddressesJob when not using --with-tatum-addresses}';

    protected $description = 'Create missing crypto virtual_accounts from active wallet_currencies; optionally sync Tatum deposit wallets/addresses.';

    public function handle(CryptoWalletService $cryptoWalletService, DepositAddressService $depositAddressService): int
    {
        $userId = $this->option('user');
        $syncTatum = (bool) $this->option('with-tatum-addresses');
        $noDispatch = (bool) $this->option('no-dispatch');

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
            } elseif (! $noDispatch && $nNew > 0) {
                ProvisionUserCryptoDepositAddressesJob::dispatch($user->id);
                $jobsDispatched++;
            }

            $usersProcessed++;
        }

        $this->info("Done. Users scanned: {$usersProcessed}. New virtual account rows created this run: {$accountsCreated}.");
        if (! $syncTatum && ! $noDispatch) {
            $this->comment("Queued ProvisionUserCryptoDepositAddressesJob for {$jobsDispatched} user(s) (only where new accounts were added). Run `php artisan queue:work`.");
            $this->comment('To create Tatum addresses in this process instead of the queue, use --with-tatum-addresses.');
        }

        return self::SUCCESS;
    }
}
