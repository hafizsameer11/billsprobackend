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
        Schema::create('virtual_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('card_name', 255);
            $table->string('card_number', 20)->unique();
            $table->string('cvv', 4);
            $table->string('expiry_month', 2);
            $table->string('expiry_year', 4);
            $table->string('card_type', 50)->default('mastercard'); // mastercard, visa, etc.
            $table->string('card_color', 50)->default('green'); // green, brown, purple, etc.
            $table->string('currency', 10)->default('USD'); // Virtual cards always use USD ($)
            $table->decimal('balance', 20, 8)->default(0);
            $table->decimal('daily_spending_limit', 20, 8)->nullable();
            $table->decimal('monthly_spending_limit', 20, 8)->nullable();
            $table->integer('daily_transaction_limit')->nullable();
            $table->integer('monthly_transaction_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_frozen')->default(false);
            $table->string('billing_address_street', 255)->nullable();
            $table->string('billing_address_city', 100)->nullable();
            $table->string('billing_address_state', 100)->nullable();
            $table->string('billing_address_country', 100)->nullable();
            $table->string('billing_address_postal_code', 20)->nullable();
            $table->text('metadata')->nullable(); // Additional card data
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('card_number');
            $table->index('is_active');
            $table->index('is_frozen');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('virtual_cards');
    }
};
