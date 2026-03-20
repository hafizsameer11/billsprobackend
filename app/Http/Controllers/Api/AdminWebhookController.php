<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessTatumWebhookJob;
use App\Models\PalmPayRawWebhook;
use App\Models\TatumRawWebhook;
use App\Services\PalmPay\PalmPayWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class AdminWebhookController extends Controller
{
    public function __construct(
        protected PalmPayWebhookProcessor $palmPayProcessor
    ) {}

    public function tatumRaw(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = TatumRawWebhook::query()->orderByDesc('id');

        if ($request->filled('processed')) {
            $q->where('processed', filter_var($request->query('processed'), FILTER_VALIDATE_BOOL));
        }

        return ResponseHelper::success($q->paginate($perPage), 'Tatum raw webhooks retrieved.');
    }

    public function palmpayRaw(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = PalmPayRawWebhook::query()->orderByDesc('id');

        if ($request->filled('processed')) {
            $q->where('processed', filter_var($request->query('processed'), FILTER_VALIDATE_BOOL));
        }

        return ResponseHelper::success($q->paginate($perPage), 'PalmPay raw webhooks retrieved.');
    }

    public function replayTatum(Request $request, int $id): JsonResponse
    {
        if (! Config::get('admin.webhook_replay_enabled')) {
            return ResponseHelper::error('Webhook replay is disabled.', 403);
        }

        $raw = TatumRawWebhook::query()->findOrFail($id);
        ProcessTatumWebhookJob::dispatch($raw->id);

        return ResponseHelper::success(['job' => 'ProcessTatumWebhookJob', 'tatum_raw_webhook_id' => $raw->id], 'Replay queued.');
    }

    public function replayPalmpay(Request $request, int $id): JsonResponse
    {
        if (! Config::get('admin.webhook_replay_enabled')) {
            return ResponseHelper::error('Webhook replay is disabled.', 403);
        }

        $raw = PalmPayRawWebhook::query()->findOrFail($id);
        $payload = json_decode($raw->raw_data, true);
        if (! is_array($payload)) {
            return ResponseHelper::error('Invalid stored payload.', 422);
        }

        try {
            $this->palmPayProcessor->processVerifiedPayload($payload);
        } catch (\Throwable $e) {
            return ResponseHelper::error('Replay failed: '.$e->getMessage(), 500);
        }

        return ResponseHelper::success(null, 'PalmPay payload reprocessed.');
    }
}
