<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTatumWebhookJob;
use App\Models\TatumRawWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TatumWebhookController extends Controller
{
    /**
     * Re-queue processing for a stored raw webhook (e.g. after fixing payload handling or setting processed=0).
     */
    public function replay(int $id): JsonResponse
    {
        $raw = TatumRawWebhook::query()->find($id);
        if (! $raw) {
            return response()->json(['message' => 'Raw webhook not found'], 404);
        }

        ProcessTatumWebhookJob::dispatch($raw->id);

        return response()->json([
            'message' => 'Replay queued',
            'tatum_raw_webhook_id' => $raw->id,
        ], 200);
    }

    /**
     * Queue ProcessTatumWebhookJob for every row with processed=false (oldest first).
     * Optional query: limit (default 200, max 500).
     */
    public function replayPending(Request $request): JsonResponse
    {
        $limit = min(500, max(1, (int) $request->query('limit', 200)));

        $ids = TatumRawWebhook::query()
            ->where('processed', false)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $rawId) {
            ProcessTatumWebhookJob::dispatch($rawId);
        }

        return response()->json([
            'message' => 'Replay queued for pending raw webhooks',
            'count' => $ids->count(),
            'limit' => $limit,
            'tatum_raw_webhook_ids' => $ids->values()->all(),
        ], 200);
    }

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
