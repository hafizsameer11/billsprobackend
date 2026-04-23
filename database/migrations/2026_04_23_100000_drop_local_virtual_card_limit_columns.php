<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->dropColumn([
                'daily_spending_limit',
                'monthly_spending_limit',
                'daily_transaction_limit',
                'monthly_transaction_limit',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->decimal('daily_spending_limit', 20, 8)->nullable();
            $table->decimal('monthly_spending_limit', 20, 8)->nullable();
            $table->integer('daily_transaction_limit')->nullable();
            $table->integer('monthly_transaction_limit')->nullable();
        });
    }
};
