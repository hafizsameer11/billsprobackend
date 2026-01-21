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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type', 50); // login, transaction, bill_payment, deposit, withdrawal, etc.
            $table->string('title');
            $table->text('message');
            $table->boolean('read')->default(false);
            $table->json('metadata')->nullable(); // Additional data (transaction_id, amount, etc.)
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('read');
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
