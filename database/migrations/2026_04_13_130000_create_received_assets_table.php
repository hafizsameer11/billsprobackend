<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('received_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('virtual_account_id')->constrained('virtual_accounts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('crypto_deposit_address_id')->nullable()->constrained('crypto_deposit_addresses')->nullOnDelete();
            $table->string('blockchain', 64);
            $table->string('currency', 32);
            $table->decimal('amount', 24, 8);
            $table->string('tx_hash', 255);
            $table->unsignedInteger('log_index')->default(0);
            $table->string('from_address', 255)->nullable();
            $table->string('to_address', 255)->nullable();
            $table->string('source', 32)->default('tatum_webhook');
            /** Custody lifecycle: received → can be linked to sweeps later */
            $table->string('status', 32)->default('received');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tx_hash', 'log_index']);
            $table->index(['user_id', 'created_at']);
            $table->index(['virtual_account_id', 'status']);
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_assets');
    }
};
