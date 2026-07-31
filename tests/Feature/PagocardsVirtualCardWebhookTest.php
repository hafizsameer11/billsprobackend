<?php

namespace Tests\Feature;

use App\Jobs\SendExpoPushToUserJob;
use App\Models\Notification;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardProviderWebhookEvent;
use App\Models\VirtualCardTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PagocardsVirtualCardWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function webhookUrl(): string
    {
        return '/api/webhooks/pagocards/virtual-cards/test-webhook-token';
    }

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

        $this->postJson($this->webhookUrl(), $payload)->assertOk();
        $this->postJson($this->webhookUrl(), $payload)->assertOk()
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

        $this->postJson($this->webhookUrl(), $payload)->assertOk();

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

        $this->postJson($this->webhookUrl(), [
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

    public function test_unknown_card_webhook_is_ignored(): void
    {
        Queue::fake();

        $payload = [
            'eventId' => 'evt-orphan-1',
            'eventName' => 'cardAuthentication.created',
            'cardId' => 'crd-does-not-exist',
            'eventTargetId' => '3ds-x',
        ];

        $this->postJson($this->webhookUrl(), $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Ignored: unknown card');

        $this->assertEquals(0, VirtualCardProviderWebhookEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_new_settlement_event_creates_usd_spend_and_push_notification(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $card = $this->makeVirtualCardForUser($user, '019f-card-settlement');

        $this->postJson($this->webhookUrl(), [
            'event' => 'virtualcard.transaction.settlement',
            'event_id' => 'evt-new-settlement-1',
            'data' => [
                'id' => 'provider-settlement-1',
                'cardId' => '019f-card-settlement',
                'amount' => 29940000,
                'display_amount' => 29.94,
                'currency' => 'USD',
                'merchant_name' => 'DOMINOS',
                'reference' => 'CARD_SETTLE_1',
                'status' => 'completed',
                'timestamp' => '2026-07-30T19:13:19Z',
                'transaction_type' => 'settlement',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('virtual_card_transactions', [
            'virtual_card_id' => $card->id,
            'provider_transaction_id' => 'provider-settlement-1',
            'type' => 'payment',
            'status' => 'completed',
            'currency' => 'USD',
            'amount' => 29.94,
            'total_amount' => 29.94,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Card payment completed',
        ]);
        Queue::assertPushed(SendExpoPushToUserJob::class, function (SendExpoPushToUserJob $job): bool {
            return $job->data['kind'] === 'pagocards_settlement'
                && str_contains($job->body, '$29.94');
        });
    }

    public function test_decline_and_decline_charge_are_separate_notifications_and_transactions(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $card = $this->makeVirtualCardForUser($user, '019f-card-decline');
        $providerTransactionId = 'provider-decline-1';

        $this->postJson($this->webhookUrl(), [
            'event' => 'virtualcard.transaction.declined',
            'event_id' => 'evt-decline-1',
            'data' => [
                'id' => $providerTransactionId,
                'cardId' => '019f-card-decline',
                'amount' => 4280000,
                'display_amount' => 4.28,
                'currency' => 'USD',
                'merchant_name' => 'TikTok Ads',
                'reason' => 'No sufficient funds',
                'reference' => 'CARD_AUTH_DECLINE_1',
                'status' => 'decline',
                'transaction_type' => 'authorization_declined',
            ],
        ])->assertOk();

        $this->postJson($this->webhookUrl(), [
            'event' => 'virtualcard.transaction.declined.charge',
            'event_id' => 'evt-decline-charge-1',
            'data' => [
                'id' => $providerTransactionId,
                'cardId' => '019f-card-decline',
                'feeAmount' => 500000,
                'reference' => 'CARD_AUTH_DECLINE_1',
                'status' => 'completed',
                'violationCount' => 5,
            ],
        ])->assertOk();

        $this->assertDatabaseHas('virtual_card_transactions', [
            'virtual_card_id' => $card->id,
            'provider_transaction_id' => $providerTransactionId,
            'type' => 'payment',
            'status' => 'failed',
            'amount' => 4.28,
        ]);
        $this->assertDatabaseHas('virtual_card_transactions', [
            'virtual_card_id' => $card->id,
            'provider_transaction_id' => $providerTransactionId.':fee:declined-charge',
            'type' => 'fee',
            'status' => 'completed',
            'fee' => 0.50,
            'total_amount' => 0.50,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Card payment declined',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Declined transaction fee charged',
        ]);
    }

    public function test_cross_border_event_converts_micro_units_to_usd_fee(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $card = $this->makeVirtualCardForUser($user, '019f-card-cross-border');

        $this->postJson($this->webhookUrl(), [
            'event' => 'virtualcard.transaction.crossborder',
            'event_id' => 'evt-cross-border-1',
            'data' => [
                'cardId' => '019f-card-cross-border',
                'amount' => 1250000,
                'chargedAmount' => 1250000,
                'currency' => 'USD',
                'merchant_name' => 'DOMINOS',
                'reference' => 'CARD_CROSSBORD_1',
                'status' => 'completed',
                'transaction_type' => 'cross_border_settled',
            ],
        ])->assertOk();

        $transaction = VirtualCardTransaction::query()
            ->where('virtual_card_id', $card->id)
            ->where('type', 'fee')
            ->firstOrFail();
        $this->assertSame(1.25, (float) $transaction->fee);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Cross-border card fee charged',
        ]);
    }

    public function test_duplicate_topup_transaction_with_different_event_ids_has_one_side_effect(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $card = $this->makeVirtualCardForUser($user, '019f-card-topup');
        $data = [
            'id' => 'provider-topup-1',
            'cardId' => '019f-card-topup',
            'amount' => 25000000,
            'reference' => 'funding-topup-1',
            'status' => 'completed',
        ];

        $this->postJson($this->webhookUrl(), [
            'event' => 'virtualcard.topup.completed',
            'event_id' => 'evt-topup-1',
            'data' => $data,
        ])->assertOk();
        $this->postJson($this->webhookUrl(), [
            'event' => 'virtualcard.topup.completed',
            'event_id' => 'evt-topup-2',
            'data' => $data,
        ])->assertOk()
            ->assertJsonPath('message', 'Webhook recorded; duplicate transaction side effects ignored');

        $this->assertSame(2, VirtualCardProviderWebhookEvent::query()->count());
        $this->assertSame(1, VirtualCardTransaction::query()
            ->where('virtual_card_id', $card->id)
            ->where('provider_transaction_id', 'provider-topup-1')
            ->count());
        $this->assertSame(1, Notification::query()
            ->where('user_id', $user->id)
            ->where('title', 'Virtual card funded')
            ->count());
    }
}
