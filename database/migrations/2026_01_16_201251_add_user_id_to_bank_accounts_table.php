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
        Schema::table('bank_accounts', function (Blueprint $table) {
            // Remove unique constraint on account_number first (before adding user_id)
            $table->dropUnique(['account_number']);
            
            // Add user_id column
            $table->foreignId('user_id')->after('id')->constrained()->onDelete('cascade');
            
            // Add unique constraint for user_id + account_number combination
            $table->unique(['user_id', 'account_number']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropUnique(['user_id', 'account_number']);
            $table->unique('account_number');
        });
    }
};
