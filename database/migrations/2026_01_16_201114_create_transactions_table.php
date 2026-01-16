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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('transaction_id', 100)->unique();
            $table->string('type', 50); // deposit, withdrawal, bill_payment, transfer, etc.
            $table->string('category', 50)->nullable(); // fiat_deposit, crypto_deposit, airtime, data, etc.
            $table->string('status', 50)->default('pending'); // pending, completed, failed, cancelled
            $table->string('currency', 10)->default('NGN');
            $table->decimal('amount', 20, 8);
            $table->decimal('fee', 20, 8)->default(0);
            $table->decimal('total_amount', 20, 8); // amount + fee
            $table->string('reference', 255)->nullable()->unique();
            $table->text('description')->nullable();
            $table->text('metadata')->nullable(); // Additional transaction data (JSON)
            $table->string('bank_name', 255)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('account_name', 255)->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('transaction_id');
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
        Schema::dropIfExists('transactions');
    }
};
