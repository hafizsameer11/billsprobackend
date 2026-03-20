<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTatumWebhookJob;
use App\Models\TatumRawWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TatumWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            if ($payload === [] && $request->getContent() !== '') {
                $decoded = json_decode($request->getContent(), true);
                $payload = is_array($decoded) ? $decoded : [];
            }

            $raw = TatumRawWebhook::query()->create([
                'raw_data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'headers' => json_encode($request->headers->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'processed' => false,
            ]);

            ProcessTatumWebhookJob::dispatch($raw->id);
        } catch (\Throwable) {
            // Still return 200 so Tatum does not retry endlessly
        }

        return response()->json(['message' => 'Webhook received'], 200);
    }
}
