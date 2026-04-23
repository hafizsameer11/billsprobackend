<?php

namespace App\Services\Expo;

use App\Models\UserExpoPushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExpoPushNotificationService
{
    /**
     * @param  array<string, mixed>  $data  Passed to the client as notification.request.content.data
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = UserExpoPushToken::query()
            ->where('user_id', $userId)
            ->pluck('expo_push_token')
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        $url = (string) config('expo.push_api_url', 'https://exp.host/--/api/v2/push/send');
        $accessToken = config('expo.access_token');

        $chunks = array_chunk($tokens, 90);
        foreach ($chunks as $chunk) {
            $messages = [];
            foreach ($chunk as $token) {
                $messages[] = [
                    'to' => $token,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'sound' => 'default',
                ];
            }

            $headers = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];
            if (is_string($accessToken) && $accessToken !== '') {
                $headers['Authorization'] = 'Bearer '.$accessToken;
            }

            try {
                $response = Http::timeout(20)
                    ->withHeaders($headers)
                    ->post($url, $messages);

                if (! $response->successful()) {
                    Log::warning('expo_push.send_failed', [
                        'user_id' => $userId,
                        'status' => $response->status(),
                        'body' => Str::limit($response->body(), 2000),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('expo_push.send_exception', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
