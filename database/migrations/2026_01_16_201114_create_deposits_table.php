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
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('bank_account_id')->nullable()->constrained()->onDelete('set null');
            $table->unsignedBigInteger('transaction_id')->nullable()->unique();
            $table->string('deposit_reference', 100)->unique();
            $table->string('currency', 10)->default('NGN');
            $table->decimal('amount', 20, 8);
            $table->decimal('fee', 20, 8)->default(0);
            $table->decimal('total_amount', 20, 8); // amount + fee
            $table->string('status', 50)->default('pending'); // pending, completed, failed, cancelled
            $table->string('payment_method', 50)->default('bank_transfer'); // bank_transfer, instant_transfer, etc.
            $table->text('metadata')->nullable(); // Additional deposit data
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('deposit_reference');
            $table->index('status');
            $table->index('created_at');
        });

        // Add foreign key constraint after transactions table is created
        Schema::table('deposits', function (Blueprint $table) {
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
