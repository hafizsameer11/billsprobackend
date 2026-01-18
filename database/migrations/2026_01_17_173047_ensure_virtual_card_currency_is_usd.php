<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ensure virtual cards and transactions always use USD as currency.
     */
    public function up(): void
    {
        // Update any existing records to USD if they're not already
        DB::table('virtual_cards')->where('currency', '!=', 'USD')->orWhereNull('currency')->update(['currency' => 'USD']);
        DB::table('virtual_card_transactions')->where('currency', '!=', 'USD')->orWhereNull('currency')->update(['currency' => 'USD']);

        // Make currency non-nullable and ensure default is USD
        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->string('currency', 10)->default('USD')->nullable(false)->change();
        });

        Schema::table('virtual_card_transactions', function (Blueprint $table) {
            $table->string('currency', 10)->default('USD')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->string('currency', 10)->default('USD')->nullable()->change();
        });

        Schema::table('virtual_card_transactions', function (Blueprint $table) {
            $table->string('currency', 10)->default('USD')->nullable()->change();
        });
    }
};
