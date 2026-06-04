<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_profit_settings', function (Blueprint $table) {
            $table->string('margin_mode', 32)->default('ledger_rule')->after('percentage_basis');
            $table->decimal('provider_cost_ngn', 20, 8)->nullable()->after('margin_mode');
            $table->decimal('provider_cost_usd', 20, 8)->nullable()->after('provider_cost_ngn');
            $table->decimal('provider_pct', 12, 4)->nullable()->after('provider_cost_usd');
            $table->decimal('provider_pct_cap_ngn', 20, 8)->nullable()->after('provider_pct');
            $table->string('linked_rate_slug', 191)->nullable()->after('provider_pct_cap_ngn');
        });

        $now = now();
        $extra = [
            ['service_key' => 'palmpay_deposit', 'label' => 'PalmPay wallet deposit', 'margin_mode' => 'charge_minus_cost', 'sort_order' => 15],
            ['service_key' => 'card_decline', 'label' => 'Virtual card decline fee', 'margin_mode' => 'charge_minus_cost', 'sort_order' => 105],
            ['service_key' => 'bill_commission_airtime', 'label' => 'Airtime / data commission', 'margin_mode' => 'commission', 'sort_order' => 35],
            ['service_key' => 'bill_commission_betting', 'label' => 'Betting commission', 'margin_mode' => 'commission', 'sort_order' => 36],
            ['service_key' => 'card_withdrawal', 'label' => 'Virtual card withdrawal', 'margin_mode' => 'ledger_rule', 'sort_order' => 102],
        ];

        foreach ($extra as $r) {
            if (! DB::table('service_profit_settings')->where('service_key', $r['service_key'])->exists()) {
                DB::table('service_profit_settings')->insert(array_merge($r, [
                    'fixed_fee' => 0,
                    'percentage' => 0,
                    'percentage_basis' => 'total_amount',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::table('service_profit_settings', function (Blueprint $table) {
            $table->dropColumn([
                'margin_mode',
                'provider_cost_ngn',
                'provider_cost_usd',
                'provider_pct',
                'provider_pct_cap_ngn',
                'linked_rate_slug',
            ]);
        });
    }
};
