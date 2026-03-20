<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_sweep_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crypto_vendor_id')->constrained('crypto_vendors')->cascadeOnDelete();
            $table->foreignId('virtual_account_id')->constrained('virtual_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('blockchain', 64);
            $table->string('currency', 32);
            $table->decimal('amount', 24, 8);
            $table->string('from_address', 255)->comment('User deposit address snapshot');
            $table->string('to_address', 255)->comment('Vendor payout address snapshot');
            $table->string('status', 32)->default('pending')->index();
            $table->string('tx_hash', 255)->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_sweep_orders');
    }
};
