<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PalmPayRawWebhook;
use App\Services\PalmPay\PalmPayAuthService;
use App\Services\PalmPay\PalmPayWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class PalmPayWebhookController extends Controller
{
    public function __construct(
        protected PalmPayAuthService $auth,
        protected PalmPayWebhookProcessor $processor
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
}
