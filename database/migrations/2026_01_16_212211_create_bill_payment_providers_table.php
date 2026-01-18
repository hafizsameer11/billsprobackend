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
        Schema::create('bill_payment_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('bill_payment_categories')->onDelete('cascade');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('logo_url')->nullable();
            $table->string('country_code', 10)->default('NG');
            $table->string('currency', 10)->default('NGN');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->unique(['category_id', 'code']);
            $table->index('category_id');
            $table->index('code');
            $table->index('is_active');
            $table->index('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_payment_providers');
    }
};
