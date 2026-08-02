<?php

namespace App\Services\Admin;

use App\Models\PagocardsWalletRecharge;
use App\Models\Transaction;
use App\Models\VirtualCard;
use Illuminate\Support\Carbon;

class CardLoadProfitBackfillService
{
    public const METADATA_KEY = 'profit_snapshot_backfill';

    public function __construct(
        protected CardLoadProfitCalculator $calculator,
        protected DatabaseBackupService $backups,
    ) {}

    public function hasCompletedHistoricalBackfill(): bool
    {
        return PagocardsWalletRecharge::query()
            ->whereNotNull('applied_to_history_at')
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function applyFirstRechargeRateToHistory(PagocardsWalletRecharge $recharge, bool $dryRun = false): array
    {
        if ($this->hasCompletedHistoricalBackfill()) {
            return [
                'skipped' => true,
                'reason' => 'historical_backfill_already_completed',
                'processed' => 0,
                'skipped_existing' => 0,
                'ineligible' => 0,
            ];
        }

        $trueRate = (float) $recharge->true_rate_ngn_per_usd;
        if ($trueRate <= 0) {
            return [
                'skipped' => true,
                'reason' => 'invalid_true_rate',
                'processed' => 0,
                'skipped_existing' => 0,
                'ineligible' => 0,
            ];
        }

        $backupPath = null;
        if (! $dryRun) {
            $backupPath = $this->backups->backupMysql('pagocards_profit_backfill');
        }

        $processed = 0;
        $skippedExisting = 0;
        $ineligible = 0;

        Transaction::query()
            ->where('type', 'card_funding')
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (
                $recharge,
                $trueRate,
                $dryRun,
                &$processed,
                &$skippedExisting,
                &$ineligible
            ) {
                foreach ($rows as $transaction) {
                    /** @var Transaction $transaction */
                    $meta = is_array($transaction->metadata) ? $transaction->metadata : [];

                    if (isset($meta[self::METADATA_KEY]) && is_array($meta[self::METADATA_KEY])) {
                        $skippedExisting++;

                        continue;
                    }

                    if (isset($meta['profit_snapshot']) && is_array($meta['profit_snapshot'])) {
                        $skippedExisting++;

                        continue;
                    }

                    if (($meta['payment_wallet_type'] ?? '') !== 'naira_wallet') {
                        $ineligible++;

                        continue;
                    }

                    $charges = is_array($meta['wallet_charge'] ?? null) ? $meta['wallet_charge'] : [];
                    $principalUsd = (float) ($meta['principal_usd'] ?? $charges['principal_usd'] ?? 0);
                    if ($principalUsd <= 0) {
                        $ineligible++;

                        continue;
                    }

                    $fundKey = $this->resolveFundServiceKey($transaction, $meta);
                    $customerRate = (float) ($meta['exchange_rate_ngn_per_usd'] ?? $charges['exchange_rate_ngn_per_usd'] ?? 0);
                    $revenueNgn = (float) ($transaction->total_amount ?? $charges['charge_ngn'] ?? 0);

                    $snapshot = $this->calculator->buildBackfillSnapshot(
                        $revenueNgn,
                        $principalUsd,
                        $trueRate,
                        $customerRate,
                        $fundKey,
                        (int) $recharge->id,
                    );

                    if ($dryRun) {
                        $processed++;

                        continue;
                    }

                    $meta[self::METADATA_KEY] = $snapshot;
                    $transaction->update(['metadata' => $meta]);
                    $processed++;
                }
            });

        if (! $dryRun) {
            $recharge->update([
                'applied_to_history_at' => now(),
                'history_backfill_count' => $processed,
                'db_backup_path' => $backupPath,
            ]);
        }

        return [
            'skipped' => false,
            'dry_run' => $dryRun,
            'processed' => $processed,
            'skipped_existing' => $skippedExisting,
            'ineligible' => $ineligible,
            'true_rate_ngn_per_usd' => $trueRate,
            'recharge_id' => $recharge->id,
            'db_backup_path' => $backupPath,
            'metadata_key' => self::METADATA_KEY,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveFundServiceKey(Transaction $transaction, array $meta): string
    {
        $scheme = strtolower((string) ($meta['card_scheme'] ?? ''));
        if ($scheme === 'visa') {
            return 'visa_fund';
        }
        if ($scheme === 'mastercard') {
            return 'fund';
        }

        $cardId = (int) ($meta['card_id'] ?? 0);
        if ($cardId > 0) {
            $card = VirtualCard::query()->find($cardId);
            if ($card) {
                $provider = strtolower((string) $card->provider);
                $cardType = strtolower((string) $card->card_type);

                if (str_contains($provider, 'visa') || $cardType === 'visa') {
                    return 'visa_fund';
                }
            }
        }

        return 'visa_fund';
    }
}
