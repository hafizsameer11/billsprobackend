<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('palm_pay_deposit_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposit_id')->constrained('deposits')->cascadeOnDelete();
            $table->string('merchant_order_id', 32)->unique();
            $table->string('palmpay_order_no', 64)->nullable()->unique();
            $table->unsignedTinyInteger('order_status')->default(1);
            $table->json('virtual_account')->nullable();
            $table->text('checkout_url')->nullable();
            $table->json('raw_create_response')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('deposit_id');
        });

        Schema::create('palm_pay_bill_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('fiat_wallet_id')->constrained('fiat_wallets')->cascadeOnDelete();
            $table->string('out_order_no', 64)->unique();
            $table->string('palmpay_order_no', 64)->nullable()->unique();
            $table->string('scene_code', 20);
            $table->string('biller_id', 100);
            $table->string('item_id', 100);
            $table->string('recharge_account', 50);
            $table->decimal('amount', 20, 8);
            $table->string('currency', 10)->default('NGN');
            $table->string('status', 30)->default('pending');
            $table->string('palmpay_status', 30)->nullable();
            $table->boolean('refunded')->default(false);
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reason', 255)->nullable();
            $table->json('provider_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('out_order_no');
        });

        Schema::create('palm_pay_raw_webhooks', function (Blueprint $table) {
            $table->id();
            $table->longText('raw_data');
            $table->text('headers')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['processed', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palm_pay_raw_webhooks');
        Schema::dropIfExists('palm_pay_bill_orders');
        Schema::dropIfExists('palm_pay_deposit_orders');
    }
};
