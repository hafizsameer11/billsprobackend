<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->string('blockchain', 64);
            $table->string('currency', 32);
            $table->string('payout_address', 255);
            $table->string('contract_address', 255)->nullable()->comment('Token contract for fungible; null for native');
            $table->boolean('is_active')->default(true);
            $table->text('metadata')->nullable();
            $table->timestamps();

            $table->index(['blockchain', 'currency', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_vendors');
    }
};
