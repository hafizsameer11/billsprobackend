<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_card_provider_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_event_id', 191)->unique();
            $table->string('event_name', 191);
            $table->string('event_target_id', 191)->nullable();
            $table->string('pagocards_card_id', 191);
            $table->string('pagocards_user_id', 191)->nullable();
            $table->foreignId('virtual_card_id')->nullable()->constrained('virtual_cards')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'vc_pwebhook_ev_user_status_idx');
            // Default name exceeds MySQL 64-char identifier limit (virtual_card_id is long).
            $table->index(['virtual_card_id', 'status'], 'vc_pwebhook_ev_card_status_idx');
            $table->index('event_name', 'vc_pwebhook_ev_event_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_card_provider_webhook_events');
    }
};
