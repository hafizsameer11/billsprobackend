<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->string('provider', 50)->nullable()->after('card_type');
            $table->string('provider_card_id', 191)->nullable()->after('provider');
            $table->string('provider_status', 50)->nullable()->after('provider_card_id');
            $table->json('provider_payload')->nullable()->after('metadata');

            $table->index('provider_card_id');
            $table->index('provider_status');
            //
        });

        Schema::table('virtual_card_transactions', function (Blueprint $table) {
            $table->string('provider_transaction_id', 191)->nullable()->after('transaction_id');
            $table->json('provider_payload')->nullable()->after('metadata');

            $table->index('provider_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('virtual_card_transactions', function (Blueprint $table) {
            $table->dropIndex(['provider_transaction_id']);
            $table->dropColumn(['provider_transaction_id', 'provider_payload']);
        });

        Schema::table('virtual_cards', function (Blueprint $table) {
            $table->dropIndex(['provider_card_id']);
            $table->dropIndex(['provider_status']);
            $table->dropColumn(['provider', 'provider_card_id', 'provider_status', 'provider_payload']);
        });
    }
};
