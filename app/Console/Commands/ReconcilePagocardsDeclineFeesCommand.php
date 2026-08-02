<?php

namespace App\Console\Commands;

use App\Services\VirtualCard\DeclineFeeRecoveryService;
use Illuminate\Console\Command;

class ReconcilePagocardsDeclineFeesCommand extends Command
{
    protected $signature = 'pagocards:reconcile-decline-fees';

    protected $description = 'Poll Pagocards admin API for merchant-paid visa decline fees and charge users in Naira';

    public function handle(DeclineFeeRecoveryService $recovery): int
    {
        if (! $recovery->isEnabled()) {
            $this->warn('Decline fee recovery is disabled.');

            return self::SUCCESS;
        }

        $processed = $recovery->reconcileAll();
        $this->info("Processed {$processed} new merchant-paid decline fee(s).");

        return self::SUCCESS;
    }
}
