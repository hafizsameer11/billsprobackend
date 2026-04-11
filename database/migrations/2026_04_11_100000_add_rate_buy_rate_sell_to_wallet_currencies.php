<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_currencies', function (Blueprint $table) {
            $table->decimal('rate_buy', 20, 8)->nullable()->after('rate')->comment('USD per 1 crypto unit — user buys crypto with NGN');
            $table->decimal('rate_sell', 20, 8)->nullable()->after('rate_buy')->comment('USD per 1 crypto unit — user sells crypto for NGN');
        });

        if (Schema::hasTable('wallet_currencies')) {
            DB::table('wallet_currencies')->whereNotNull('rate')->update([
                'rate_buy' => DB::raw('rate'),
                'rate_sell' => DB::raw('rate'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('wallet_currencies', function (Blueprint $table) {
            $table->dropColumn(['rate_buy', 'rate_sell']);
        });
    }
};
