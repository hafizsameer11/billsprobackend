<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('blockchain', 255);
            $table->text('mnemonic_encrypted')->nullable()->comment('AES encrypted mnemonic or secret');
            $table->string('xpub', 500)->nullable()->comment('xpub or raw address for non-HD chains');
            $table->string('derivation_path', 100)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'blockchain']);
            $table->index('blockchain');
        });

        Schema::create('crypto_deposit_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_account_id')->constrained('virtual_accounts')->onDelete('cascade');
            $table->foreignId('user_wallet_id')->nullable()->constrained('user_wallets')->nullOnDelete();
            $table->string('blockchain', 255);
            $table->string('currency', 50);
            $table->string('address', 255);
            $table->unsignedInteger('index')->default(0);
            $table->text('private_key_encrypted')->nullable();
            $table->timestamps();

            $table->index('virtual_account_id');
            $table->index('address');
            $table->index(['blockchain', 'currency']);
        });

        Schema::create('tatum_raw_webhooks', function (Blueprint $table) {
            $table->id();
            $table->longText('raw_data');
            $table->text('headers')->nullable();
            $table->string('ip_address', 255)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('processed');
            $table->index('created_at');
        });

        Schema::create('tatum_webhook_responses', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 255)->nullable()->index();
            $table->string('subscription_type', 255)->nullable();
            $table->decimal('amount', 20, 8)->nullable();
            $table->string('reference', 255)->nullable();
            $table->string('currency', 50)->nullable();
            $table->string('tx_id', 255)->nullable()->unique();
            $table->unsignedBigInteger('block_height')->nullable();
            $table->string('block_hash', 255)->nullable();
            $table->string('from_address', 255)->nullable();
            $table->string('to_address', 255)->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->integer('index')->nullable();
            $table->timestamps();

            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tatum_webhook_responses');
        Schema::dropIfExists('tatum_raw_webhooks');
        Schema::dropIfExists('crypto_deposit_addresses');
        Schema::dropIfExists('user_wallets');
    }
};
