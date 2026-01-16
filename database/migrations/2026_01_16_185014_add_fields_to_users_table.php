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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone_number')->nullable()->unique()->after('email');
            $table->boolean('email_verified')->default(false)->after('email_verified_at');
            $table->boolean('phone_verified')->default(false)->after('email_verified');
            $table->string('pin')->nullable()->after('password');
            $table->boolean('kyc_completed')->default(false)->after('pin');
            $table->string('country_code', 10)->default('NG')->after('kyc_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'phone_number', 'email_verified', 'phone_verified', 'pin', 'kyc_completed', 'country_code']);
        });
    }
};
