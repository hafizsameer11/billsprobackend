<?php

use App\Models\PlatformRate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $usdToNgn = (float) config('virtual_card.usd_to_ngn_rate', 1500.0);
        $fundFlatUsd = (float) config('virtual_card.fund_load_flat_fee_usd', 1.0);
        $fundPct = (float) config('virtual_card.fund_load_percent', 1.0);
        $terminateSellRate = (float) config('virtual_card.terminate_sell_rate_ngn_per_usd', 1420.0);
        $terminateFeeUsd = (float) config('virtual_card.terminate_fee_usd', 1.0);

        $rows = [
            [
                'service_key' => 'visa_493_creation',
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fee_usd' => 8.0,
                'percentage_fee' => null,
                'display_label' => 'Visa (493) Card Issuance',
            ],
            [
                'service_key' => 'visa_493_fund',
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fee_usd' => $fundFlatUsd,
                'percentage_fee' => $fundPct,
                'display_label' => 'Visa (493) Card Funding',
            ],
            [
                'service_key' => 'visa_493_terminate',
                'exchange_rate_ngn_per_usd' => $terminateSellRate,
                'fee_usd' => $terminateFeeUsd,
                'percentage_fee' => null,
                'display_label' => 'Visa (493) Card Termination Refund',
            ],
        ];

        foreach ($rows as $row) {
            $existing = PlatformRate::query()
                ->where('category', 'virtual_card')
                ->where('service_key', $row['service_key'])
                ->whereNull('sub_service_key')
                ->whereNull('crypto_asset')
                ->whereNull('network_key')
                ->first();

            if ($existing) {
                continue;
            }

            $m = new PlatformRate([
                'category' => 'virtual_card',
                'service_key' => $row['service_key'],
                'exchange_rate_ngn_per_usd' => $row['exchange_rate_ngn_per_usd'],
                'fixed_fee_ngn' => 0,
                'percentage_fee' => $row['percentage_fee'],
                'fee_usd' => $row['fee_usd'],
                'is_active' => true,
                'display_label' => $row['display_label'],
            ]);
            $m->slug = PlatformRate::composeSlug($m);
            $m->save();
        }
    }

    public function down(): void
    {
        PlatformRate::query()
            ->where('category', 'virtual_card')
            ->whereIn('service_key', ['visa_493_creation', 'visa_493_fund', 'visa_493_terminate'])
            ->delete();
    }
};
