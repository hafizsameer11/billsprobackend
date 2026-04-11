<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buy/sell spreads for NGN ↔ crypto live here (USD per 1 crypto unit), not on wallet_currencies.
     */
    public function up(): void
    {
        Schema::create('crypto_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_currency_id')->unique()->constrained('wallet_currencies')->cascadeOnDelete();
            $table->decimal('rate_buy', 20, 8)->comment('USD per 1 unit — user buys crypto with NGN');
            $table->decimal('rate_sell', 20, 8)->comment('USD per 1 unit — user sells crypto for NGN');
            $table->timestamps();
        });

        if (! Schema::hasTable('wallet_currencies')) {
            return;
        }

        $rows = DB::table('wallet_currencies')->select('id', 'rate', 'rate_buy', 'rate_sell')->get();
        $hasBuySell = Schema::hasColumn('wallet_currencies', 'rate_buy');

        foreach ($rows as $row) {
            $r = (float) ($row->rate ?? 0);
            $buy = $hasBuySell && $row->rate_buy !== null ? (float) $row->rate_buy : $r;
            $sell = $hasBuySell && $row->rate_sell !== null ? (float) $row->rate_sell : $r;
            if ($buy <= 0 && $r > 0) {
                $buy = $r;
            }
            if ($sell <= 0 && $r > 0) {
                $sell = $r;
            }
            if ($buy <= 0 && $sell <= 0) {
                continue;
            }
            if ($buy <= 0) {
                $buy = $sell;
            }
            if ($sell <= 0) {
                $sell = $buy;
            }

            DB::table('crypto_exchange_rates')->insert([
                'wallet_currency_id' => $row->id,
                'rate_buy' => $buy,
                'rate_sell' => $sell,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($hasBuySell) {
            Schema::table('wallet_currencies', function (Blueprint $table) {
                $table->dropColumn(['rate_buy', 'rate_sell']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wallet_currencies') && ! Schema::hasColumn('wallet_currencies', 'rate_buy')) {
            Schema::table('wallet_currencies', function (Blueprint $table) {
                $table->decimal('rate_buy', 20, 8)->nullable()->after('rate');
                $table->decimal('rate_sell', 20, 8)->nullable()->after('rate_buy');
            });
        }

        if (Schema::hasTable('crypto_exchange_rates') && Schema::hasTable('wallet_currencies')) {
            foreach (DB::table('crypto_exchange_rates')->get() as $er) {
                DB::table('wallet_currencies')->where('id', $er->wallet_currency_id)->update([
                    'rate_buy' => $er->rate_buy,
                    'rate_sell' => $er->rate_sell,
                ]);
            }
        }

        Schema::dropIfExists('crypto_exchange_rates');
    }
};
