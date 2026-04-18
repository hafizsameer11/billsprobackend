<?php

namespace App\Services\Admin;

use App\Models\ServiceProfitSetting;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProfitReportingService
{
    /**
     * @return array{
     *   fixed_profit: string,
     *   percentage_profit: string,
     *   total_profit: string,
     *   basis_amount: string,
     *   basis: string,
     *   service_key: string,
     *   setting_label: string|null
     * }
     */
    public function computeForTransaction(Transaction $t, Collection $settingsByKey): array
    {
        $setting = $this->resolveSetting($t->type ?? '', $settingsByKey);
        if (! $setting || ! $setting->is_active) {
            return [
                'fixed_profit' => '0',
                'percentage_profit' => '0',
                'total_profit' => '0',
                'basis_amount' => '0',
                'basis' => 'total_amount',
                'service_key' => (string) ($t->type ?? ''),
                'setting_label' => null,
            ];
        }

        $basisKey = in_array($setting->percentage_basis, ['amount', 'fee', 'total_amount'], true)
            ? $setting->percentage_basis
            : 'total_amount';

        $basisAmount = match ($basisKey) {
            'amount' => (float) $t->amount,
            'fee' => (float) $t->fee,
            default => (float) $t->total_amount,
        };

        $fixed = (float) $setting->fixed_fee;
        $pct = (float) $setting->percentage;
        $pctProfit = round($basisAmount * $pct / 100, 8);
        $total = round($fixed + $pctProfit, 8);

        return [
            'fixed_profit' => $this->fmt($fixed),
            'percentage_profit' => $this->fmt($pctProfit),
            'total_profit' => $this->fmt($total),
            'basis_amount' => $this->fmt($basisAmount),
            'basis' => $basisKey,
            'service_key' => (string) $setting->service_key,
            'setting_label' => $setting->label,
        ];
    }

    /**
     * @return array{
     *   transaction_count: int,
     *   sum_transaction_amount: string,
     *   sum_fixed_profit: string,
     *   sum_percentage_profit: string,
     *   sum_total_profit: string
     * }
     */
    public function summarize(Builder $query, Collection $settingsByKey): array
    {
        $sumFixed = 0.0;
        $sumPct = 0.0;
        $sumTotal = 0.0;
        $sumAmount = 0.0;
        $count = 0;

        $query->clone()->orderBy('id')->chunk(500, function ($rows) use ($settingsByKey, &$sumFixed, &$sumPct, &$sumTotal, &$sumAmount, &$count) {
            foreach ($rows as $t) {
                /** @var Transaction $t */
                $p = $this->computeForTransaction($t, $settingsByKey);
                $sumFixed += (float) $p['fixed_profit'];
                $sumPct += (float) $p['percentage_profit'];
                $sumTotal += (float) $p['total_profit'];
                $sumAmount += (float) $t->total_amount;
                $count++;
            }
        });

        return [
            'transaction_count' => $count,
            'sum_transaction_amount' => $this->fmt($sumAmount),
            'sum_fixed_profit' => $this->fmt($sumFixed),
            'sum_percentage_profit' => $this->fmt($sumPct),
            'sum_total_profit' => $this->fmt($sumTotal),
        ];
    }

    public function settingsByKey(): Collection
    {
        return ServiceProfitSetting::query()
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->keyBy('service_key');
    }

    protected function resolveSetting(string $type, Collection $settingsByKey): ?ServiceProfitSetting
    {
        if ($type !== '' && $settingsByKey->has($type)) {
            return $settingsByKey->get($type);
        }

        return $settingsByKey->get('_default');
    }

    protected function fmt(float $n): string
    {
        return number_format($n, 8, '.', '');
    }
}
