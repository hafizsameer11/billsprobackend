<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_maintenance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('group')->index();
            $table->string('label');
            $table->boolean('is_under_maintenance')->default(false);
            $table->string('notice_title')->nullable();
            $table->text('notice_message')->nullable();
            $table->string('alternate_hint')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_maintenance_settings');
    }
};
