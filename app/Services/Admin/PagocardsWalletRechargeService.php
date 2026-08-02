<?php

namespace App\Services\Admin;

use App\Models\PagocardsWalletRecharge;
use InvalidArgumentException;

class PagocardsWalletRechargeService
{
    public function __construct(
        protected CardLoadProfitBackfillService $backfill,
    ) {}

    public function currentTrueRate(): ?float
    {
        $row = PagocardsWalletRecharge::query()
            ->orderByDesc('recharged_at')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) $row->true_rate_ngn_per_usd : null;
    }

    public function latestRecharge(): ?PagocardsWalletRecharge
    {
        return PagocardsWalletRecharge::query()
            ->orderByDesc('recharged_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{recharge: PagocardsWalletRecharge, historical_backfill: array<string, mixed>|null}
     */
    public function create(array $data, ?int $adminUserId = null): array
    {
        $isFirstRecharge = ! PagocardsWalletRecharge::query()->exists();

        $ngnSpent = (float) ($data['ngn_spent'] ?? 0);
        $usdCredited = (float) ($data['usd_credited'] ?? 0);

        if ($ngnSpent <= 0) {
            throw new InvalidArgumentException('NGN spent must be greater than zero.');
        }
        if ($usdCredited <= 0) {
            throw new InvalidArgumentException('USD credited must be greater than zero.');
        }

        $usdGross = isset($data['usd_gross']) && $data['usd_gross'] !== '' && $data['usd_gross'] !== null
            ? (float) $data['usd_gross']
            : null;

        if ($usdGross !== null && $usdGross <= 0) {
            throw new InvalidArgumentException('USD gross must be greater than zero when provided.');
        }

        $trueRate = round($ngnSpent / $usdCredited, 8);

        $recharge = PagocardsWalletRecharge::query()->create([
            'ngn_spent' => round($ngnSpent, 2),
            'usd_gross' => $usdGross !== null ? round($usdGross, 4) : null,
            'usd_credited' => round($usdCredited, 4),
            'true_rate_ngn_per_usd' => $trueRate,
            'recharged_at' => $data['recharged_at'] ?? now(),
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
            'created_by' => $adminUserId,
        ]);

        $historicalBackfill = null;
        if ($isFirstRecharge) {
            $historicalBackfill = $this->backfill->applyFirstRechargeRateToHistory($recharge);
        }

        return [
            'recharge' => $recharge->fresh(),
            'historical_backfill' => $historicalBackfill,
        ];
    }
}
