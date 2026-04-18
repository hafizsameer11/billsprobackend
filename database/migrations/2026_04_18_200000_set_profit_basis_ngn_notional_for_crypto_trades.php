<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_profit_settings')
            ->whereIn('service_key', ['crypto_buy', 'crypto_sell'])
            ->update(['percentage_basis' => 'ngn_notional']);
    }

    public function down(): void
    {
        DB::table('service_profit_settings')
            ->whereIn('service_key', ['crypto_buy', 'crypto_sell'])
            ->update(['percentage_basis' => 'total_amount']);
    }
};
