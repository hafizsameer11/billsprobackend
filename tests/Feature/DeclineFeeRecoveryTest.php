<?php

namespace Tests\Feature;

use App\Jobs\CheckMerchantDeclineFeeJob;
use App\Models\CardDeclineFeeCharge;
use App\Models\FiatWallet;
use App\Models\PlatformRate;
use App\Models\User;
use App\Models\VirtualCard;
use App\Services\VirtualCard\DeclineFeeRecoveryService;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class DeclineFeeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPlatformRates();
    }

    private function seedPlatformRates(): void
    {
        foreach (
            [
                ['service_key' => 'visa_decline_fee', 'fee_usd' => 1.0],
                ['service_key' => 'visa_fund', 'fee_usd' => 1.0, 'exchange_rate_ngn_per_usd' => 1500.0],
            ] as $row
        ) {
            $m = new PlatformRate([
                'category' => 'virtual_card',
                'service_key' => $row['service_key'],
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $row['exchange_rate_ngn_per_usd'] ?? 1500.0,
                'fixed_fee_ngn' => 0,
                'fee_usd' => $row['fee_usd'],
                'is_active' => true,
            ]);
            PlatformRate::query()->updateOrCreate(
                ['slug' => PlatformRate::composeSlug($m)],
                array_merge($m->toArray(), ['slug' => PlatformRate::composeSlug($m)])
            );
        }
    }

    private function makeVisaCard(User $user, string $providerCardId = '019f-test-visa-card'): VirtualCard
    {
        return VirtualCard::query()->create([
            'user_id' => $user->id,
            'card_name' => 'Test Visa',
            'card_number' => (string) random_int(1000000000000000, 9999999999999999),
            'cvv' => '123',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'card_type' => 'visa',
            'provider' => 'pagocards_visa',
            'provider_card_id' => $providerCardId,
            'provider_status' => 'active',
            'card_color' => 'green',
            'currency' => 'USD',
            'balance' => 0,
            'is_active' => true,
            'is_frozen' => false,
        ]);
    }

    private function fakeAdminDeclineDebit(int $adminTxId, string $cardId, float $amountUsd = 0.5): void
    {
        Http::fake([
            '*/admin/transactions*' => Http::response([
                'status' => 'success',
                'transactions' => [
                    [
                        'id' => $adminTxId,
                        'uuid' => 'uuid-'.$adminTxId,
                        'type' => 'debit',
                        'amount' => (string) $amountUsd,
                        'wallet_type' => 'visa',
                        'description' => "Visa card decline fee for cardId: {$cardId}",
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_merchant_paid_charges_admin_rate_not_provider_cost(): void
    {
        $user = User::factory()->create();
        $card = $this->makeVisaCard($user);
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 500,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $this->fakeAdminDeclineDebit(9001, $card->provider_card_id, 0.5);

        $recovery = app(DeclineFeeRecoveryService::class);
        $charge = $recovery->processMerchantDeclineFeeDebit(
            [
                'id' => 9001,
                'uuid' => 'uuid-9001',
                'amount' => '0.500000',
                'description' => 'Visa card decline fee for cardId: '.$card->provider_card_id,
            ],
            $card
        );

        $this->assertNotNull($charge);
        $this->assertSame('1.0000', (string) $charge->billable_usd);
        $this->assertSame('0.5000', (string) $charge->provider_cost_usd);
        $this->assertSame('1500.00000000', (string) $charge->exchange_rate_ngn_per_usd);
        $this->assertSame('1500.0000', (string) $charge->amount_ngn);

        $wallet = FiatWallet::query()->where('user_id', $user->id)->first();
        $this->assertSame(-1000.0, (float) $wallet->balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'card_decline_fee',
            'total_amount' => 1500,
        ]);
    }

    public function test_merchant_paid_is_idempotent_on_admin_tx_id(): void
    {
        $user = User::factory()->create();
        $card = $this->makeVisaCard($user);
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 5000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $recovery = app(DeclineFeeRecoveryService::class);
        $adminTx = [
            'id' => 9002,
            'uuid' => 'uuid-9002',
            'amount' => '0.500000',
            'description' => 'Visa card decline fee for cardId: '.$card->provider_card_id,
        ];

        $first = $recovery->processMerchantDeclineFeeDebit($adminTx, $card);
        $second = $recovery->processMerchantDeclineFeeDebit($adminTx, $card);

        $this->assertSame($first->id, $second->id);
        $this->assertEquals(1, CardDeclineFeeCharge::query()->count());
        $this->assertEquals(1, \App\Models\Transaction::query()->where('type', 'card_decline_fee')->count());
    }

    public function test_auto_freezes_card_after_third_merchant_subsidy(): void
    {
        $user = User::factory()->create();
        $card = $this->makeVisaCard($user);
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 100000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $virtualCards = Mockery::mock(VirtualCardService::class)->makePartial();
        $virtualCards->shouldReceive('toggleFreeze')
            ->once()
            ->with($user->id, $card->id, true)
            ->andReturn(['success' => true, 'message' => 'frozen']);
        $this->app->instance(VirtualCardService::class, $virtualCards);

        $recovery = app(DeclineFeeRecoveryService::class);

        for ($i = 1; $i <= 3; $i++) {
            $recovery->processMerchantDeclineFeeDebit([
                'id' => 9100 + $i,
                'uuid' => 'uuid-'.(9100 + $i),
                'amount' => '0.500000',
                'description' => 'Visa card decline fee for cardId: '.$card->provider_card_id,
            ], $card);
        }

        $this->assertEquals(3, CardDeclineFeeCharge::query()->where('funding_source', 'merchant')->count());
    }

    public function test_declined_webhook_dispatches_poll_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeVisaCard($user, '019f-decline-poll');

        $this->postJson('/api/webhooks/pagocards/virtual-cards/test-webhook-token', [
            'event' => 'virtualcard.transaction.declined',
            'event_id' => 'evt-decline-poll-1',
            'data' => [
                'id' => 'prov-decline-poll',
                'cardId' => '019f-decline-poll',
                'amount' => 1000000,
                'display_amount' => 1.0,
                'currency' => 'USD',
                'merchant_name' => 'Test Merchant',
                'reason' => 'No sufficient funds',
                'reference' => 'CARD_AUTH_POLL_1',
                'status' => 'decline',
            ],
        ])->assertOk();

        Queue::assertPushed(CheckMerchantDeclineFeeJob::class);
    }

    public function test_deposit_recovery_marks_outstanding_charges_recovered(): void
    {
        $user = User::factory()->create();
        $card = $this->makeVisaCard($user);

        CardDeclineFeeCharge::query()->create([
            'user_id' => $user->id,
            'virtual_card_id' => $card->id,
            'provider_cost_usd' => 0.5,
            'billable_usd' => 1,
            'exchange_rate_ngn_per_usd' => 1500,
            'amount_ngn' => 1500,
            'funding_source' => CardDeclineFeeCharge::FUNDING_MERCHANT,
            'detection_method' => 'admin_api_poll',
            'recovery_status' => CardDeclineFeeCharge::STATUS_CHARGED,
        ]);

        app(DeclineFeeRecoveryService::class)->handlePostDepositRecovery($user->id, -1500.0, 500.0);

        $this->assertDatabaseHas('card_decline_fee_charges', [
            'user_id' => $user->id,
            'recovery_status' => CardDeclineFeeCharge::STATUS_RECOVERED,
        ]);
    }
}
