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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name', 255);
            $table->string('account_number', 50)->unique();
            $table->string('account_name', 255);
            $table->string('currency', 10)->default('NGN');
            $table->string('country_code', 10)->default('NG');
            $table->boolean('is_active')->default(true);
            $table->text('metadata')->nullable(); // Additional bank details
            $table->timestamps();
            
            $table->index(['currency', 'country_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
