<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('chat_session_id')
                ->nullable()
                ->after('user_id')
                ->constrained('chat_sessions')
                ->nullOnDelete();
            $table->index('chat_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['chat_session_id']);
        });
    }
};
