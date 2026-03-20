<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 32)->default('active')->after('is_admin');
            $table->timestamp('suspended_at')->nullable()->after('account_status');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
            $table->text('internal_notes')->nullable()->after('suspension_reason');
            $table->index('account_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'suspended_at', 'suspension_reason', 'internal_notes']);
        });
    }
};
