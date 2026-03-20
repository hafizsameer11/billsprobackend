<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1 launch: ETH + USDT (ERC-20), BTC, DOGE, BSC USDT, Tron USDT — deactivate all other wallet_currencies rows.
     */
    public function up(): void
    {
        if (! Schema::hasTable('wallet_currencies')) {
            return;
        }

        $launch = [
            ['ethereum', 'ETH'],
            ['ethereum', 'USDT'],
            ['bitcoin', 'BTC'],
            ['dogecoin', 'DOGE'],
            ['bsc', 'USDT_BSC'],
            ['tron', 'USDT_TRON'],
        ];

        DB::table('wallet_currencies')->update(['is_active' => false]);

        foreach ($launch as [$blockchain, $currency]) {
            DB::table('wallet_currencies')
                ->where('blockchain', $blockchain)
                ->where('currency', $currency)
                ->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_currencies')) {
            return;
        }

        DB::table('wallet_currencies')->update(['is_active' => true]);
    }
};
