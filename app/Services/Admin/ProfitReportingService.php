<?php

namespace App\Services\Admin;

use App\Models\ServiceProfitSetting;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProfitReportingService
{
    public function __construct(
        protected TransactionPricingSnapshotService $snapshots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function computeForTransaction(Transaction $t, Collection $settingsByKey): array
    {
        $meta = is_array($t->metadata) ? $t->metadata : [];
        $snap = $meta['pricing_snapshot'] ?? null;
        if (! is_array($snap)) {
            $snap = $this->snapshots->buildForTransaction($t);
        }

        $revenueNgn = (float) ($snap['customer_revenue_ngn'] ?? $snap['charge_ngn'] ?? (float) $t->fee);
        if ($revenueNgn <= 0 && isset($meta['estimated_commission_ngn'])) {
            $revenueNgn = (float) $meta['estimated_commission_ngn'];
        }

        $providerCostNgn = (float) ($snap['provider_cost_ngn'] ?? 0);
        $netMargin = (float) ($snap['estimated_profit_ngn'] ?? round($revenueNgn - $providerCostNgn, 2));

        $setting = $this->resolveSetting($t, $settingsByKey);
        $legacy = $this->computeLegacyRule($t, $setting);

        if ($netMargin == 0.0 && (float) $legacy['total_profit'] > 0) {
            $netMargin = (float) $legacy['total_profit'];
            $revenueNgn = (float) $legacy['basis_amount'];
        }

        return array_merge($legacy, [
            'customer_revenue_ngn' => $this->fmt($revenueNgn),
            'provider_cost_ngn' => $this->fmt($providerCostNgn),
            'net_margin_ngn' => $this->fmt($netMargin),
            'total_profit' => $this->fmt($netMargin),
            'pricing_source' => (string) ($snap['source'] ?? 'ledger'),
            'commission_pct' => isset($meta['commission_pct']) ? (string) $meta['commission_pct'] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeLegacyRule(Transaction $t, ?ServiceProfitSetting $setting): array
    {
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
                'admin_profit_percent' => null,
            ];
        }

        if ($setting->margin_mode === 'commission') {
            return [
                'fixed_profit' => '0',
                'percentage_profit' => '0',
                'total_profit' => '0',
                'basis_amount' => (string) $t->amount,
                'basis' => 'amount',
                'service_key' => (string) $setting->service_key,
                'setting_label' => $setting->label,
                'profit_currency' => 'NGN',
                'admin_profit_percent' => (string) $setting->percentage,
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

        return [
            'fixed_profit' => $this->fmt($fixed),
            'percentage_profit' => $this->fmt($pctProfit),
            'total_profit' => $this->fmt($total),
            'basis_amount' => $this->fmt($basisAmount),
            'basis' => $basisKey,
            'service_key' => (string) $setting->service_key,
            'setting_label' => $setting->label,
            'profit_currency' => $basisKey === 'ngn_notional' ? 'NGN' : null,
            'admin_profit_percent' => $this->fmt($pct),
        ];
    }

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
     * @return array<string, mixed>
     */
    public function summarize(Builder $query, Collection $settingsByKey): array
    {
        $sumFixed = 0.0;
        $sumPct = 0.0;
        $sumTotal = 0.0;
        $sumAmount = 0.0;
        $sumFee = 0.0;
        $sumPrincipal = 0.0;
        $sumProviderCost = 0.0;
        $sumRevenue = 0.0;
        $sumCommission = 0.0;
        $count = 0;

        $query->clone()->orderBy('id')->chunk(500, function ($rows) use ($settingsByKey, &$sumFixed, &$sumPct, &$sumTotal, &$sumAmount, &$sumFee, &$sumPrincipal, &$sumProviderCost, &$sumRevenue, &$sumCommission, &$count) {
            foreach ($rows as $t) {
                /** @var Transaction $t */
                $p = $this->computeForTransaction($t, $settingsByKey);
                $sumFixed += (float) $p['fixed_profit'];
                $sumPct += (float) $p['percentage_profit'];
                $sumTotal += (float) $p['net_margin_ngn'];
                $sumAmount += (float) $t->total_amount;
                $sumFee += (float) $t->fee;
                $sumPrincipal += (float) $t->amount;
                $sumProviderCost += (float) $p['provider_cost_ngn'];
                $sumRevenue += (float) $p['customer_revenue_ngn'];
                $meta = is_array($t->metadata) ? $t->metadata : [];
                if (isset($meta['estimated_commission_ngn'])) {
                    $sumCommission += (float) $meta['estimated_commission_ngn'];
                }
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
            'sum_net_margin' => $this->fmt($sumTotal),
            'sum_provider_cost' => $this->fmt($sumProviderCost),
            'sum_customer_revenue' => $this->fmt($sumRevenue),
            'sum_commission' => $this->fmt($sumCommission),
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

    protected function resolveSetting(Transaction $t, Collection $settingsByKey): ?ServiceProfitSetting
    {
        $type = (string) ($t->type ?? '');

        if ($type === 'bill_payment') {
            $cat = strtolower((string) ($t->category ?? ''));
            if ($cat === 'betting') {
                return $settingsByKey->get('bill_commission_betting') ?? $settingsByKey->get('bill_payment');
            }
            if (in_array($cat, ['airtime', 'data'], true)) {
                return $settingsByKey->get('bill_commission_airtime') ?? $settingsByKey->get('bill_payment');
            }
        }
        if ($type === 'deposit') {
            return $settingsByKey->get('palmpay_deposit') ?? $settingsByKey->get('deposit');
        }

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
