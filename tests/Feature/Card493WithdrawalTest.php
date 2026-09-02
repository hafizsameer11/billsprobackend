<?php

namespace Tests\Feature;

use App\Models\FiatWallet;
use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Services\VirtualCard\PagocardsVirtualCardWebhookService;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Card493WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mastercard.merchant_base_url' => 'https://pagocards.test/api',
            'mastercard.public_key' => 'pk-test',
            'mastercard.secret_key' => 'sk-test',
            'mastercard.visa_493.enabled' => true,
        ]);
    }

    private function make493VisaCard(User $user, float $balance = 25.0): VirtualCard
    {
        return VirtualCard::query()->create([
            'user_id' => $user->id,
            'card_name' => 'Test 493 Visa',
            'card_number' => '4937241043245430',
            'cvv' => '123',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'card_type' => 'visa',
            'provider' => 'pagocards_visa',
            'provider_card_id' => 'card_01withdraw493',
            'provider_status' => 'active',
            'card_color' => 'green',
            'currency' => 'USD',
            'balance' => $balance,
            'is_active' => true,
            'is_frozen' => false,
            'metadata' => ['pagocards_visa_api' => 'v1_493'],
        ]);
    }

    private function seedVisa493FundRate(float $rate = 1500.0, float $feeUsd = 1.0): void
    {
        $m = new PlatformRate([
            'category' => 'virtual_card',
            'service_key' => 'visa_493_fund',
            'sub_service_key' => null,
            'crypto_asset' => null,
            'network_key' => null,
            'exchange_rate_ngn_per_usd' => $rate,
            'fixed_fee_ngn' => 0,
            'fee_usd' => $feeUsd,
            'is_active' => true,
        ]);
        PlatformRate::query()->updateOrCreate(
            ['slug' => PlatformRate::composeSlug($m)],
            array_merge($m->toArray(), ['slug' => PlatformRate::composeSlug($m)])
        );
    }

    private function seedFundingRate(User $user, VirtualCard $card, float $rate = 1550.0): void
    {
        Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => 'card_funding',
            'category' => 'virtual_card',
            'status' => 'completed',
            'currency' => 'NGN',
            'amount' => 15500,
            'fee' => 0,
            'total_amount' => 15500,
            'reference' => 'FUNDTEST493',
            'description' => 'Test fund',
            'metadata' => [
                'virtual_card_id' => $card->id,
                'exchange_rate_ngn_per_usd' => $rate,
                'payment_wallet_type' => 'naira_wallet',
            ],
        ]);
    }

    private function webhookUrl(): string
    {
        return '/api/webhooks/pagocards/virtual-cards/test-webhook-token';
    }

    public function test_withdraw_estimate_uses_last_funding_rate(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards/card_01withdraw493' => Http::response([
                'status' => 'success',
                'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 25]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $card = $this->make493VisaCard($user);
        $this->seedVisa493FundRate(1500.0);
        $this->seedFundingRate($user, $card, 1550.0);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/virtual-cards/visa-493/{$card->id}/withdraw-estimate?amount=10");

        $response->assertOk()
            ->assertJsonPath('data.withdrawal_usd', 10)
            ->assertJsonPath('data.exchange_rate_ngn_per_usd', 1550)
            ->assertJsonPath('data.exchange_rate_source', 'last_funding')
            ->assertJsonPath('data.refund_ngn', 15500);
    }

    public function test_withdraw_estimate_falls_back_to_visa_493_fund_rate_without_prior_funding(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards/card_01withdraw493' => Http::response([
                'status' => 'success',
                'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 25]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $card = $this->make493VisaCard($user);
        $this->seedVisa493FundRate(1480.0);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/virtual-cards/visa-493/{$card->id}/withdraw-estimate?amount=10");

        $response->assertOk()
            ->assertJsonPath('data.exchange_rate_ngn_per_usd', 1480)
            ->assertJsonPath('data.exchange_rate_source', 'visa_493_fund')
            ->assertJsonPath('data.refund_ngn', 14800);
    }

    public function test_withdraw_submits_provider_request_and_creates_pending_ledger(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards/card_01withdraw493' => Http::sequence()
                ->push(['status' => 'success', 'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 25]]], 200)
                ->push(['status' => 'success', 'message' => 'Withdrawal successful'], 200)
                ->push(['status' => 'success', 'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 15]]], 200),
            'https://pagocards.test/api/v1/cards/card_01withdraw493/withdraw' => Http::response([
                'status' => 'success',
                'data' => ['reference' => 'provider-withdraw-1'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $card = $this->make493VisaCard($user);
        $this->seedFundingRate($user, $card, 1500.0);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/virtual-cards/visa-493/{$card->id}/withdraw", ['amount' => 10]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'card_withdrawal',
            'status' => 'pending',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pagocards.test/api/v1/cards/card_01withdraw493/withdraw'
                && (float) ($request['amount'] ?? 0) === 10.0;
        });
    }

    public function test_webhook_settles_pending_withdrawal_to_naira_wallet(): void
    {
        $user = User::factory()->create();
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 1000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
        $card = $this->make493VisaCard($user, 15);

        $pending = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => 'card_withdrawal',
            'category' => 'virtual_card',
            'status' => 'pending',
            'currency' => 'USD',
            'amount' => 10,
            'fee' => 0,
            'total_amount' => 10,
            'reference' => 'WDTEST493',
            'description' => 'Pending withdraw',
            'metadata' => [
                'virtual_card_id' => $card->id,
                'withdrawal_usd' => 10,
                'exchange_rate_ngn_per_usd' => 1500,
                'expected_refund_ngn' => 15000,
            ],
        ]);

        $this->postJson($this->webhookUrl(), [
            'event' => 'virtualcard.topup.completed',
            'event_id' => 'evt-withdraw-settle-1',
            'data' => [
                'id' => 'provider-withdraw-settle-1',
                'cardId' => 'card_01withdraw493',
                'amount' => 10_000_000,
                'display_amount' => 10,
                'currency' => 'USD',
                'transaction_type' => 'withdrawal',
                'reference' => 'WDTEST493',
            ],
        ])->assertOk();

        $pending->refresh();
        $this->assertSame('completed', $pending->status);

        $wallet = FiatWallet::where('user_id', $user->id)->first();
        $this->assertEquals(16000.0, (float) $wallet->balance);
    }

    public function test_withdraw_504_keeps_pending_and_returns_processing_message(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards/card_01withdraw493' => Http::response([
                'status' => 'success',
                'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 25]],
            ], 200),
            'https://pagocards.test/api/v1/cards/card_01withdraw493/withdraw' => Http::response([
                'status' => 'error',
                'message' => 'Failed to withdraw card funds',
            ], 504),
        ]);

        $user = User::factory()->create();
        $card = $this->make493VisaCard($user);
        $this->seedFundingRate($user, $card, 1500.0);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/virtual-cards/visa-493/{$card->id}/withdraw", ['amount' => 10]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'message' => 'Your withdrawal request is being processed. Your Naira wallet will be credited once the provider confirms the transaction.',
            ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'card_withdrawal',
            'status' => 'pending',
        ]);
    }

    public function test_card_withdraw_result_webhook_settles_pending_withdrawal(): void
    {
        $user = User::factory()->create();
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 1000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
        $card = $this->make493VisaCard($user, 5);

        $pending = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => 'card_withdrawal',
            'category' => 'virtual_card',
            'status' => 'pending',
            'currency' => 'USD',
            'amount' => 5,
            'fee' => 0,
            'total_amount' => 5,
            'reference' => 'WDA2B011BA1B17',
            'description' => 'Pending withdraw',
            'metadata' => [
                'virtual_card_id' => $card->id,
                'withdrawal_usd' => 5,
                'exchange_rate_ngn_per_usd' => 1449,
                'expected_refund_ngn' => 7245,
                'settlement_status' => 'awaiting_webhook',
            ],
        ]);

        $this->postJson($this->webhookUrl(), [
            'eventType' => PagocardsVirtualCardWebhookService::EVENT_CARD_WITHDRAW_RESULT,
            'status' => 'SUCCESS',
            'withdrawAmount' => '5.00',
            'cardNo' => '4937********1633',
            'cardid' => 'card_01withdraw493',
            'orderId' => 'WD202609020018598206335',
        ])->assertOk();

        $pending->refresh();
        $this->assertSame('completed', $pending->status);

        $wallet = FiatWallet::where('user_id', $user->id)->first();
        $this->assertEquals(8245.0, (float) $wallet->balance);
    }

    public function test_card_withdraw_result_recovers_failed_withdrawal(): void
    {
        $user = User::factory()->create();
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 1000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
        $card = $this->make493VisaCard($user, 0);

        $failed = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => 'card_withdrawal',
            'category' => 'virtual_card',
            'status' => 'failed',
            'currency' => 'USD',
            'amount' => 5,
            'fee' => 0,
            'total_amount' => 5,
            'reference' => 'WDA2B011BA1B17',
            'description' => 'Virtual card withdrawal failed at provider',
            'metadata' => [
                'virtual_card_id' => $card->id,
                'withdrawal_usd' => 5,
                'exchange_rate_ngn_per_usd' => 1449,
                'expected_refund_ngn' => 7245,
                'settlement_status' => 'provider_failed',
            ],
        ]);

        $this->postJson($this->webhookUrl(), [
            'eventType' => PagocardsVirtualCardWebhookService::EVENT_CARD_WITHDRAW_RESULT,
            'status' => 'SUCCESS',
            'withdrawAmount' => '5.00',
            'cardid' => 'card_01withdraw493',
            'orderId' => 'WD202609020018598206335',
        ])->assertOk();

        $failed->refresh();
        $this->assertSame('completed', $failed->status);

        $wallet = FiatWallet::where('user_id', $user->id)->first();
        $this->assertEquals(8245.0, (float) $wallet->balance);
    }

    public function test_terminate_493_withdraws_balance_before_provider_terminate(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards/card_01withdraw493' => Http::sequence()
                ->push(['status' => 'success', 'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 25]]], 200)
                ->push(['status' => 'success', 'data' => ['reference' => 'provider-term-withdraw']], 200)
                ->push(['status' => 'success', 'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 1]]], 200)
                ->push(['status' => 'success', 'message' => 'Card terminated'], 200),
            'https://pagocards.test/api/v1/cards/card_01withdraw493/withdraw' => Http::response([
                'status' => 'success',
                'data' => ['reference' => 'provider-term-withdraw'],
            ], 200),
            'https://pagocards.test/api/v1/cards/card_01withdraw493/terminate' => Http::response([
                'status' => 'success',
                'message' => 'Card terminated',
            ], 200),
        ]);

        $user = User::factory()->create();
        $card = $this->make493VisaCard($user, 25);
        $this->seedFundingRate($user, $card, 1500.0);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/virtual-cards/visa-493/{$card->id}/terminate");

        $response->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://pagocards.test/api/v1/cards/card_01withdraw493/withdraw'
            && (float) ($request['amount'] ?? 0) === 24.0);
        Http::assertSent(fn ($request) => $request->url() === 'https://pagocards.test/api/v1/cards/card_01withdraw493/terminate');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'card_withdrawal',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user->id,
            'type' => 'card_refund',
        ]);

        $card->refresh();
        $this->assertFalse($card->is_active);
    }

    public function test_terminate_493_with_zero_balance_charges_naira_wallet_fee(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards/card_01withdraw493' => Http::response([
                'status' => 'success',
                'data' => ['card_id' => 'card_01withdraw493', 'balance' => ['display_amount' => 0]],
            ], 200),
            'https://pagocards.test/api/v1/cards/card_01withdraw493/terminate' => Http::response([
                'status' => 'success',
                'message' => 'Card terminated',
            ], 200),
        ]);

        $user = User::factory()->create();
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 5000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
        $card = $this->make493VisaCard($user, 0);
        $this->seedFundingRate($user, $card, 1449.0);
        Sanctum::actingAs($user);

        $this->getJson("/api/virtual-cards/visa-493/{$card->id}/terminate-estimate")
            ->assertOk()
            ->assertJsonPath('data.can_terminate', true)
            ->assertJsonPath('data.refundable_usd', 0)
            ->assertJsonPath('data.refund_via', 'naira_wallet_fee')
            ->assertJsonPath('data.fee_charge_ngn', 1449);

        $this->postJson("/api/virtual-cards/visa-493/{$card->id}/terminate")
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/withdraw'));

        $wallet = FiatWallet::where('user_id', $user->id)->first();
        $this->assertEquals(3551.0, (float) $wallet->balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'card_termination_fee',
            'status' => 'completed',
            'amount' => 1449,
        ]);
    }

    public function test_legacy_visa_card_withdraw_is_rejected(): void
    {
        $user = User::factory()->create();
        $card = VirtualCard::query()->create([
            'user_id' => $user->id,
            'card_name' => 'Legacy Visa',
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'card_type' => 'visa',
            'provider' => 'pagocards_visa',
            'provider_card_id' => 'legacy-withdraw-1',
            'provider_status' => 'active',
            'card_color' => 'green',
            'currency' => 'USD',
            'balance' => 10,
            'is_active' => true,
            'is_frozen' => false,
            'metadata' => ['pagocards_visa_api' => 'legacy'],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/virtual-cards/visa-493/{$card->id}/withdraw", ['amount' => 5])
            ->assertStatus(404);

        $this->postJson("/api/virtual-cards/visa-card/{$card->id}/withdraw", ['amount' => 5])
            ->assertStatus(404);
    }
}
