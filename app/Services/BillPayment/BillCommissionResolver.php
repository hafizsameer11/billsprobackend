<?php

namespace App\Services\BillPayment;

use App\Models\BillCommissionRate;
use App\Models\CommissionVolumeTier;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BillCommissionResolver
{
    /**
     * Rolling calendar-month bill payment volume (NGN) for tier selection.
     */
    public function monthlyBillVolumeNgn(?Carbon $at = null): float
    {
        $at = $at ?? now();
        $start = $at->copy()->startOfMonth();
        $end = $at->copy()->endOfMonth();

        return (float) Transaction::query()
            ->where('type', 'bill_payment')
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
    }

    public function resolveTierKey(?Carbon $at = null): string
    {
        $volume = $this->monthlyBillVolumeNgn($at);
        $tiers = CommissionVolumeTier::query()->orderBy('sort_order')->get();

        foreach ($tiers as $tier) {
            $min = (float) $tier->min_monthly_volume_ngn;
            $max = $tier->max_monthly_volume_ngn;
            if ($volume >= $min && ($max === null || $volume < $max)) {
                return (string) $tier->tier_key;
            }
        }

        return '1';
    }

    /**
     * @return array{commission_pct: float, tier_key: string, entity_key: string, scene: string}|null
     */
    public function resolveCommission(
        string $scene,
        string $entityKey,
        ?string $tierKey = null
    ): ?array {
        $scene = $this->normalizeScene($scene);
        $entityKey = $this->normalizeEntityKey($entityKey, $scene);
        $tierKey = $tierKey ?? $this->resolveTierKey();

        $row = BillCommissionRate::query()
            ->where('scene', $scene)
            ->where('entity_key', $entityKey)
            ->where('tier_key', $tierKey)
            ->where('is_active', true)
            ->first();

        if (! $row) {
            $row = BillCommissionRate::query()
                ->where('scene', $scene)
                ->where('entity_key', $entityKey)
                ->where('tier_key', '1')
                ->where('is_active', true)
                ->first();
        }

        if (! $row) {
            return null;
        }

        return [
            'commission_pct' => (float) $row->commission_pct,
            'tier_key' => (string) $row->tier_key,
            'entity_key' => (string) $row->entity_key,
            'scene' => $scene,
        ];
    }

    public function estimatedCommissionNgn(float $amountNgn, float $commissionPct): float
    {
        return round($amountNgn * max(0.0, $commissionPct) / 100, 2);
    }

    protected function normalizeScene(string $scene): string
    {
        $s = strtolower(trim($scene));
        if (in_array($s, ['airtime', 'data', 'betting'], true)) {
            return $s;
        }
        if ($s === 'mobile' || $s === 'topup') {
            return 'airtime';
        }

        return $s;
    }

    protected function normalizeEntityKey(string $key, string $scene): string
    {
        $k = trim($key);
        if ($k === '') {
            return $k;
        }

        if ($scene === 'betting') {
            return $k;
        }

        return match (strtolower($k)) {
            'mtn', 'mtn-ng', 'mtn_ng' => 'MTN',
            'airtel', 'airtel-ng' => 'Airtel',
            'glo', 'glo-ng' => 'Glo',
            '9mobile', 'etisalat', '9 mobile' => '9Mobile',
            default => ucfirst($k),
        };
    }
}
