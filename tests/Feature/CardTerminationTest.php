<?php

namespace Tests\Feature;

use App\Models\FiatWallet;
use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardTerminationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mastercard.merchant_base_url' => 'https://pagocards.test/api',
            'mastercard.public_key' => 'pk-test',
            'mastercard.secret_key' => 'sk-test',
        ]);

        $this->seedTerminateRates();
    }

    private function seedTerminateRates(): void
    {
        foreach (
            [
                ['service_key' => 'visa_terminate', 'fee_usd' => 1.0, 'exchange_rate_ngn_per_usd' => 1420.0],
                ['service_key' => 'terminate', 'fee_usd' => 1.0, 'exchange_rate_ngn_per_usd' => 1420.0],
            ] as $row
        ) {
            $m = new PlatformRate([
                'category' => 'virtual_card',
                'service_key' => $row['service_key'],
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $row['exchange_rate_ngn_per_usd'],
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

    private function makeVisaCard(User $user, float $balance = 5.31): VirtualCard
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
            'provider_card_id' => 'visa-card-terminate-test',
            'provider_status' => 'active',
            'card_color' => 'green',
            'currency' => 'USD',
            'balance' => $balance,
            'is_active' => true,
            'is_frozen' => false,
        ]);
    }

    private function fakeVisaCardDetails(float $balanceUsd): void
    {
        $micro = (int) round($balanceUsd * 1_000_000);
        Http::fake([
            'https://pagocards.test/api/visacard/getcard' => Http::response([
                'secure' => [
                    'success' => true,
                    'data' => [
                        'details' => [
                            'balance_amount' => $micro,
                            'cardid' => 'visa-card-terminate-test',
                        ],
                        'transactions' => [],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_terminate_estimate_rejects_balance_below_fee(): void
    {
        $user = User::factory()->create(['email' => 'low-balance@example.com']);
        $card = $this->makeVisaCard($user, 0.50);
        $this->fakeVisaCardDetails(0.50);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/virtual-cards/visa-card/'.$card->id.'/terminate-estimate');

        $response->assertOk()
            ->assertJsonPath('data.can_terminate', false)
            ->assertJsonPath('data.card_balance_usd', 0.5)
            ->assertJsonPath('data.termination_fee_usd', 1);
    }

    public function test_terminate_estimate_returns_refund_breakdown(): void
    {
        $user = User::factory()->create(['email' => 'estimate@example.com']);
        $card = $this->makeVisaCard($user, 5.31);
        $this->fakeVisaCardDetails(5.31);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/virtual-cards/visa-card/'.$card->id.'/terminate-estimate');

        $response->assertOk()
            ->assertJsonPath('data.can_terminate', true)
            ->assertJsonPath('data.refundable_usd', 4.31)
            ->assertJsonPath('data.sell_rate_ngn_per_usd', 1420)
            ->assertJsonPath('data.refund_ngn', 6120.2);
    }

    public function test_terminate_visa_credits_naira_wallet_and_creates_card_refund(): void
    {
        Http::fake([
            'https://pagocards.test/api/visacard/getcard' => Http::response([
                'secure' => [
                    'success' => true,
                    'data' => [
                        'details' => [
                            'balance_amount' => 5310000,
                            'cardid' => 'visa-card-terminate-test',
                        ],
                        'transactions' => [],
                    ],
                ],
            ], 200),
            'https://pagocards.test/api/terminate' => Http::response([
                'status' => 'success',
                'message' => 'Card terminated',
            ], 200),
        ]);

        $user = User::factory()->create(['email' => 'terminate@example.com']);
        $card = $this->makeVisaCard($user, 5.31);
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => 1000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/virtual-cards/visa-card/'.$card->id.'/terminate');

        $response->assertOk()
            ->assertJsonPath('data.termination.refund_ngn', 6120.2);

        $card->refresh();
        $this->assertFalse($card->is_active);
        $this->assertEquals(0.0, (float) $card->balance);

        $wallet = FiatWallet::where('user_id', $user->id)->first();
        $this->assertEquals(7120.2, (float) $wallet->balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'card_refund',
            'amount' => 6120.2,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pagocards.test/api/terminate'
                && $request['cardid'] === 'visa-card-terminate-test'
                && $request['email'] === 'terminate@example.com';
        });
    }

    public function test_terminate_rejects_when_balance_below_fee(): void
    {
        $user = User::factory()->create(['email' => 'reject@example.com']);
        $card = $this->makeVisaCard($user, 0.25);
        $this->fakeVisaCardDetails(0.25);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/virtual-cards/visa-card/'.$card->id.'/terminate');

        $response->assertStatus(422);
        $this->assertTrue($card->fresh()->is_active);
        $this->assertSame(0, Transaction::where('user_id', $user->id)->where('type', 'card_refund')->count());
    }
}
