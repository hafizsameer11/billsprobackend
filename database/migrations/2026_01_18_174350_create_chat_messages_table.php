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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('admin_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->text('message');
            $table->enum('sender_type', ['user', 'admin', 'system'])->default('user');
            $table->enum('status', ['sent', 'delivered', 'read'])->default('sent');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_type')->nullable(); // image, document, etc.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            
            $table->index('chat_session_id');
            $table->index('user_id');
            $table->index('admin_id');
            $table->index('sender_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
