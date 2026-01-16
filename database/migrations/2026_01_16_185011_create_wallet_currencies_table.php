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
        Schema::create('wallet_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('blockchain', 255);
            $table->string('currency', 50);
            $table->string('symbol', 255)->nullable();
            $table->string('name', 255);
            $table->string('icon')->nullable();
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('naira_price', 20, 8)->nullable();
            $table->decimal('rate', 20, 8)->nullable()->comment('Exchange rate to USD');
            $table->string('token_type', 50)->nullable();
            $table->string('contract_address', 255)->nullable();
            $table->integer('decimals')->default(18);
            $table->boolean('is_token')->default(false);
            $table->string('blockchain_name', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['blockchain', 'currency']);
            $table->index('currency');
            $table->index('blockchain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_currencies');
    }
};
