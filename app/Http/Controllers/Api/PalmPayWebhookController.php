<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PalmPayDepositOrder;
use App\Models\PalmPayRawWebhook;
use App\Services\PalmPay\PalmPayAuthService;
use App\Services\PalmPay\PalmPayDepositService;
use App\Services\PalmPay\PalmPayWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class PalmPayWebhookController extends Controller
{
    public function __construct(
        protected PalmPayAuthService $auth,
        protected PalmPayWebhookProcessor $processor,
        protected PalmPayDepositService $depositService
    ) {}

    /**
     * PalmPay expects plain text "success" (HTTP 200).
     */
    public function handle(Request $request): \Illuminate\Http\Response
    {
        $rawId = null;
        try {
            $raw = PalmPayRawWebhook::create([
                'raw_data' => json_encode($request->all()),
                'headers' => json_encode($request->headers->all()),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'processed' => false,
            ]);
            $rawId = $raw->id;
        } catch (\Throwable $e) {
            Log::error('PalmPay raw webhook save failed', ['e' => $e->getMessage()]);
        }

        try {
            $payload = $request->all();
            $sign = $payload['sign'] ?? null;

            if (Config::get('palmpay.verify_webhook_signature', true)) {
                if (! is_string($sign) || $sign === '') {
                    $this->markRaw($rawId, 'Missing sign');

                    return response('success', 200)->header('Content-Type', 'text/plain');
                }
                if (! $this->auth->verifyWebhookPayload($payload, $sign)) {
                    Log::warning('PalmPay webhook signature verification failed');
                    $this->markRaw($rawId, 'Invalid signature');

                    return response('success', 200)->header('Content-Type', 'text/plain');
                }
            }

            $this->processor->processVerifiedPayload($payload);

            $this->markRaw($rawId, null);
        } catch (\Throwable $e) {
            Log::error('PalmPay webhook processing error', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->markRaw($rawId, $e->getMessage());
        }

        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    private function markRaw(?int $rawId, ?string $error): void
    {
        if ($rawId === null) {
            return;
        }
        PalmPayRawWebhook::where('id', $rawId)->update([
            'processed' => true,
            'processed_at' => now(),
            'error_message' => $error,
        ]);
    }

    /**
     * Public no-parameter recovery endpoint.
     * Reprocesses failed/pending PalmPay raw webhooks and refreshes pending PalmPay deposit orders.
     */
    public function replayPending(): JsonResponse
    {
        $replayed = 0;
        $replayErrors = 0;
        $statusChecks = 0;
        $completedAfterRefresh = 0;

        $rawRows = PalmPayRawWebhook::query()
            ->where(function ($q) {
                $q->where('processed', false)
                    ->orWhereNotNull('error_message');
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($rawRows as $raw) {
            $payload = json_decode((string) $raw->raw_data, true);
            if (! is_array($payload)) {
                $raw->update([
                    'processed' => true,
                    'processed_at' => now(),
                    'error_message' => 'Invalid stored payload',
                ]);
                $replayErrors++;
                continue;
            }

            try {
                $this->processor->processVerifiedPayload($payload);
                $raw->update([
                    'processed' => true,
                    'processed_at' => now(),
                    'error_message' => null,
                ]);
                $replayed++;
            } catch (\Throwable $e) {
                $raw->update([
                    'processed' => true,
                    'processed_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
                $replayErrors++;
            }
        }

        $pendingOrders = PalmPayDepositOrder::query()
            ->with('deposit')
            ->whereHas('deposit', function ($q) {
                $q->where('status', 'pending');
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($pendingOrders as $order) {
            if (! $order->deposit) {
                continue;
            }
            try {
                $statusChecks++;
                $this->depositService->refreshRemoteStatus($order->deposit, $order);
                $fresh = $order->deposit->fresh();
                if ($fresh && $fresh->status === 'completed') {
                    $completedAfterRefresh++;
                }
            } catch (\Throwable $e) {
                Log::warning('PalmPay replayPending status refresh failed', [
                    'order_id' => $order->id,
                    'merchant_order_id' => $order->merchant_order_id,
                    'e' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'PalmPay replay and pending refresh completed.',
            'data' => [
                'raw_reprocessed' => $replayed,
                'raw_reprocess_errors' => $replayErrors,
                'pending_status_checked' => $statusChecks,
                'deposits_completed_after_refresh' => $completedAfterRefresh,
            ],
        ]);
    }
}
