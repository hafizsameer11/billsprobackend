<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc', function (Blueprint $table) {
            $table->string('face_verification_video_path')->nullable()->after('nin_number');
            $table->string('face_verification_video_disk')->default('local')->after('face_verification_video_path');
            $table->timestamp('face_verification_submitted_at')->nullable()->after('face_verification_video_disk');
        });
    }

    public function down(): void
    {
        Schema::table('kyc', function (Blueprint $table) {
            $table->dropColumn([
                'face_verification_video_path',
                'face_verification_video_disk',
                'face_verification_submitted_at',
            ]);
        });
    }
};
