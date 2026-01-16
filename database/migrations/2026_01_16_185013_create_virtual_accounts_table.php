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
        Schema::create('virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('currency_id')->nullable()->constrained('wallet_currencies')->onDelete('set null');
            $table->string('blockchain', 255);
            $table->string('currency', 50);
            $table->string('customer_id', 255)->nullable();
            $table->string('account_id', 255)->unique();
            $table->string('account_code', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('frozen')->default(false);
            $table->string('account_balance', 255)->default('0');
            $table->string('available_balance', 255)->default('0');
            $table->string('xpub', 500)->nullable();
            $table->string('accounting_currency', 50)->nullable();
            $table->timestamps();
            
            $table->unique(['user_id', 'blockchain', 'currency']);
            $table->index('user_id');
            $table->index('currency_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('virtual_accounts');
    }
};
