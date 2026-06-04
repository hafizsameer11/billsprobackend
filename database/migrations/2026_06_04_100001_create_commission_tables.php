<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_volume_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier_key', 16)->unique();
            $table->string('label', 64);
            $table->decimal('min_monthly_volume_ngn', 24, 2)->default(0);
            $table->decimal('max_monthly_volume_ngn', 24, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('bill_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->string('scene', 32);
            $table->string('entity_key', 64);
            $table->string('tier_key', 16);
            $table->decimal('commission_pct', 10, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['scene', 'entity_key', 'tier_key']);
            $table->index(['scene', 'tier_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_commission_rates');
        Schema::dropIfExists('commission_volume_tiers');
    }
};
