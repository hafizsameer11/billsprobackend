<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('virtual_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_card_id')->constrained('virtual_cards')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('set null');
            $table->string('type', 50); // fund, withdraw, payment, refund
            $table->string('status', 50)->default('pending'); // pending, completed, failed, cancelled
            $table->string('currency', 10)->default('USD'); // Always USD for virtual card transactions
            $table->decimal('amount', 20, 8); // Amount in USD
            $table->decimal('fee', 20, 8)->default(0);
            $table->decimal('total_amount', 20, 8); // amount + fee
            $table->string('payment_wallet_type', 50)->nullable(); // naira_wallet, crypto_wallet
            $table->string('payment_wallet_currency', 10)->nullable(); // NGN, USD, etc.
            $table->decimal('exchange_rate', 20, 8)->nullable();
            $table->string('reference', 255)->nullable()->unique();
            $table->text('description')->nullable();
            $table->text('metadata')->nullable(); // Additional transaction data
            $table->timestamps();
            
            $table->index('virtual_card_id');
            $table->index('user_id');
            $table->index('type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('virtual_card_transactions');
    }
};
