<?php

namespace Tests\Feature;

use App\Jobs\SendExpoPushToUserJob;
use App\Models\Notification;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PagocardsVirtualCardWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeVirtualCardForUser(User $user, string $providerCardId): VirtualCard
    {
        return VirtualCard::query()->create([
            'user_id' => $user->id,
            'card_name' => 'Test Card',
            'card_number' => (string) random_int(1000000000000000, 9999999999999999),
            'cvv' => '123',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'card_type' => 'mastercard',
            'provider' => 'pagocards',
            'provider_card_id' => $providerCardId,
            'provider_status' => 'active',
            'card_color' => 'green',
            'currency' => 'USD',
            'balance' => 0,
            'is_active' => true,
            'is_frozen' => false,
        ]);
    }

    public function test_duplicate_event_id_is_idempotent(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeVirtualCardForUser($user, 'crd-test-duplicate');

        $payload = [
            'eventId' => 'evt-dup-1',
            'eventName' => 'cardAuthentication.created',
            'cardId' => 'crd-test-duplicate',
            'eventTargetId' => '3ds-target-1',
            'merchantName' => 'Acme',
            'merchantAmount' => '10.00',
            'merchantCurrency' => 'USD',
        ];

        $this->postJson('/api/webhooks/pagocards/virtual-cards', $payload)->assertOk();
        $this->postJson('/api/webhooks/pagocards/virtual-cards', $payload)->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertEquals(1, VirtualCardProviderWebhookEvent::query()->count());
        $this->assertEquals(1, Notification::query()->where('user_id', $user->id)->count());
    }

    public function test_3ds_webhook_resolves_user_by_provider_card_id(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $card = $this->makeVirtualCardForUser($user, 'crd-resolve-99');

        $payload = [
            'eventId' => 'evt-3ds-1',
            'eventName' => 'cardAuthentication.created',
            'cardId' => 'crd-resolve-99',
            'userId' => 'usr-pagocards-1',
            'eventTargetId' => '3ds-challenge-abc',
            'merchantName' => 'Coffee Shop',
            'merchantAmount' => '25.50',
            'merchantCurrency' => 'USD',
            'maskedPan' => '533812******1234',
        ];

        $this->postJson('/api/webhooks/pagocards/virtual-cards', $payload)->assertOk();

        $this->assertDatabaseHas('virtual_card_provider_webhook_events', [
            'external_event_id' => 'evt-3ds-1',
            'virtual_card_id' => $card->id,
            'user_id' => $user->id,
            'event_name' => 'cardAuthentication.created',
            'event_target_id' => '3ds-challenge-abc',
            'status' => VirtualCardProviderWebhookEvent::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'virtual_card',
        ]);

        Queue::assertPushed(SendExpoPushToUserJob::class, function (SendExpoPushToUserJob $job): bool {
            return $job->data['screen'] === 'VirtualCards'
                && $job->data['kind'] === 'pagocards_3ds';
        });
    }

    public function test_authenticated_user_can_list_pending_provider_events(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->makeVirtualCardForUser($user, 'crd-pending-list');

        $this->postJson('/api/webhooks/pagocards/virtual-cards', [
            'eventId' => 'evt-pending-list-1',
            'eventName' => 'cardAuthentication.created',
            'cardId' => 'crd-pending-list',
            'eventTargetId' => '3ds-list-1',
            'merchantName' => 'Test Merchant',
        ])->assertOk();

        Sanctum::actingAs($user);

        $this->getJson('/api/virtual-cards/pending-provider-events')
            ->assertOk()
            ->assertJsonPath('data.0.event_name', 'cardAuthentication.created')
            ->assertJsonPath('data.0.event_target_id', '3ds-list-1');
    }

    public function test_unknown_card_stores_event_without_user(): void
    {
        Queue::fake();

        $payload = [
            'eventId' => 'evt-orphan-1',
            'eventName' => 'cardAuthentication.created',
            'cardId' => 'crd-does-not-exist',
            'eventTargetId' => '3ds-x',
        ];

        $this->postJson('/api/webhooks/pagocards/virtual-cards', $payload)->assertOk();

        $row = VirtualCardProviderWebhookEvent::query()->where('external_event_id', 'evt-orphan-1')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
        $this->assertNull($row->virtual_card_id);

        Queue::assertNothingPushed();
    }
}
