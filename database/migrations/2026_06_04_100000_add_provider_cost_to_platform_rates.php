<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_rates', function (Blueprint $table) {
            $table->decimal('provider_cost_ngn', 20, 4)->nullable()->after('fee_usd');
            $table->decimal('provider_cost_usd', 20, 8)->nullable()->after('provider_cost_ngn');
            $table->decimal('provider_pct', 12, 4)->nullable()->after('provider_cost_usd');
            $table->decimal('provider_pct_cap_ngn', 20, 4)->nullable()->after('provider_pct');
            $table->string('display_label', 120)->nullable()->after('provider_pct_cap_ngn');
        });
    }

    public function down(): void
    {
        Schema::table('platform_rates', function (Blueprint $table) {
            $table->dropColumn([
                'provider_cost_ngn',
                'provider_cost_usd',
                'provider_pct',
                'provider_pct_cap_ngn',
                'display_label',
            ]);
        });
    }
};
