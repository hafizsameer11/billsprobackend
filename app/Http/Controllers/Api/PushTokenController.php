<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\UserExpoPushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expo_push_token' => 'required|string|max:512',
            'platform' => 'nullable|string|max:32',
            'device_id' => 'nullable|string|max:191',
        ]);

        $user = $request->user();

        UserExpoPushToken::query()->updateOrCreate(
            ['expo_push_token' => $data['expo_push_token']],
            [
                'user_id' => $user->id,
                'platform' => $data['platform'] ?? null,
                'device_id' => $data['device_id'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return ResponseHelper::success(null, 'Push token registered.');
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expo_push_token' => 'required|string|max:512',
        ]);

        UserExpoPushToken::query()
            ->where('user_id', $request->user()->id)
            ->where('expo_push_token', $data['expo_push_token'])
            ->delete();

        return ResponseHelper::success(null, 'Push token removed.');
    }
}
