<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tatum V3 native gas on BNB Smart Chain uses currency symbol "BSC" (not "BNB"; "BNB" is Beacon chain /v3/bnb/transaction).
 * Align ledger `wallet_currencies.currency` and `virtual_accounts.currency` with Tatum docs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('wallet_currencies')
            ->whereRaw('LOWER(blockchain) = ?', ['bsc'])
            ->where('currency', 'BNB')
            ->update([
                'currency' => 'BSC',
                'symbol' => 'BSC',
                'name' => 'BNB Smart Chain (native)',
                'icon' => 'wallet_symbols/bsc.png',
                'updated_at' => now(),
            ]);

        DB::table('virtual_accounts')
            ->whereRaw('LOWER(blockchain) = ?', ['bsc'])
            ->where('currency', 'BNB')
            ->update([
                'currency' => 'BSC',
                'accounting_currency' => 'BSC',
                'updated_at' => now(),
            ]);

        if (Schema::hasTable('platform_rates')) {
            DB::table('platform_rates')
                ->where('network_key', 'bsc')
                ->where('crypto_asset', 'BNB')
                ->update(['crypto_asset' => 'BSC', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('wallet_currencies')
            ->whereRaw('LOWER(blockchain) = ?', ['bsc'])
            ->where('currency', 'BSC')
            ->whereNull('contract_address')
            ->update([
                'currency' => 'BNB',
                'symbol' => 'BNB',
                'name' => 'BNB',
                'icon' => 'wallet_symbols/bnb.png',
                'updated_at' => now(),
            ]);

        DB::table('virtual_accounts')
            ->whereRaw('LOWER(blockchain) = ?', ['bsc'])
            ->where('currency', 'BSC')
            ->update([
                'currency' => 'BNB',
                'accounting_currency' => 'BNB',
                'updated_at' => now(),
            ]);

        if (Schema::hasTable('platform_rates')) {
            DB::table('platform_rates')
                ->where('network_key', 'bsc')
                ->where('crypto_asset', 'BSC')
                ->update(['crypto_asset' => 'BNB', 'updated_at' => now()]);
        }
    }
};
