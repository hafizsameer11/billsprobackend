<?php

namespace App\Services\Admin;

use App\Models\PlatformRate;
use App\Services\Platform\PlatformRateMarginEstimator;

class PricingCatalogService
{
    public function __construct(
        protected PlatformRateMarginEstimator $estimator,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function buildCatalog(): array
    {
        $rows = [];

        $rates = PlatformRate::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('service_key')
            ->orderBy('id')
            ->get();

        foreach ($rates as $r) {
            if (in_array($r->service_key, ['withdraw'], true) && $r->category === 'virtual_card') {
                continue;
            }

            $est = $this->estimator->estimate($r, 10_000, 100);
            $label = $r->display_label ?: $this->defaultLabel($r);

            $rows[] = [
                'source' => 'platform_rate',
                'rate_id' => $r->id,
                'slug' => $r->slug,
                'category' => $r->category,
                'service_key' => $r->service_key,
                'label' => $label,
                'provider_cost_display' => $this->formatCostDisplay($r, $est),
                'billspro_charge_display' => $this->formatChargeDisplay($r, $est),
                'estimated_profit_display' => $this->formatProfitDisplay($est),
                'edit_path' => $this->editPath($r),
            ];
        }

        $rows[] = [
            'source' => 'commission',
            'label' => 'Airtime & Data (commission)',
            'provider_cost_display' => 'Vendor commission (tiered)',
            'billspro_charge_display' => 'User pays standard rate',
            'estimated_profit_display' => 'Commission % × volume',
            'edit_path' => '/rates/commissions',
        ];

        $rows[] = [
            'source' => 'commission',
            'label' => 'Betting wallet funding (commission)',
            'provider_cost_display' => 'Platform commission (after WHT)',
            'billspro_charge_display' => 'User pays standard rate',
            'estimated_profit_display' => 'Commission % × volume',
            'edit_path' => '/rates/commissions',
        ];

        return $rows;
    }

    protected function defaultLabel(PlatformRate $r): string
    {
        $parts = array_filter([
            ucfirst(str_replace('_', ' ', $r->category)),
            $r->service_key,
            $r->sub_service_key,
            $r->crypto_asset,
            $r->network_key,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, float|null>  $est
     */
    protected function formatProfitDisplay(array $est): string
    {
        if ($est['estimated_profit_ngn'] !== null && $est['estimated_profit_ngn'] != 0) {
            return '₦'.number_format($est['estimated_profit_ngn'], 2);
        }
        if ($est['estimated_profit_usd'] !== null && $est['estimated_profit_usd'] != 0) {
            return '$'.number_format($est['estimated_profit_usd'], 2);
        }

        return '—';
    }

    /**
     * @param  array<string, float|null>  $est
     */
    protected function formatChargeDisplay(PlatformRate $r, array $est): string
    {
        $parts = [];
        if ($est['charge_ngn'] > 0) {
            $parts[] = '₦'.number_format($est['charge_ngn'], 2);
        }
        if ($est['charge_usd'] > 0) {
            $parts[] = '$'.number_format($est['charge_usd'], 2);
        }
        if ($r->percentage_fee !== null) {
            $parts[] = (float) $r->percentage_fee.'%';
        }

        return $parts !== [] ? implode(' + ', $parts) : '₦0 / $0';
    }

    /**
     * @param  array<string, float|null>  $est
     */
    protected function formatCostDisplay(PlatformRate $r, array $est): string
    {
        $parts = [];
        if ($est['provider_cost_ngn'] > 0) {
            $parts[] = '₦'.number_format($est['provider_cost_ngn'], 2);
        }
        if ($est['provider_cost_usd'] > 0) {
            $parts[] = '$'.number_format($est['provider_cost_usd'], 2);
        }
        if ($r->provider_pct !== null) {
            $parts[] = (float) $r->provider_pct.'%';
            if ($r->provider_pct_cap_ngn !== null) {
                $parts[] = 'cap ₦'.number_format((float) $r->provider_pct_cap_ngn, 0);
            }
        }

        return $parts !== [] ? implode(' + ', $parts) : '—';
    }

    protected function editPath(PlatformRate $r): string
    {
        if ($r->category === 'virtual_card' && in_array($r->service_key, ['visa_creation', 'visa_fund'], true)) {
            return '/rates/visa-virtual-card';
        }

        return '/rates';
    }
}
