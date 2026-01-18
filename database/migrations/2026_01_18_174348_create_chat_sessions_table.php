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
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('admin_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('issue_type', ['fiat_issue', 'virtual_card_issue', 'crypto_issue', 'general'])->nullable();
            $table->enum('status', ['active', 'waiting', 'closed'])->default('waiting');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('admin_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
