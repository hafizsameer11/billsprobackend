<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crypto_vendors', function (Blueprint $table) {
            $table->foreignId('wallet_currency_id')
                ->nullable()
                ->after('currency')
                ->constrained('wallet_currencies')
                ->nullOnDelete();
            $table->index('wallet_currency_id');
        });
    }

    public function down(): void
    {
        Schema::table('crypto_vendors', function (Blueprint $table) {
            $table->dropForeign(['wallet_currency_id']);
        });
    }
};
