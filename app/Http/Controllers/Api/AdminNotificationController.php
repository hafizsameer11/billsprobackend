<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\AdminBanner;
use App\Models\AdminNotification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
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

        $users = $this->resolveAudienceUsers($data['audience']);
        $now = now();

        $campaign = AdminNotification::query()->create([
            'subject' => $data['subject'],
            'message' => $data['message'],
            'audience' => $data['audience'],
            'attachment' => $data['attachment'] ?? null,
            'sent_count' => $users->count(),
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

        return ResponseHelper::success($campaign->fresh(), 'Push notification created.');
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    protected function resolveAudienceUsers(string $audience)
    {
        return match ($audience) {
            'all' => User::query()->select('id')->get(),
            'active' => User::query()->where('account_status', 'active')->select('id')->get(),
            'banned' => User::query()->where('account_status', 'banned')->select('id')->get(),
            'kyc_pending' => User::query()
                ->whereHas('kyc', fn ($q) => $q->where('status', 'pending'))
                ->select('id')
                ->get(),
            'kyc_verified' => User::query()->where('kyc_completed', true)->select('id')->get(),
            'new_users_30d' => User::query()->where('created_at', '>=', now()->subDays(30))->select('id')->get(),
            default => User::query()->select('id')->get(),
        };
    }
}
