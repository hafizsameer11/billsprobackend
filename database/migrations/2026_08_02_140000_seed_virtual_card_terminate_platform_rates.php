<?php

use App\Models\PlatformRate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $sellRate = (float) config('virtual_card.terminate_sell_rate_ngn_per_usd', 1420.0);
        $feeUsd = (float) config('virtual_card.terminate_fee_usd', 1.0);

        foreach (['terminate', 'visa_terminate'] as $serviceKey) {
            $existing = PlatformRate::query()
                ->where('category', 'virtual_card')
                ->where('service_key', $serviceKey)
                ->whereNull('sub_service_key')
                ->whereNull('crypto_asset')
                ->whereNull('network_key')
                ->first();

            if ($existing) {
                continue;
            }

            $m = new PlatformRate([
                'category' => 'virtual_card',
                'service_key' => $serviceKey,
                'exchange_rate_ngn_per_usd' => $sellRate,
                'fixed_fee_ngn' => 0,
                'fee_usd' => $feeUsd,
                'is_active' => true,
                'display_label' => $serviceKey === 'visa_terminate'
                    ? 'Visa Card Termination Refund'
                    : 'Mastercard Card Termination Refund',
            ]);
            $m->slug = PlatformRate::composeSlug($m);
            $m->save();
        }
    }

    public function down(): void
    {
        PlatformRate::query()
            ->where('category', 'virtual_card')
            ->whereIn('service_key', ['terminate', 'visa_terminate'])
            ->delete();
    }
};
