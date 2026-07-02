<?php

namespace Tests\Feature;

use App\Jobs\DispatchAdminPushCampaignJob;
use App\Jobs\SendExpoPushToUserJob;
use App\Models\AdminNotification;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserExpoPushToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNotificationPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_campaign_queues_expo_push_for_users_with_saved_tokens(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $withTokenA = User::factory()->create();
        $withTokenB = User::factory()->create();
        User::factory()->create();

        foreach ([$withTokenA, $withTokenB] as $i => $user) {
            UserExpoPushToken::query()->create([
                'user_id' => $user->id,
                'expo_push_token' => 'ExponentPushToken[test-'.$i.']',
                'platform' => 'android',
            ]);
        }

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/notifications', [
            'subject' => 'Promo',
            'message' => 'Hello from BillsPro',
            'audience' => 'all',
        ])
            ->assertOk()
            ->assertJsonPath('data.push_queued_count', 2)
            ->assertJsonPath('data.sent_count', 4);

        $campaignId = (int) AdminNotification::query()->value('id');
        $this->assertEquals(4, Notification::query()->where('type', 'admin_push')->count());

        Queue::assertPushed(DispatchAdminPushCampaignJob::class, function (DispatchAdminPushCampaignJob $job) use ($campaignId): bool {
            return $job->campaignId === $campaignId;
        });

        $job = new DispatchAdminPushCampaignJob($campaignId);
        $job->handle(app(\App\Services\Admin\AdminNotificationAudienceService::class));

        Queue::assertPushed(SendExpoPushToUserJob::class, 2);
        Queue::assertPushed(SendExpoPushToUserJob::class, function (SendExpoPushToUserJob $job) use ($withTokenA): bool {
            return $job->userId === $withTokenA->id
                && $job->title === 'Promo'
                && $job->data['type'] === 'admin_push';
        });
    }

    public function test_admin_campaign_skips_push_queue_when_no_tokens_exist(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->count(2)->create();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/notifications', [
            'subject' => 'Promo',
            'message' => 'No devices yet',
            'audience' => 'all',
        ])
            ->assertOk()
            ->assertJsonPath('data.push_queued_count', 0);

        Queue::assertNotPushed(DispatchAdminPushCampaignJob::class);
    }
}
