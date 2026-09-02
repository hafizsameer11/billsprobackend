<?php

namespace Tests\Feature;

use App\Models\FiatWallet;
use App\Models\PlatformRate;
use App\Models\User;
use App\Models\VirtualCard;
use App\Services\VirtualCard\Visa493BinApiClient;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Visa493BinTest extends TestCase
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
            'mastercard.visa_493.product_code' => 'us_493_visa_bin',
        ]);

        $this->seedRates();
    }

    private function seedRates(): void
    {
        foreach (
            [
                ['service_key' => 'visa_creation', 'fee_usd' => 8.0, 'exchange_rate_ngn_per_usd' => 1500.0],
                ['service_key' => 'visa_fund', 'fee_usd' => 2.0, 'exchange_rate_ngn_per_usd' => 2000.0],
                ['service_key' => 'visa_493_creation', 'fee_usd' => 8.0, 'exchange_rate_ngn_per_usd' => 1500.0],
                ['service_key' => 'visa_493_fund', 'fee_usd' => 1.0, 'exchange_rate_ngn_per_usd' => 1600.0],
                ['service_key' => 'visa_493_terminate', 'fee_usd' => 1.0, 'exchange_rate_ngn_per_usd' => 1420.0],
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

    private function seedNairaWallet(User $user, float $balance = 10000): void
    {
        FiatWallet::query()->create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => $balance,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function makeVisaCard(User $user, string $providerCardId, array $metadata = []): VirtualCard
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
            'metadata' => $metadata,
        ]);
    }

    public function test_http_fake_201_is_successful_not_ok(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards' => Http::response([
                'status' => 'success',
                'message' => 'Card created successfully.',
            ], 201),
        ]);

        $response = Http::post('https://pagocards.test/api/v1/cards', []);

        $this->assertSame(201, $response->status());
        $this->assertFalse($response->ok());
        $this->assertTrue($response->successful());
    }

    public function test_visa493_client_accepts_http_201_on_create(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards' => Http::response([
                'status' => 'success',
                'message' => 'Card created successfully.',
                'data' => ['card_id' => 'card_201test'],
            ], 201),
        ]);

        $result = app(Visa493BinApiClient::class)->createCard([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Card created successfully.', $result['message']);
    }

    public function test_create_visa_card_uses_493_api_without_initial_load(): void
    {
        Http::fake([
            'https://pagocards.test/api/v1/cards' => Http::response([
                'status' => 'success',
                'message' => 'Card created successfully.',
                'data' => [
                    'card_id' => 'card_01test493',
                ],
            ], 201),
            'https://pagocards.test/api/v1/cards/card_01test493' => Http::response([
                'status' => 'success',
                'data' => [
                    'card_id' => 'card_01test493',
                    'card_number' => '4111111111111111',
                    'cvv' => '123',
                    'expiry_month' => '12',
                    'expiry_year' => '2030',
                    'name_on_card' => 'Jane Doe',
                    'balance' => ['display_amount' => 0],
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $this->seedNairaWallet($user, 50000);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/virtual-cards/visa-493', [
            'payment_wallet_type' => 'naira_wallet',
            'payment_wallet_currency' => 'NGN',
            'card_name' => 'My Visa',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('success', true);

        $card = VirtualCard::where('user_id', $user->id)->first();
        $this->assertNotNull($card);
        $this->assertEquals('v1_493', $card->metadata['pagocards_visa_api']);
        $this->assertEquals('us_493_visa_bin', $card->metadata['product_code']);
        $this->assertEquals('493BIN', $card->metadata['brand']);
        $this->assertEquals('card_01test493', $card->provider_card_id);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://pagocards.test/api/v1/cards' || $request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return ($body['product_code'] ?? null) === 'us_493_visa_bin'
                && ($body['first_name'] ?? null) === 'Jane'
                && ($body['last_name'] ?? null) === 'Doe'
                && ($body['email'] ?? null) === 'jane@example.com'
                && ! array_key_exists('initial_load', $body);
        });
    }

    public function test_legacy_visa_create_route_uses_visacard_endpoint_when_493_enabled(): void
    {
        Http::fake([
            'https://pagocards.test/api/visacard/createcard' => Http::response([
                'status' => 'success',
                'message' => 'Card created successfully.',
                'data' => [
                    'cardid' => 'legacy-create-visa-1',
                    'card_number' => '4111111111111111',
                    'cvv' => '123',
                    'expiry_month' => '12',
                    'expiry_year' => '2030',
                    'balance' => 0,
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'email' => 'legacy-create@example.com',
            'first_name' => 'Legacy',
            'last_name' => 'Visa',
        ]);
        $this->seedNairaWallet($user, 50000);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/virtual-cards/visa-card', [
            'payment_wallet_type' => 'naira_wallet',
            'payment_wallet_currency' => 'NGN',
            'card_name' => 'Legacy Visa',
        ]);

        $response->assertSuccessful()->assertJsonPath('success', true);

        $card = VirtualCard::where('user_id', $user->id)->first();
        $this->assertNotNull($card);
        $this->assertEquals('legacy', $card->metadata['pagocards_visa_api']);

        Http::assertSent(fn ($request) => $request->url() === 'https://pagocards.test/api/visacard/createcard');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://pagocards.test/api/v1/cards');
    }

    public function test_legacy_visa_card_fund_uses_visacard_endpoint(): void
    {
        Http::fake([
            'https://pagocards.test/api/visacard/fundcard' => Http::response([
                'status' => 'success',
                'data' => ['balance' => 20.0],
            ], 200),
            'https://pagocards.test/api/visacard/getcard' => Http::response([
                'secure' => [
                    'success' => true,
                    'data' => [
                        'details' => [
                            'balance_amount' => 20_000_000,
                            'cardid' => 'legacy-visa-fund',
                        ],
                        'transactions' => [],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['email' => 'legacy-fund@example.com']);
        $this->seedNairaWallet($user, 50000);
        $card = $this->makeVisaCard($user, 'legacy-visa-fund');

        $result = app(VirtualCardService::class)->fundCard($user->id, $card->id, [
            'amount' => 20,
            'payment_wallet_type' => 'naira_wallet',
            'payment_wallet_currency' => 'NGN',
        ]);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pagocards.test/api/visacard/fundcard'
                && ($request['cardid'] ?? '') === 'legacy-visa-fund'
                && (float) ($request['amount'] ?? 0) === 20.0;
        });
    }

    public function test_mixed_user_cards_route_funding_to_correct_provider(): void
    {
        Http::fake([
            'https://pagocards.test/api/visacard/fundcard' => Http::response([
                'status' => 'success',
                'data' => ['balance' => 10.0],
            ], 200),
            'https://pagocards.test/api/visacard/getcard' => Http::response([
                'secure' => [
                    'success' => true,
                    'data' => [
                        'details' => [
                            'balance_amount' => 10_000_000,
                            'cardid' => 'legacy-mixed-1',
                        ],
                        'transactions' => [],
                    ],
                ],
            ], 200),
            'https://pagocards.test/api/v1/cards/card_493_mixed/fund' => Http::response([
                'status' => 'success',
                'data' => ['balance' => ['display_amount' => 15.0]],
            ], 200),
            'https://pagocards.test/api/v1/cards/card_493_mixed' => Http::response([
                'status' => 'success',
                'data' => [
                    'card_id' => 'card_493_mixed',
                    'balance' => ['display_amount' => 15.0],
                    'transactions' => [],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['email' => 'mixed-route@example.com']);
        $this->seedNairaWallet($user, 50000);

        $legacyCard = $this->makeVisaCard($user, 'legacy-mixed-1');
        $binCard = $this->makeVisaCard($user, 'card_493_mixed', [
            'pagocards_visa_api' => 'v1_493',
            'product_code' => 'us_493_visa_bin',
            'brand' => '493BIN',
        ]);

        $service = app(VirtualCardService::class);

        $legacyResult = $service->fundCard($user->id, $legacyCard->id, [
            'amount' => 10,
            'payment_wallet_type' => 'naira_wallet',
            'payment_wallet_currency' => 'NGN',
        ]);
        $this->assertTrue($legacyResult['success']);

        $binResult = $service->fundCard($user->id, $binCard->id, [
            'amount' => 15,
            'payment_wallet_type' => 'naira_wallet',
            'payment_wallet_currency' => 'NGN',
        ]);
        $this->assertTrue($binResult['success']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pagocards.test/api/visacard/fundcard'
                && ($request['cardid'] ?? '') === 'legacy-mixed-1';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pagocards.test/api/v1/cards/card_493_mixed/fund'
                && (float) ($request['amount'] ?? 0) === 15.0
                && ! isset($request['email']);
        });
    }

    public function test_get_user_cards_syncs_legacy_visa_list_without_overwriting_api_version(): void
    {
        Http::fake([
            'https://pagocards.test/api/visacard/getallcards' => Http::response([
                'data' => [[
                    'cardid' => 'legacy-list-1',
                    'nameoncard' => 'Mixed User',
                    'balance' => 5,
                ]],
            ], 200),
        ]);

        $user = User::factory()->create(['email' => 'mixed-list@example.com']);
        $this->makeVisaCard($user, 'legacy-list-1', ['pagocards_visa_api' => 'legacy']);
        $this->makeVisaCard($user, 'card_493_list', ['pagocards_visa_api' => 'v1_493']);

        app(VirtualCardService::class)->getUserCards($user->id);

        Http::assertSent(fn ($request) => $request->url() === 'https://pagocards.test/api/visacard/getallcards');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://pagocards.test/api/v1/cards/getallcards');

        $legacy = VirtualCard::where('provider_card_id', 'legacy-list-1')->first();
        $bin493 = VirtualCard::where('provider_card_id', 'card_493_list')->first();

        $this->assertEquals('legacy', $legacy->metadata['pagocards_visa_api']);
        $this->assertEquals('v1_493', $bin493->metadata['pagocards_visa_api']);
        $this->assertEquals(2, VirtualCard::where('user_id', $user->id)->count());
    }

    public function test_create_visa_card_strips_unexpected_initial_load(): void
    {
        config(['mastercard.visa_493.strip_unexpected_initial_load' => true]);

        Http::fake([
            'https://pagocards.test/api/v1/cards' => Http::response([
                'status' => 'success',
                'data' => ['card_id' => 'card_01strip493'],
            ], 200),
            'https://pagocards.test/api/v1/cards/card_01strip493' => Http::sequence()
                ->push([
                    'status' => 'success',
                    'data' => [
                        'card_id' => 'card_01strip493',
                        'card_number' => '4111111111111111',
                        'cvv' => '123',
                        'expiry_month' => '12',
                        'expiry_year' => '2030',
                        'balance' => ['display_amount' => 5],
                    ],
                ], 200)
                ->push([
                    'status' => 'success',
                    'data' => [
                        'card_id' => 'card_01strip493',
                        'balance' => ['display_amount' => 0],
                    ],
                ], 200),
            'https://pagocards.test/api/v1/cards/card_01strip493/withdraw' => Http::response([
                'status' => 'success',
                'data' => ['display_amount' => 5],
            ], 200),
        ]);

        $user = User::factory()->create([
            'email' => 'strip@example.com',
            'first_name' => 'Strip',
            'last_name' => 'Test',
        ]);
        $this->seedNairaWallet($user, 50000);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/virtual-cards/visa-493', [
            'payment_wallet_type' => 'naira_wallet',
            'payment_wallet_currency' => 'NGN',
            'card_name' => 'Strip Card',
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('success', true);

        $card = VirtualCard::where('user_id', $user->id)->first();
        $this->assertNotNull($card);
        $this->assertEquals(0.0, (float) $card->balance);
        $this->assertEquals(5.0, (float) ($card->metadata['stripped_unexpected_initial_load_usd'] ?? 0));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pagocards.test/api/v1/cards/card_01strip493/withdraw'
                && (float) ($request['amount'] ?? 0) === 5.0;
        });
    }

    public function test_visa_493_creation_fee_uses_separate_platform_rates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/virtual-cards/visa-493/creation-fee');

        $response->assertOk()
            ->assertJsonPath('data.card_scheme', 'visa_493')
            ->assertJsonPath('data.card_program.fund_exchange_rate_ngn_per_usd', 1600)
            ->assertJsonPath('data.card_program.card_funding_fee_usd', 1);
    }

    public function test_visa_493_funding_estimate_uses_separate_platform_rates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/virtual-cards/visa-493/funding-estimate?amount=100&payment_wallet_type=naira_wallet');

        $response->assertOk()
            ->assertJsonPath('data.exchange_rate_ngn_per_usd', 1600)
            ->assertJsonPath('data.card_funding_fee_usd', 1)
            ->assertJsonPath('data.charge_ngn', 161600);
    }
}
