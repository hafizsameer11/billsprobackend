<?php

namespace App\Services\Admin;

use App\Models\ServiceProfitSetting;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Admin-configured profit on top of customer-facing pricing from {@see \App\Services\Platform\PlatformRateResolver}
 * and {@see \App\Services\Crypto\CryptoService}.
 *
 * Revenue shape differs by transaction type: fiat/bills use ledger fees; on-chain crypto uses USD-notional fees;
 * buy/sell use NGN notional from metadata (spread), not `transactions.fee` (often zero).
 */
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
     *   setting_label: string|null,
     *   profit_currency: string|null
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
                'profit_currency' => null,
            ];
        }

        $basisKey = in_array($setting->percentage_basis, ['amount', 'fee', 'total_amount', 'ngn_notional'], true)
            ? $setting->percentage_basis
            : 'total_amount';

        $basisAmount = match ($basisKey) {
            'amount' => (float) $t->amount,
            'fee' => (float) $t->fee,
            'ngn_notional' => $this->ngnNotionalFromTransaction($t),
            default => (float) $t->total_amount,
        };

        $fixed = (float) $setting->fixed_fee;
        $pct = (float) $setting->percentage;
        $pctProfit = round($basisAmount * $pct / 100, 8);
        $total = round($fixed + $pctProfit, 8);

        $profitCurrency = $basisKey === 'ngn_notional' ? 'NGN' : null;

        return [
            'fixed_profit' => $this->fmt($fixed),
            'percentage_profit' => $this->fmt($pctProfit),
            'total_profit' => $this->fmt($total),
            'basis_amount' => $this->fmt($basisAmount),
            'basis' => $basisKey,
            'service_key' => (string) $setting->service_key,
            'setting_label' => $setting->label,
            'profit_currency' => $profitCurrency,
        ];
    }

    /**
     * NGN economic leg for crypto buy (metadata.payment_amount) and sell (ngn_amount / amount_to_receive).
     */
    protected function ngnNotionalFromTransaction(Transaction $t): float
    {
        $meta = is_array($t->metadata) ? $t->metadata : [];
        $type = (string) ($t->type ?? '');

        if ($type === 'crypto_buy') {
            return isset($meta['payment_amount']) ? (float) $meta['payment_amount'] : 0.0;
        }

        if ($type === 'crypto_sell') {
            $v = $meta['ngn_amount'] ?? $meta['amount_to_receive'] ?? null;

            return $v !== null && $v !== '' ? (float) $v : 0.0;
        }

        return 0.0;
    }

    /**
     * @return array{
     *   transaction_count: int,
     *   sum_transaction_amount: string,
     *   sum_fee_collected: string,
     *   sum_principal_amount: string,
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
        $sumFee = 0.0;
        $sumPrincipal = 0.0;
        $count = 0;

        $query->clone()->orderBy('id')->chunk(500, function ($rows) use ($settingsByKey, &$sumFixed, &$sumPct, &$sumTotal, &$sumAmount, &$sumFee, &$sumPrincipal, &$count) {
            foreach ($rows as $t) {
                /** @var Transaction $t */
                $p = $this->computeForTransaction($t, $settingsByKey);
                $sumFixed += (float) $p['fixed_profit'];
                $sumPct += (float) $p['percentage_profit'];
                $sumTotal += (float) $p['total_profit'];
                $sumAmount += (float) $t->total_amount;
                $sumFee += (float) $t->fee;
                $sumPrincipal += (float) $t->amount;
                $count++;
            }
        });

        return [
            'transaction_count' => $count,
            'sum_transaction_amount' => $this->fmt($sumAmount),
            'sum_fee_collected' => $this->fmt($sumFee),
            'sum_principal_amount' => $this->fmt($sumPrincipal),
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
