<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchAdminPushCampaignJob;
use App\Models\AdminBanner;
use App\Models\AdminNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\Admin\AdminNotificationAudienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function __construct(
        protected AdminNotificationAudienceService $audienceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = AdminNotification::query()
            ->with('creator:id,name,email')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ResponseHelper::success($paginator, 'Admin notifications retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            'audience' => 'required|string|in:all,active,banned,kyc_pending,kyc_verified,new_users_30d',
            'attachment' => 'nullable|string|max:1000000',
        ]);

        $users = $this->audienceService->resolveUsers($data['audience']);
        $pushQueuedCount = $this->audienceService->countUsersWithPushTokens($data['audience']);
        $now = now();

        $campaign = AdminNotification::query()->create([
            'subject' => $data['subject'],
            'message' => $data['message'],
            'audience' => $data['audience'],
            'attachment' => $data['attachment'] ?? null,
            'sent_count' => $users->count(),
            'push_queued_count' => $pushQueuedCount,
            'created_by' => $request->user()?->id,
        ]);

        $rows = $users->map(fn (User $u): array => [
            'user_id' => $u->id,
            'type' => 'admin_push',
            'title' => $data['subject'],
            'message' => $data['message'],
            'read' => false,
            'metadata' => json_encode([
                'campaign_id' => $campaign->id,
                'audience' => $data['audience'],
                'attachment' => $data['attachment'] ?? null,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        if ($rows !== []) {
            Notification::query()->insert($rows);
        }

        if ($pushQueuedCount > 0) {
            DispatchAdminPushCampaignJob::dispatch($campaign->id);
        }

        $message = $pushQueuedCount > 0
            ? "Push notification created. Expo push queued for {$pushQueuedCount} user(s) with saved device tokens."
            : 'Push notification created. No users in this audience have a saved Expo push token yet.';

        return ResponseHelper::success($campaign->fresh(), $message);
    }

    public function destroy(AdminNotification $notification): JsonResponse
    {
        $notification->delete();

        return ResponseHelper::success(null, 'Notification deleted.');
    }

    public function banners(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = AdminBanner::query()
            ->with('creator:id,name,email')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ResponseHelper::success($paginator, 'Banners retrieved.');
    }

    public function storeBanner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => 'required|string|max:2000000',
            'is_active' => 'nullable|boolean',
        ]);

        $banner = AdminBanner::query()->create([
            'image' => $data['image'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()?->id,
        ]);

        return ResponseHelper::success($banner, 'Banner created.');
    }

    public function destroyBanner(AdminBanner $banner): JsonResponse
    {
        $banner->delete();

        return ResponseHelper::success(null, 'Banner deleted.');
    }
}
