<?php

namespace App\Console\Commands;

use App\Models\PagocardsWalletRecharge;
use App\Services\Admin\CardLoadProfitBackfillService;
use Illuminate\Console\Command;

class BackfillCardLoadProfitCommand extends Command
{
    protected $signature = 'pagocards:backfill-card-load-profit
                            {--dry-run : Preview without writing metadata or taking a backup}
                            {--recharge-id= : Use a specific recharge row instead of the first one}';

    protected $description = 'Backfill historical card-load profit into metadata.profit_snapshot_backfill (first recharge only)';

    public function handle(CardLoadProfitBackfillService $backfill): int
    {
        if ($backfill->hasCompletedHistoricalBackfill()) {
            $this->warn('Historical profit backfill was already completed.');

            return self::SUCCESS;
        }

        $rechargeId = $this->option('recharge-id');
        $recharge = $rechargeId
            ? PagocardsWalletRecharge::query()->find($rechargeId)
            : PagocardsWalletRecharge::query()->orderBy('id')->first();

        if (! $recharge) {
            $this->error('No Pagocards wallet recharge found. Log the first recharge in admin first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->info('Dry run — no database backup or metadata writes.');
        }

        $result = $backfill->applyFirstRechargeRateToHistory($recharge, $dryRun);

        $this->table(
            ['Metric', 'Value'],
            collect($result)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : (string) $v])->values()->all()
        );

        return self::SUCCESS;
    }
}
