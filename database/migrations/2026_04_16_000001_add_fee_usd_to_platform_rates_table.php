<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_rates', function (Blueprint $table) {
            $table->decimal('fee_usd', 12, 4)->nullable()->after('min_fee_ngn');
        });

        $defaultUsd = (float) config('virtual_card.creation_fee_usd', 3.0);

        DB::table('platform_rates')
            ->where('category', 'virtual_card')
            ->where('service_key', 'creation')
            ->update([
                'fee_usd' => $defaultUsd,
                'fixed_fee_ngn' => 0,
            ]);
    }

    public function down(): void
    {
        Schema::table('platform_rates', function (Blueprint $table) {
            $table->dropColumn('fee_usd');
        });
    }
};
