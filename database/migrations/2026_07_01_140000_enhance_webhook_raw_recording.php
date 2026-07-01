<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tatum_raw_webhooks', function (Blueprint $table) {
            $table->string('channel', 32)->nullable()->after('id');
            $table->string('tx_id', 255)->nullable()->after('raw_data');
            $table->string('subscription_type', 128)->nullable()->after('tx_id');
            $table->string('receiving_address', 255)->nullable()->after('subscription_type');
            $table->string('outcome', 64)->nullable()->after('error_message');

            $table->index('tx_id');
            $table->index('outcome');
            $table->index('subscription_type');
        });

        Schema::create('webhook_raw_payloads', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('channel', 32)->nullable();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('tx_id', 255)->nullable();
            $table->string('subscription_type', 128)->nullable();
            $table->string('receiving_address', 255)->nullable();
            $table->unsignedBigInteger('tatum_raw_webhook_id')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('tx_id');
            $table->index('tatum_raw_webhook_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_raw_payloads');

        Schema::table('tatum_raw_webhooks', function (Blueprint $table) {
            $table->dropIndex(['tx_id']);
            $table->dropIndex(['outcome']);
            $table->dropIndex(['subscription_type']);
            $table->dropColumn([
                'channel',
                'tx_id',
                'subscription_type',
                'receiving_address',
                'outcome',
            ]);
        });
    }
};
