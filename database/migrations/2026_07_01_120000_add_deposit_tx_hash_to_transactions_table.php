<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('deposit_tx_hash', 128)->nullable()->after('reference');
            $table->unique(['type', 'deposit_tx_hash'], 'transactions_type_deposit_tx_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_type_deposit_tx_hash_unique');
            $table->dropColumn('deposit_tx_hash');
        });
    }
};
