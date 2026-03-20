<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VirtualCard\VirtualCardService;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class VirtualCardFlowTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_card_requires_firstname_and_lastname(): void
    {
        $user = User::factory()->make(['id' => 1]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/virtual-cards', [
            'card_name' => 'My Master Card',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['firstname', 'lastname', 'payment_wallet_type']);
    }

    public function test_fund_card_maps_provider_error_response(): void
    {
        $user = User::factory()->make(['id' => 1]);
        Sanctum::actingAs($user);

        $service = Mockery::mock(VirtualCardService::class);
        $service->shouldReceive('fundCard')
            ->once()
            ->andReturnUsing(static fn (): array => [
                'success' => false,
                'status' => 404,
                'message' => 'Provider funding endpoint is not available.',
            ]);
        $this->app->instance(VirtualCardService::class, $service);

        $response = $this->postJson('/api/virtual-cards/1/fund', [
            'amount' => 50,
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Provider funding endpoint is not available.');
    }

    public function test_terminate_route_returns_success_response_shape(): void
    {
        $user = User::factory()->make(['id' => 1]);
        Sanctum::actingAs($user);

        $service = Mockery::mock(VirtualCardService::class);
        $service->shouldReceive('terminateCard')
            ->once()
            ->andReturnUsing(static fn (): array => [
                'success' => true,
                'message' => 'Card terminated successfully',
                'data' => ['card' => ['id' => 10, 'provider_status' => 'terminated']],
            ]);
        $this->app->instance(VirtualCardService::class, $service);

        $response = $this->postJson('/api/virtual-cards/10/terminate');

        $response->assertOk()
            ->assertJsonPath('message', 'Card terminated successfully')
            ->assertJsonPath('data.card.id', 10);
    }
}
