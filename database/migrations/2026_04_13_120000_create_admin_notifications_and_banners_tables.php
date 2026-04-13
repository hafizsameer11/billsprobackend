<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 255);
            $table->text('message');
            $table->string('audience', 50)->default('all');
            $table->longText('attachment')->nullable();
            $table->unsignedBigInteger('sent_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admin_banners', function (Blueprint $table) {
            $table->id();
            $table->longText('image');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_banners');
        Schema::dropIfExists('admin_notifications');
    }
};
