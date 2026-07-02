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
        $ratesBySlug = PlatformRate::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('slug');

        $rows = [];
        $catalog = config('billspro_pricing.catalog', []);
        $sendFees = config('billspro_pricing.crypto.send_fees', []);

        foreach ($catalog as $entry) {
            $group = (string) ($entry['group'] ?? 'Other');

            if (isset($entry['type'])) {
                $row = $this->buildSyntheticRow((string) $entry['type'], $ratesBySlug, $group);
                if ($row !== null) {
                    $rows[] = $row;
                }

                continue;
            }

            $slug = (string) ($entry['slug'] ?? '');
            $rate = $ratesBySlug->get($slug);
            if (! $rate) {
                continue;
            }

            $rows[] = $this->buildRateRow($rate, $group);
        }

        foreach ($sendFees as $fee) {
            $asset = strtoupper((string) ($fee['asset'] ?? ''));
            $network = strtolower((string) ($fee['network'] ?? ''));
            if ($asset === '' || $network === '') {
                continue;
            }

            $slug = "crypto|withdrawal||{$asset}|{$network}";
            $rate = $ratesBySlug->get($slug);
            if (! $rate) {
                continue;
            }

            $rows[] = $this->buildRateRow($rate, 'Crypto');
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, PlatformRate>  $ratesBySlug
     * @return array<string, mixed>|null
     */
    protected function buildSyntheticRow(string $type, $ratesBySlug, string $group): ?array
    {
        return match ($type) {
            'crypto_buy_sell' => $this->buildCryptoBuySellRow($ratesBySlug, $group),
            'commission_airtime' => [
                'source' => 'commission',
                'group' => $group,
                'label' => 'Airtime & Data (commission)',
                'provider_cost_display' => 'Vendor commission (tiered)',
                'billspro_charge_display' => 'User pays standard rate',
                'estimated_profit_display' => 'Commission % × volume',
                'edit_path' => '/rates/commissions',
            ],
            'commission_betting' => [
                'source' => 'commission',
                'group' => $group,
                'label' => 'Betting wallet funding (commission)',
                'provider_cost_display' => 'Platform commission (after WHT)',
                'billspro_charge_display' => 'User pays standard rate',
                'estimated_profit_display' => 'Commission % × volume',
                'edit_path' => '/rates/commissions',
            ],
            default => null,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<string, PlatformRate>  $ratesBySlug
     * @return array<string, mixed>
     */
    protected function buildCryptoBuySellRow($ratesBySlug, string $group): array
    {
        $buy = $ratesBySlug->get('crypto|buy|||');
        $fx = $buy ? (float) $buy->fixed_fee_ngn : (float) config('billspro_pricing.fx_markup_ngn', 80);

        return [
            'source' => 'platform_rate',
            'rate_id' => $buy?->id,
            'slug' => 'crypto|buy|||',
            'category' => 'crypto',
            'service_key' => 'buy',
            'group' => $group,
            'label' => 'Crypto Buy/Sell',
            'provider_cost_display' => 'Market rate',
            'billspro_charge_display' => $fx > 0
                ? '₦'.number_format($fx, 0).' FX markup'
                : 'Market rate + FX markup',
            'estimated_profit_display' => $fx > 0
                ? '₦'.number_format($fx, 0).' FX spread'
                : 'FX spread profit',
            'edit_path' => '/rates',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRateRow(PlatformRate $r, string $group): array
    {
        [$sampleNgn, $sampleUsd] = $this->catalogSampleAmounts($r);
        $est = $this->estimator->estimate($r, $sampleNgn, $sampleUsd);

        return [
            'source' => 'platform_rate',
            'rate_id' => $r->id,
            'slug' => $r->slug,
            'category' => $r->category,
            'service_key' => $r->service_key,
            'group' => $group,
            'label' => $r->display_label ?: $this->defaultLabel($r),
            'provider_cost_display' => $this->formatCostDisplay($r),
            'billspro_charge_display' => $this->formatChargeDisplay($r),
            'estimated_profit_display' => $this->formatProfitDisplay($r, $est),
            'edit_path' => $this->editPath($r),
        ];
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
    protected function formatProfitDisplay(PlatformRate $r, array $est): string
    {
        if ($this->isCardFundRate($r) && (float) $r->fixed_fee_ngn > 0) {
            return '₦'.number_format((float) $r->fixed_fee_ngn, 0).' FX spread';
        }

        if ($r->category === 'crypto' && in_array($r->service_key, ['buy', 'sell'], true) && (float) $r->fixed_fee_ngn > 0) {
            return '₦'.number_format((float) $r->fixed_fee_ngn, 0).' FX spread';
        }

        if ($r->category === 'fiat' && $r->service_key === 'deposit') {
            return 'Loss leader';
        }

        if ($r->category === 'virtual_card' && in_array($r->service_key, ['creation', 'visa_creation', 'decline_fee', 'visa_decline_fee'], true)) {
            $fee = $r->fee_usd !== null ? (float) $r->fee_usd : 0.0;
            $cost = $r->provider_cost_usd !== null ? (float) $r->provider_cost_usd : 0.0;
            $profit = round($fee - $cost, 2);
            if ($profit != 0.0) {
                return '$'.number_format($profit, 2);
            }
        }

        if ($r->category === 'crypto' && $r->service_key === 'withdrawal') {
            $fee = $r->fee_usd !== null ? (float) $r->fee_usd : 0.0;
            $cost = $r->provider_cost_usd !== null ? (float) $r->provider_cost_usd : 0.0;
            if ($cost > 0 && $fee > $cost) {
                return '~$'.number_format($fee - $cost, 2);
            }
            if ($fee > 0 && $cost <= 0) {
                return '~$'.number_format($fee, 2);
            }
        }

        if ($r->category === 'fiat' && $r->service_key === 'withdrawal') {
            $fee = (float) $r->fixed_fee_ngn;
            $cost = $r->provider_cost_ngn !== null ? (float) $r->provider_cost_ngn : 0.0;
            if ($fee > $cost) {
                return '₦'.number_format($fee - $cost, 2);
            }
        }

        if ($est['estimated_profit_ngn'] !== null && $est['estimated_profit_ngn'] != 0) {
            return '₦'.number_format($est['estimated_profit_ngn'], 2);
        }
        if ($est['estimated_profit_usd'] !== null && $est['estimated_profit_usd'] != 0) {
            return '$'.number_format($est['estimated_profit_usd'], 2);
        }

        return '—';
    }

    protected function formatChargeDisplay(PlatformRate $r): string
    {
        if ($this->isCardFundRate($r)) {
            return $this->formatCardFundChargeDisplay($r);
        }

        if ($r->category === 'virtual_card' && in_array($r->service_key, ['creation', 'visa_creation', 'decline_fee', 'visa_decline_fee'], true)) {
            if ($r->service_key === 'decline_fee' || $r->service_key === 'visa_decline_fee') {
                return $r->fee_usd !== null
                    ? '2nd decline: $'.number_format((float) $r->fee_usd, 2)
                    : '—';
            }

            return $r->fee_usd !== null
                ? '$'.number_format((float) $r->fee_usd, 2)
                : '—';
        }

        if ($r->category === 'crypto' && $r->service_key === 'deposit') {
            return $r->fee_usd !== null && (float) $r->fee_usd > 0
                ? '$'.number_format((float) $r->fee_usd, 2)
                : '—';
        }

        if ($r->category === 'crypto' && $r->service_key === 'withdrawal') {
            return $this->formatCryptoSendChargeDisplay($r);
        }

        if ($r->category === 'fiat' && $r->service_key === 'deposit') {
            return '₦0';
        }

        if ($r->category === 'fiat' && $r->service_key === 'withdrawal') {
            return (float) $r->fixed_fee_ngn > 0
                ? '₦'.number_format((float) $r->fixed_fee_ngn, 2)
                : '₦0';
        }

        return '—';
    }

    protected function formatCostDisplay(PlatformRate $r): string
    {
        if ($r->category === 'fiat' && $r->service_key === 'deposit') {
            if ($r->provider_pct !== null && (float) $r->provider_pct > 0) {
                $parts = [(float) $r->provider_pct.'%'];
                if ($r->provider_pct_cap_ngn !== null && (float) $r->provider_pct_cap_ngn > 0) {
                    $parts[] = 'cap ₦'.number_format((float) $r->provider_pct_cap_ngn, 0);
                }

                return implode(' ', $parts);
            }
        }

        if ($r->category === 'crypto' && $r->service_key === 'deposit') {
            return '$0';
        }

        if ($r->category === 'crypto' && $r->service_key === 'withdrawal') {
            return $this->formatCryptoSendCostDisplay($r);
        }

        $parts = [];
        if ($r->provider_cost_ngn !== null && (float) $r->provider_cost_ngn > 0) {
            $parts[] = '₦'.number_format((float) $r->provider_cost_ngn, 2);
        }
        if ($r->provider_cost_usd !== null && (float) $r->provider_cost_usd > 0) {
            $prefix = $r->category === 'crypto' && $r->service_key === 'withdrawal' ? '~' : '';
            $parts[] = $prefix.'$'.number_format((float) $r->provider_cost_usd, 2);
        }
        if ($r->provider_pct !== null && (float) $r->provider_pct > 0) {
            $parts[] = (float) $r->provider_pct.'%';
        }

        return $parts !== [] ? implode(' + ', $parts) : '—';
    }

    protected function formatCardFundChargeDisplay(PlatformRate $r): string
    {
        $parts = [];
        if ($r->fee_usd !== null && (float) $r->fee_usd > 0) {
            $parts[] = '$'.number_format((float) $r->fee_usd, 2);
        }
        if ((float) $r->fixed_fee_ngn > 0) {
            $parts[] = '₦'.number_format((float) $r->fixed_fee_ngn, 0).' FX markup';
        }

        return $parts !== [] ? implode(' + ', $parts) : '—';
    }

    protected function formatCryptoSendChargeDisplay(PlatformRate $r): string
    {
        $asset = strtoupper((string) ($r->crypto_asset ?? ''));
        $isVariableGas = in_array($asset, ['TRX', 'USDT_TRON'], true);

        if ($isVariableGas) {
            $flat = $r->fee_usd !== null ? (float) $r->fee_usd : 1.0;

            return 'Gas fee + $'.number_format($flat, 2);
        }

        if ($r->fee_usd !== null && (float) $r->fee_usd > 0) {
            return '$'.number_format((float) $r->fee_usd, 2);
        }

        return '—';
    }

    protected function formatCryptoSendCostDisplay(PlatformRate $r): string
    {
        $asset = strtoupper((string) ($r->crypto_asset ?? ''));
        if (in_array($asset, ['TRX', 'USDT_TRON'], true)) {
            return 'Variable';
        }

        if ($r->provider_cost_usd !== null && (float) $r->provider_cost_usd > 0) {
            return '~$'.number_format((float) $r->provider_cost_usd, 2);
        }

        return '—';
    }

    protected function isCardFundRate(PlatformRate $r): bool
    {
        return $r->category === 'virtual_card' && in_array($r->service_key, ['fund', 'visa_fund'], true);
    }

    protected function editPath(PlatformRate $r): string
    {
        if ($r->category === 'virtual_card' && in_array($r->service_key, ['visa_creation', 'visa_fund'], true)) {
            return '/rates/visa-virtual-card';
        }

        if ($r->category === 'crypto' && $r->service_key === 'withdrawal') {
            return '/rates';
        }

        return '/rates';
    }

    /**
     * @return array{0: float, 1: float}
     */
    protected function catalogSampleAmounts(PlatformRate $r): array
    {
        if ($r->category === 'fiat') {
            return [10_000.0, 0.0];
        }

        return [0.0, 100.0];
    }
}
