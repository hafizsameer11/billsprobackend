<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_profit_settings', function (Blueprint $table) {
            $table->id();
            $table->string('service_key', 64)->unique();
            $table->string('label', 120);
            $table->decimal('fixed_fee', 20, 8)->default(0);
            $table->decimal('percentage', 10, 4)->default(0);
            $table->string('percentage_basis', 32)->default('total_amount');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['service_key' => '_default', 'label' => 'Other / unlisted types', 'sort_order' => 999],
            ['service_key' => 'deposit', 'label' => 'Fiat deposit', 'sort_order' => 10],
            ['service_key' => 'withdrawal', 'label' => 'Fiat withdrawal', 'sort_order' => 20],
            ['service_key' => 'bill_payment', 'label' => 'Bill payment', 'sort_order' => 30],
            ['service_key' => 'crypto_deposit', 'label' => 'Crypto deposit', 'sort_order' => 40],
            ['service_key' => 'crypto_withdrawal', 'label' => 'Crypto withdrawal', 'sort_order' => 50],
            ['service_key' => 'crypto_buy', 'label' => 'Crypto buy', 'sort_order' => 60],
            ['service_key' => 'crypto_sell', 'label' => 'Crypto sell', 'sort_order' => 70],
            ['service_key' => 'external_send', 'label' => 'Crypto external send', 'sort_order' => 80],
            ['service_key' => 'card_creation', 'label' => 'Virtual card — creation fee', 'sort_order' => 90],
            ['service_key' => 'card_funding', 'label' => 'Virtual card — funding / load', 'sort_order' => 100],
            ['service_key' => 'flush', 'label' => 'Treasury / flush', 'sort_order' => 110],
        ];

        foreach ($rows as $r) {
            DB::table('service_profit_settings')->insert(array_merge($r, [
                'fixed_fee' => 0,
                'percentage' => 0,
                'percentage_basis' => 'total_amount',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_profit_settings');
    }
};
