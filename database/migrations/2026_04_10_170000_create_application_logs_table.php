<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('level')->index();
            $table->string('level_name', 20)->index();
            $table->string('channel', 100)->nullable()->index();
            $table->text('message');
            $table->longText('context')->nullable();
            $table->longText('extra')->nullable();
            $table->timestamp('logged_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_logs');
    }
};

