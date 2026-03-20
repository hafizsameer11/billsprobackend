<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_wallet_id')->nullable()->constrained('master_wallets')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('User for external_send; null for flush');
            $table->string('type', 32)->index()->comment('external_send, flush');
            $table->string('blockchain', 64);
            $table->string('currency', 32);
            $table->string('from_address', 255)->nullable();
            $table->string('to_address', 255);
            $table->decimal('amount', 28, 18);
            $table->decimal('network_fee', 28, 18)->nullable();
            $table->string('tx_hash', 255)->nullable()->index();
            $table->string('internal_transaction_id', 64)->nullable()->index()->comment('transactions.transaction_id');
            $table->foreignId('crypto_sweep_order_id')->nullable()->constrained('crypto_sweep_orders')->nullOnDelete();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_wallet_transactions');
    }
};
