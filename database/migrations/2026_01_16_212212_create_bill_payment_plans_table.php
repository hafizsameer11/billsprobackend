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
        Schema::create('bill_payment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('bill_payment_providers')->onDelete('cascade');
            $table->string('code', 100);
            $table->string('name', 255);
            $table->decimal('amount', 20, 8);
            $table->string('currency', 10)->default('NGN');
            $table->string('data_amount', 50)->nullable();
            $table->string('validity', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('provider_id');
            $table->index('code');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_payment_plans');
    }
};
