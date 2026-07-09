<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc', function (Blueprint $table) {
            $table->string('location')->nullable()->after('nin_number');
            $table->string('nin_verification_report_id')->nullable()->after('location');
            $table->string('bvn_verification_report_id')->nullable()->after('nin_verification_report_id');
            $table->string('nin_verification_status')->nullable()->after('bvn_verification_report_id');
            $table->string('bvn_verification_status')->nullable()->after('nin_verification_status');
            $table->json('nin_verification_data')->nullable()->after('bvn_verification_status');
            $table->json('bvn_verification_data')->nullable()->after('nin_verification_data');
            $table->timestamp('identity_verified_at')->nullable()->after('bvn_verification_data');
        });
    }

    public function down(): void
    {
        Schema::table('kyc', function (Blueprint $table) {
            $table->dropColumn([
                'location',
                'nin_verification_report_id',
                'bvn_verification_report_id',
                'nin_verification_status',
                'bvn_verification_status',
                'nin_verification_data',
                'bvn_verification_data',
                'identity_verified_at',
            ]);
        });
    }
};
