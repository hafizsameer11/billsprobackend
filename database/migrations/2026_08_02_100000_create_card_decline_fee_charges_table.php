<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_decline_fee_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('virtual_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('pagocards_admin_tx_id')->nullable()->unique();
            $table->uuid('pagocards_admin_tx_uuid')->nullable();
            $table->string('provider_card_id', 64)->nullable();
            $table->string('declined_reference', 128)->nullable();
            $table->decimal('provider_cost_usd', 12, 4)->default(0);
            $table->decimal('billable_usd', 12, 4);
            $table->foreignId('platform_rate_id')->nullable()->constrained('platform_rates')->nullOnDelete();
            $table->decimal('exchange_rate_ngn_per_usd', 24, 8);
            $table->decimal('amount_ngn', 20, 4);
            $table->string('funding_source', 16); // merchant | card
            $table->string('detection_method', 32);
            $table->string('recovery_status', 16)->default('charged'); // charged | recovered | waived
            $table->foreignId('naira_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->unsignedTinyInteger('card_subsidy_sequence')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();

            $table->index(['virtual_card_id', 'funding_source', 'recovery_status'], 'cdf_card_funding_recovery_idx');
            $table->index(['user_id', 'recovery_status'], 'cdf_user_recovery_idx');
        });

        Schema::create('pagocards_admin_sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->unsignedBigInteger('last_admin_transaction_id')->default(0);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_decline_fee_charges');
        Schema::dropIfExists('pagocards_admin_sync_state');
    }
};
