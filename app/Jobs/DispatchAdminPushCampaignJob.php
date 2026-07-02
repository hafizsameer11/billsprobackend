<?php

namespace App\Jobs;

use App\Models\AdminNotification;
use App\Services\Admin\AdminNotificationAudienceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchAdminPushCampaignJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $campaignId) {}

    public function uniqueId(): string
    {
        return 'admin-push-campaign-'.$this->campaignId;
    }

    public function handle(AdminNotificationAudienceService $audience): void
    {
        $campaign = AdminNotification::query()->find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $audience->usersWithPushTokensQuery((string) $campaign->audience)
            ->chunkById(100, function ($users) use ($campaign): void {
                foreach ($users as $user) {
                    SendExpoPushToUserJob::dispatch(
                        (int) $user->id,
                        (string) $campaign->subject,
                        (string) $campaign->message,
                        [
                            'screen' => 'Notifications',
                            'type' => 'admin_push',
                            'campaign_id' => (string) $campaign->id,
                        ],
                    );
                }
            });
    }
}
