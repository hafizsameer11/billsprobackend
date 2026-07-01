<?php

namespace Database\Seeders;

use App\Models\PlatformRate;
use App\Models\ServiceProfitSetting;
use App\Models\WalletCurrency;
use Illuminate\Database\Seeder;

/**
 * Applies the BillsPro Pricing & Profit Margin Table (PDF) to `platform_rates`
 * and links `service_profit_settings` for admin profit reporting.
 *
 * Prerequisites: PlatformRateSeeder, WalletCurrencySeeder, CommissionTablesSeeder.
 *
 *   php artisan db:seed --class=PdfPricingDefaultsSeeder
 */
class PdfPricingDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $pricing = config('billspro_pricing', []);
        $fxMarkup = (float) ($pricing['fx_markup_ngn'] ?? 80);

        foreach ($pricing['fiat'] ?? [] as $serviceKey => $row) {
            $this->patchRate('fiat', $serviceKey, null, null, null, $this->rowToPatch($row));
        }

        foreach ($pricing['virtual_card'] ?? [] as $serviceKey => $row) {
            $patch = $this->rowToPatch($row);
            if (! empty($row['fx_markup_ngn'])) {
                $patch['fixed_fee_ngn'] = $fxMarkup;
            }
            $this->patchOrCreateVirtualCardRate($serviceKey, $patch);
        }

        $deposit = $pricing['crypto']['deposit'] ?? [];
        $depositPatch = $this->rowToPatch($deposit);
        $this->patchRate('crypto', 'deposit', null, null, null, $depositPatch);

        foreach (['buy', 'sell'] as $tradeKey) {
            $trade = $pricing['crypto'][$tradeKey] ?? [];
            $tradePatch = $this->rowToPatch($trade);
            if (! empty($trade['fixed_fee_ngn'])) {
                $tradePatch['fixed_fee_ngn'] = $fxMarkup;
            }
            $this->patchRate('crypto', $tradeKey, null, null, null, $tradePatch);
        }

        $this->applyCryptoDepositFees($depositPatch);
        $this->applyCryptoSendFees($pricing['crypto']['send_fees'] ?? []);

        $this->syncServiceProfitSettings($pricing['service_profit_links'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function rowToPatch(array $row): array
    {
        $patch = [];
        if (isset($row['label'])) {
            $patch['display_label'] = $row['label'];
        }
        foreach ([
            'fixed_fee_ngn',
            'percentage_fee',
            'min_fee_ngn',
            'fee_usd',
            'provider_cost_ngn',
            'provider_cost_usd',
            'provider_pct',
            'provider_pct_cap_ngn',
            'exchange_rate_ngn_per_usd',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== true) {
                $patch[$key] = $row[$key];
            }
        }

        return $patch;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    protected function applyCryptoDepositFees(array $patch): void
    {
        if ($patch === []) {
            return;
        }

        foreach (WalletCurrency::query()->where('is_active', true)->cursor() as $wc) {
            $this->patchRate(
                'crypto',
                'deposit',
                null,
                (string) $wc->currency,
                (string) $wc->blockchain,
                array_merge($patch, [
                    'display_label' => 'Crypto Receive · '.strtoupper((string) $wc->currency).' ('.$wc->blockchain.')',
                ])
            );
        }
    }

    /**
     * @param  list<array{asset: string, network: string, fee_usd: float, provider_cost_usd: float}>  $sendFees
     */
    protected function applyCryptoSendFees(array $sendFees): void
    {
        $lookup = [];
        foreach ($sendFees as $fee) {
            $asset = strtoupper((string) ($fee['asset'] ?? ''));
            $network = strtolower((string) ($fee['network'] ?? ''));
            if ($asset === '' || $network === '') {
                continue;
            }
            $lookup[$asset.'|'.$network] = $fee;
        }

        foreach (WalletCurrency::query()->where('is_active', true)->cursor() as $wc) {
            $asset = strtoupper((string) $wc->currency);
            $network = strtolower((string) $wc->blockchain);
            $key = $asset.'|'.$network;
            if (! isset($lookup[$key])) {
                continue;
            }

            $fee = $lookup[$key];
            $this->patchRate('crypto', 'withdrawal', null, $asset, $network, [
                'display_label' => "{$asset} Send ({$network})",
                'fee_usd' => (float) $fee['fee_usd'],
                'provider_cost_usd' => (float) $fee['provider_cost_usd'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    protected function patchOrCreateVirtualCardRate(string $serviceKey, array $patch): void
    {
        $existing = PlatformRate::query()
            ->where('category', 'virtual_card')
            ->where('service_key', $serviceKey)
            ->whereNull('sub_service_key')
            ->whereNull('crypto_asset')
            ->whereNull('network_key')
            ->first();

        if ($existing) {
            $existing->update($patch);

            return;
        }

        $m = new PlatformRate(array_merge([
            'category' => 'virtual_card',
            'service_key' => $serviceKey,
            'is_active' => true,
        ], $patch));
        $m->slug = PlatformRate::composeSlug($m);
        $m->save();
    }

    /**
     * @param  array<string, string>  $links
     */
    protected function syncServiceProfitSettings(array $links): void
    {
        foreach ($links as $serviceKey => $slug) {
            $rate = PlatformRate::query()->where('slug', $slug)->first();
            $updates = ['linked_rate_slug' => $slug];
            if ($rate) {
                if ($rate->provider_cost_ngn !== null) {
                    $updates['provider_cost_ngn'] = $rate->provider_cost_ngn;
                }
                if ($rate->provider_cost_usd !== null) {
                    $updates['provider_cost_usd'] = $rate->provider_cost_usd;
                }
                if ($rate->provider_pct !== null) {
                    $updates['provider_pct'] = $rate->provider_pct;
                }
                if ($rate->provider_pct_cap_ngn !== null) {
                    $updates['provider_pct_cap_ngn'] = $rate->provider_pct_cap_ngn;
                }
                if ($rate->fee_usd !== null) {
                    $updates['fixed_fee'] = $rate->fee_usd;
                }
            }

            ServiceProfitSetting::query()
                ->where('service_key', $serviceKey)
                ->update($updates);
        }

        ServiceProfitSetting::query()
            ->whereIn('service_key', ['bill_commission_airtime', 'bill_commission_betting'])
            ->update(['margin_mode' => 'commission']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function patchRate(
        string $category,
        string $serviceKey,
        ?string $sub,
        ?string $asset,
        ?string $network,
        array $data
    ): void {
        $q = PlatformRate::query()
            ->where('category', $category)
            ->where('service_key', $serviceKey);

        if ($sub !== null) {
            $q->where('sub_service_key', $sub);
        } else {
            $q->whereNull('sub_service_key');
        }
        if ($asset !== null) {
            $q->where('crypto_asset', $asset);
        } else {
            $q->whereNull('crypto_asset');
        }
        if ($network !== null) {
            $q->where('network_key', $network);
        } else {
            $q->whereNull('network_key');
        }

        $row = $q->first();
        if ($row) {
            $row->update($data);
        }
    }
}
