<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crypto_sweep_orders', function (Blueprint $table) {
            $table->dropForeign(['crypto_vendor_id']);
        });

        Schema::table('crypto_sweep_orders', function (Blueprint $table) {
            $table->foreignId('crypto_vendor_id')->nullable()->change();
            $table->string('sweep_target', 16)->default('vendor')->after('crypto_vendor_id');
            $table->foreignId('master_wallet_id')->nullable()->after('to_address')->constrained('master_wallets')->nullOnDelete();
        });

        Schema::table('crypto_sweep_orders', function (Blueprint $table) {
            $table->foreign('crypto_vendor_id')->references('id')->on('crypto_vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crypto_sweep_orders', function (Blueprint $table) {
            $table->dropForeign(['crypto_vendor_id']);
            $table->dropForeign(['master_wallet_id']);
        });

        Schema::table('crypto_sweep_orders', function (Blueprint $table) {
            $table->dropColumn(['sweep_target', 'master_wallet_id']);
        });

        Schema::table('crypto_sweep_orders', function (Blueprint $table) {
            $table->foreignId('crypto_vendor_id')->nullable(false)->change();
        });

        Schema::table('crypto_sweep_orders', function (Blueprint $table) {
            $table->foreign('crypto_vendor_id')->references('id')->on('crypto_vendors')->cascadeOnDelete();
        });
    }
};
