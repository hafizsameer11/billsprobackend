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

        // One row per Expo token (global unique). Same device re-login updates this row — no duplicate tokens.
        $token = UserExpoPushToken::query()->firstOrNew([
            'expo_push_token' => $data['expo_push_token'],
        ]);
        $wasNew = ! $token->exists;
        $token->fill([
            'user_id' => $user->id,
            'platform' => $data['platform'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'last_seen_at' => now(),
        ]);
        $token->save();

        $message = $wasNew
            ? 'Push token registered.'
            : 'Push token refreshed (same device; no duplicate rows).';

        return ResponseHelper::success([
            'was_new' => $wasNew,
        ], $message);
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
