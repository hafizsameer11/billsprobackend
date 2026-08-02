<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagocards_wallet_recharges', function (Blueprint $table) {
            $table->timestamp('applied_to_history_at')->nullable()->after('created_by');
            $table->unsignedInteger('history_backfill_count')->nullable()->after('applied_to_history_at');
            $table->string('db_backup_path', 512)->nullable()->after('history_backfill_count');
        });
    }

    public function down(): void
    {
        Schema::table('pagocards_wallet_recharges', function (Blueprint $table) {
            $table->dropColumn(['applied_to_history_at', 'history_backfill_count', 'db_backup_path']);
        });
    }
};
