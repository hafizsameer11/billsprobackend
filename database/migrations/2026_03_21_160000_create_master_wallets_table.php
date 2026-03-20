<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('blockchain', 64)->unique()->comment('Normalized: ethereum, bsc, bitcoin, etc.');
            $table->string('address', 255)->nullable();
            $table->text('private_key_encrypted')->comment('AES encrypted private key / WIF');
            $table->string('label', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_wallets');
    }
};
