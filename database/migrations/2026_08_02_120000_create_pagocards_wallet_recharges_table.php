<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagocards_wallet_recharges', function (Blueprint $table) {
            $table->id();
            $table->decimal('ngn_spent', 16, 2);
            $table->decimal('usd_gross', 16, 4)->nullable();
            $table->decimal('usd_credited', 16, 4);
            $table->decimal('true_rate_ngn_per_usd', 16, 8);
            $table->timestamp('recharged_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('recharged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagocards_wallet_recharges');
    }
};
