<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_program_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('min_transfer_to_wallet_ngn', 16, 2)->default(100);
            $table->unsignedInteger('max_referrals_per_user')->nullable();
            $table->decimal('max_rewards_per_referral_ngn', 16, 2)->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();
        });

        Schema::create('referral_earnings_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('referral_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referee_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('referral_code_used', 32);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
        });

        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_key', 40);
            $table->string('beneficiary_role', 20); // referrer|referee
            $table->decimal('amount_ngn', 16, 2);
            $table->string('reward_type', 20)->default('auto'); // auto|manual
            $table->string('status', 20)->default('credited'); // credited|reversed
            $table->unsignedBigInteger('source_transaction_id')->nullable();
            $table->unsignedInteger('occurrence')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['beneficiary_user_id', 'referee_id', 'action_key', 'beneficiary_role', 'occurrence'],
                'referral_rewards_idempotent_unique'
            );
            $table->index(['referrer_id', 'created_at']);
            $table->index(['referee_id', 'action_key']);
        });

        Schema::create('referral_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_ngn', 16, 2);
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->decimal('earnings_balance_before', 18, 2);
            $table->decimal('earnings_balance_after', 18, 2);
            $table->timestamps();
        });

        $defaultActions = [
            'signup_verified' => [
                'enabled' => true,
                'reward_referrer_fixed_ngn' => 0,
                'reward_referrer_percent' => 0,
                'reward_referee_fixed_ngn' => 0,
                'reward_referee_percent' => 0,
                'max_times_per_referral' => 1,
            ],
            'kyc_approved' => [
                'enabled' => true,
                'reward_referrer_fixed_ngn' => 0,
                'reward_referrer_percent' => 0,
                'reward_referee_fixed_ngn' => 0,
                'reward_referee_percent' => 0,
                'max_times_per_referral' => 1,
            ],
            'first_deposit' => [
                'enabled' => true,
                'reward_referrer_fixed_ngn' => 0,
                'reward_referrer_percent' => 0,
                'reward_referee_fixed_ngn' => 0,
                'reward_referee_percent' => 0,
                'max_times_per_referral' => 1,
            ],
            'card_create' => [
                'enabled' => false,
                'reward_referrer_fixed_ngn' => 0,
                'reward_referrer_percent' => 0,
                'reward_referee_fixed_ngn' => 0,
                'reward_referee_percent' => 0,
                'max_times_per_referral' => 1,
            ],
            'card_fund' => [
                'enabled' => false,
                'reward_referrer_fixed_ngn' => 0,
                'reward_referrer_percent' => 0,
                'reward_referee_fixed_ngn' => 0,
                'reward_referee_percent' => 0,
                'max_times_per_referral' => 0,
            ],
        ];

        DB::table('referral_program_settings')->insert([
            'is_enabled' => false,
            'min_transfer_to_wallet_ngn' => 100,
            'max_referrals_per_user' => null,
            'max_rewards_per_referral_ngn' => null,
            'actions' => json_encode($defaultActions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_transfers');
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referral_relationships');
        Schema::dropIfExists('referral_earnings_wallets');
        Schema::dropIfExists('referral_program_settings');
    }
};
