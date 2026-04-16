<?php

namespace Database\Seeders;

use App\Models\PlatformRate;
use Illuminate\Database\Seeder;

class PlatformRateSeeder extends Seeder
{
    public function run(): void
    {
        $usdToNgn = (float) config('virtual_card.usd_to_ngn_rate', 1500.0);
        $creationFeeUsd = (float) config('virtual_card.creation_fee_usd', 3.0);
        $fundProc = (float) config('virtual_card.fund_processing_fee_ngn', 500.0);
        $fundPct = (float) config('virtual_card.fund_load_percent', 1.0);

        $rows = [
            [
                'category' => 'fiat',
                'service_key' => 'deposit',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 200,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
            ],
            [
                'category' => 'fiat',
                'service_key' => 'withdrawal',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 200,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
            ],
            [
                'category' => 'fiat',
                'service_key' => 'bill_payment',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => 1.0,
                'min_fee_ngn' => 20,
            ],
            [
                'category' => 'crypto',
                'service_key' => 'buy',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ],
            [
                'category' => 'crypto',
                'service_key' => 'sell',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ],
            [
                'category' => 'crypto',
                'service_key' => 'withdrawal',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => 3.0,
            ],
            [
                'category' => 'virtual_card',
                'service_key' => 'creation',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => $creationFeeUsd,
            ],
            [
                'category' => 'virtual_card',
                'service_key' => 'fund',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fixed_fee_ngn' => $fundProc,
                'percentage_fee' => $fundPct,
                'min_fee_ngn' => null,
            ],
            [
                'category' => 'virtual_card',
                'service_key' => 'withdraw',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
            ],
        ];

        foreach ($rows as $data) {
            $m = new PlatformRate($data);
            $slug = PlatformRate::composeSlug($m);
            PlatformRate::query()->updateOrCreate(
                ['slug' => $slug],
                array_merge($data, ['slug' => $slug, 'is_active' => true])
            );
        }
    }
}
