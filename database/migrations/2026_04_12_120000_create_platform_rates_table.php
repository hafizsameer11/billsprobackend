<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_rates', function (Blueprint $table) {
            $table->id();
            $table->string('category', 32); // fiat | crypto | virtual_card
            $table->string('service_key', 64);
            $table->string('sub_service_key', 64)->nullable(); // bill category code, etc.
            $table->string('crypto_asset', 32)->nullable(); // USDT, BTC
            $table->string('network_key', 64)->nullable(); // blockchain code, e.g. ETH, BSC
            $table->decimal('exchange_rate_ngn_per_usd', 24, 8)->nullable();
            $table->decimal('fixed_fee_ngn', 20, 4)->default(0);
            $table->decimal('percentage_fee', 12, 4)->nullable();
            $table->decimal('min_fee_ngn', 20, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('slug', 191)->unique();
            $table->timestamps();

            $table->index(['category', 'service_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_rates');
    }
};
