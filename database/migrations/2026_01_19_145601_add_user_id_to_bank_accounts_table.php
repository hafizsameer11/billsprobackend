<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            // Add user_id as nullable first to handle existing records
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->index('user_id');
        });

        // Assign existing records to the first user (or delete them if no users exist)
        $firstUserId = DB::table('users')->orderBy('id')->value('id');
        if ($firstUserId) {
            DB::table('bank_accounts')->whereNull('user_id')->update(['user_id' => $firstUserId]);
        } else {
            // If no users exist, delete orphaned bank accounts
            DB::table('bank_accounts')->whereNull('user_id')->delete();
        }

        // Now make user_id non-nullable and add foreign key constraint
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Update unique constraint to be per user instead of global
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropUnique(['account_number']);
            $table->unique(['user_id', 'account_number'], 'bank_accounts_user_account_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            // Restore original unique constraint
            $table->dropUnique('bank_accounts_user_account_unique');
            $table->unique('account_number');
            
            // Drop foreign key and column
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
