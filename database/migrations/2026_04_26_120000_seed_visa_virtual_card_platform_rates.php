<?php

use App\Models\PlatformRate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $usdToNgn = (float) config('virtual_card.usd_to_ngn_rate', 1500.0);
        $visaCreationUsd = 6.0;
        $visaFundFlatUsd = 1.0;
        $visaFundPct = 1.0;

        foreach (
            [
                [
                    'category' => 'virtual_card',
                    'service_key' => 'visa_creation',
                    'sub_service_key' => null,
                    'crypto_asset' => null,
                    'network_key' => null,
                    'exchange_rate_ngn_per_usd' => $usdToNgn,
                    'fixed_fee_ngn' => 0,
                    'percentage_fee' => null,
                    'min_fee_ngn' => null,
                    'fee_usd' => $visaCreationUsd,
                ],
                [
                    'category' => 'virtual_card',
                    'service_key' => 'visa_fund',
                    'sub_service_key' => null,
                    'crypto_asset' => null,
                    'network_key' => null,
                    'exchange_rate_ngn_per_usd' => $usdToNgn,
                    'fixed_fee_ngn' => 0,
                    'percentage_fee' => $visaFundPct,
                    'min_fee_ngn' => null,
                    'fee_usd' => $visaFundFlatUsd,
                ],
            ] as $data
        ) {
            $m = new PlatformRate($data);
            $slug = PlatformRate::composeSlug($m);
            PlatformRate::query()->updateOrCreate(
                ['slug' => $slug],
                array_merge($data, ['slug' => $slug, 'is_active' => true])
            );
        }
    }

    public function down(): void
    {
        foreach (['visa_creation', 'visa_fund'] as $key) {
            $m = new PlatformRate([
                'category' => 'virtual_card',
                'service_key' => $key,
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
            ]);
            PlatformRate::query()->where('slug', PlatformRate::composeSlug($m))->delete();
        }
    }
};
