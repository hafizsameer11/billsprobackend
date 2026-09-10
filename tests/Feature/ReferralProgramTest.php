<?php

namespace Tests\Feature;

use App\Models\FiatWallet;
use App\Models\OtpVerification;
use App\Models\ReferralEarningsWallet;
use App\Models\ReferralProgramSetting;
use App\Models\ReferralRelationship;
use App\Models\ReferralReward;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use App\Services\Referral\ReferralRewardService;
use App\Services\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferralProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function enableProgram(array $actionOverrides = []): ReferralProgramSetting
    {
        $settings = app(ReferralService::class)->getOrCreateSettings();
        $actions = ReferralProgramSetting::defaultActions();
        $actions['signup_verified'] = array_merge($actions['signup_verified'], [
            'enabled' => true,
            'reward_referrer_fixed_ngn' => 500,
            'reward_referrer_percent' => 0,
            'reward_referee_fixed_ngn' => 100,
        ], $actionOverrides['signup_verified'] ?? []);
        $actions['first_deposit'] = array_merge($actions['first_deposit'], [
            'enabled' => true,
            'reward_referrer_fixed_ngn' => 0,
            'reward_referrer_percent' => 10,
            'reward_referee_fixed_ngn' => 0,
        ], $actionOverrides['first_deposit'] ?? []);

        $settings->update([
            'is_enabled' => true,
            'min_transfer_to_wallet_ngn' => 100,
            'actions' => $actions,
        ]);

        return $settings->fresh();
    }

    private function makeReferrer(): User
    {
        $user = User::factory()->create([
            'email_verified' => true,
            'first_name' => 'Referrer',
            'last_name' => 'One',
        ]);
        app(ReferralService::class)->ensureReferralCode($user);

        return $user->fresh();
    }

    private function verifyEmail(User $user): void
    {
        $otpService = app(OtpService::class);
        $otpService->sendOtp($user->email, null, 'email');
        $otp = OtpVerification::query()
            ->where('email', $user->email)
            ->where('verified', false)
            ->value('otp');
        $result = app(AuthService::class)->verifyEmailOtp($user->email, $otp);
        $this->assertTrue($result['success']);
    }

    public function test_register_with_invalid_referral_code_fails(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.ref@example.com',
            'password' => 'password123',
            'referral_code' => 'NOSUCHCODE',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
        $this->assertStringContainsString('referral', strtolower((string) $response->json('message')));
    }

    public function test_register_with_valid_code_and_verify_awards_signup_reward(): void
    {
        $this->enableProgram();
        $referrer = $this->makeReferrer();

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.ok@example.com',
            'password' => 'password123',
            'country_code' => 'NG',
            'referral_code' => $referrer->referral_code,
        ]);

        $response->assertSuccessful()->assertJsonPath('success', true);

        $referee = User::query()->where('email', 'jane.ok@example.com')->first();
        $this->assertNotNull($referee);
        $this->assertEquals($referrer->id, $referee->referred_by_user_id);

        $this->verifyEmail($referee);

        $this->assertDatabaseHas('referral_relationships', [
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('referral_rewards', [
            'beneficiary_user_id' => $referrer->id,
            'action_key' => 'signup_verified',
            'amount_ngn' => 500,
            'status' => 'credited',
        ]);

        $wallet = ReferralEarningsWallet::query()->where('user_id', $referrer->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(500.0, (float) $wallet->balance);
    }

    public function test_first_deposit_reward_is_idempotent(): void
    {
        $this->enableProgram();
        $referrer = $this->makeReferrer();
        $referee = User::factory()->create([
            'email_verified' => true,
            'referred_by_user_id' => $referrer->id,
        ]);

        ReferralRelationship::query()->create([
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'referral_code_used' => $referrer->referral_code,
            'status' => 'active',
        ]);

        $rewards = app(ReferralRewardService::class);
        $rewards->awardForAction($referee, 'first_deposit', 1000.0, 11);
        $rewards->awardForAction($referee, 'first_deposit', 1000.0, 12);

        $count = ReferralReward::query()
            ->where('referee_id', $referee->id)
            ->where('action_key', 'first_deposit')
            ->where('beneficiary_role', 'referrer')
            ->count();
        $this->assertSame(1, $count);

        $wallet = ReferralEarningsWallet::query()->where('user_id', $referrer->id)->first();
        $this->assertEquals(100.0, (float) $wallet->balance); // 10% of 1000
    }

    public function test_disabled_action_pays_nothing(): void
    {
        $settings = $this->enableProgram();
        $actions = $settings->actions;
        $actions['first_deposit']['enabled'] = false;
        $settings->update(['actions' => $actions]);

        $referrer = $this->makeReferrer();
        $referee = User::factory()->create([
            'email_verified' => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        ReferralRelationship::query()->create([
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'referral_code_used' => $referrer->referral_code,
            'status' => 'active',
        ]);

        app(ReferralRewardService::class)->awardForAction($referee, 'first_deposit', 5000.0, null);

        $this->assertSame(0, ReferralReward::query()->where('action_key', 'first_deposit')->count());
    }

    public function test_transfer_to_main_wallet(): void
    {
        $this->enableProgram();
        $user = User::factory()->create(['email_verified' => true]);
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 1000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
        ReferralEarningsWallet::query()->create([
            'user_id' => $user->id,
            'balance' => 250,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/referral/transfer-to-wallet', ['amount' => 150]);
        $response->assertOk()->assertJsonPath('success', true);

        $this->assertEquals(100.0, (float) ReferralEarningsWallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertEquals(1150.0, (float) FiatWallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'referral_payout',
            'amount' => 150,
        ]);
    }

    public function test_admin_manual_reward_and_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email_verified' => true]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/referral/settings', [
            'is_enabled' => true,
            'min_transfer_to_wallet_ngn' => 50,
            'actions' => ReferralProgramSetting::defaultActions(),
        ])->assertOk()->assertJsonPath('data.is_enabled', true);

        $this->postJson('/api/admin/referral/rewards/manual', [
            'user_id' => $user->id,
            'amount_ngn' => 75.5,
            'reason' => 'Influencer bonus',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertEquals(75.5, (float) ReferralEarningsWallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertDatabaseHas('referral_rewards', [
            'beneficiary_user_id' => $user->id,
            'action_key' => 'manual',
            'reward_type' => 'manual',
            'amount_ngn' => 75.5,
        ]);
    }

    public function test_user_can_fetch_referral_overview(): void
    {
        $user = User::factory()->create(['email_verified' => true]);
        app(ReferralService::class)->ensureReferralCode($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/referral')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'referral_code',
                    'earnings_balance_ngn',
                    'stats',
                    'referrals',
                    'recent_rewards',
                ],
            ]);
    }
}
