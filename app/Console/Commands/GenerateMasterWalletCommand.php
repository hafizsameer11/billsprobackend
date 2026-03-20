<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMasterWalletJob;
use App\Services\Crypto\MasterWalletGeneratorService;
use Illuminate\Console\Command;

class GenerateMasterWalletCommand extends Command
{
    protected $signature = 'crypto:generate-master-wallet
                            {blockchain? : Normalized chain, e.g. ethereum, bsc, bitcoin}
                            {--all : Generate for all chains in config}
                            {--force : Replace existing master wallet for the chain(s)}
                            {--sync : Run immediately instead of queueing}
                            {--label= : Optional label}';

    protected $description = 'Generate Tatum master wallet(s): store address + encrypted mnemonic/xpub/private key in master_wallets / master_wallet_secrets';

    public function handle(MasterWalletGeneratorService $generator): int
    {
        $chains = $this->option('all')
            ? (array) config('crypto.master_wallet_chains', [])
            : ($this->argument('blockchain') ? [$this->argument('blockchain')] : null);

        if ($chains === null || $chains === []) {
            $this->error('Provide a blockchain name or use --all');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $label = $this->option('label') ?: null;

        foreach ($chains as $chain) {
            $chain = (string) $chain;
            if ($sync) {
                try {
                    $w = $generator->generate($chain, $force, $label);
                    $this->info("OK {$w->blockchain} {$w->address}");
                } catch (\Throwable $e) {
                    $this->error("{$chain}: ".$e->getMessage());

                    return self::FAILURE;
                }
            } else {
                GenerateMasterWalletJob::dispatch($chain, $force, $label);
                $this->info("Queued master wallet job for: {$chain}");
            }
        }

        return self::SUCCESS;
    }
}
