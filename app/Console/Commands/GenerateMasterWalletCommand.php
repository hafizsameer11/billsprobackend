<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMasterWalletJob;
use App\Services\Crypto\KeyEncryptionService;
use App\Services\Crypto\MasterWalletGeneratorService;
use App\Services\Tatum\TatumClient;
use Illuminate\Console\Command;
use Throwable;

class GenerateMasterWalletCommand extends Command
{
    protected $signature = 'crypto:generate-master-wallet
                            {blockchain? : Normalized chain, e.g. ethereum, bsc, bitcoin}
                            {--all : Generate for all chains in CRYPTO_MASTER_WALLET_CHAINS / config}
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

        $chains = array_values(array_filter(array_map('trim', $chains), fn (string $c) => $c !== ''));

        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $label = $this->option('label') ?: null;
        $multi = count($chains) > 1;

        if (! $this->preflight()) {
            return self::FAILURE;
        }

        $failures = [];
        $ok = 0;

        foreach ($chains as $chain) {
            $chain = (string) $chain;
            if ($sync) {
                try {
                    $w = $generator->generate($chain, $force, $label);
                    $this->info("OK {$w->blockchain} {$w->address}");
                    $ok++;
                } catch (Throwable $e) {
                    $msg = $e->getMessage();
                    $this->error("{$chain}: {$msg}");
                    $failures[] = "{$chain}: {$msg}";
                    if (! $multi) {
                        return self::FAILURE;
                    }
                }
            } else {
                GenerateMasterWalletJob::dispatch($chain, $force, $label);
                $this->info("Queued master wallet job for: {$chain}");
                $ok++;
            }
        }

        if ($multi && $sync && $failures !== []) {
            $this->newLine();
            $this->warn(count($failures).' chain(s) failed ('.$ok.' succeeded). Re-run per chain, e.g.:');
            $this->line('  php artisan crypto:generate-master-wallet ethereum --sync');
        }

        if ($ok === 0) {
            return self::FAILURE;
        }

        return $failures !== [] && ! $multi ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Fail fast with actionable messages (common causes of `crypto:generate-master-wallet --all --sync` errors).
     */
    protected function preflight(): bool
    {
        if (config('tatum.use_mock')) {
            $this->error('TATUM_USE_MOCK is true. Set TATUM_USE_MOCK=false in .env to generate real master wallets.');

            return false;
        }

        if (trim((string) config('tatum.api_key', '')) === '') {
            $this->error('TATUM_API_KEY is not set. Add it to .env (Tatum dashboard → API key).');

            return false;
        }

        try {
            TatumClient::fromConfig();
        } catch (Throwable $e) {
            $this->error('Tatum client: '.$e->getMessage());

            return false;
        }

        try {
            KeyEncryptionService::fromConfig();
        } catch (Throwable $e) {
            $this->error('Encryption: '.$e->getMessage());
            $this->line('Set ENCRYPTION_KEY in .env (32+ byte secret) or ensure APP_KEY is set.');

            return false;
        }

        return true;
    }
}
