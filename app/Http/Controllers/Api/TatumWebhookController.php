<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTatumWebhookJob;
use App\Models\TatumRawWebhook;
use App\Models\WebhookRawPayload;
use App\Services\Tatum\TatumWebhookPayloadNormalizer;
use App\Services\Tatum\TatumWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TatumWebhookController extends Controller
{
    public function __construct(
        protected TatumWebhookVerifier $verifier,
        protected TatumWebhookPayloadNormalizer $normalizer
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifier->verify($request)) {
            return response()->json(['message' => 'Invalid webhook signature'], 401);
        }

        try {
            $rawContent = $request->getContent();
            $payload = $request->all();
            if ($payload === [] && $rawContent !== '') {
                $decoded = json_decode($rawContent, true);
                $payload = is_array($decoded) ? $decoded : [];
            }

            $normalized = $this->normalizer->normalize($payload);
            $channel = $this->normalizer->inferChannel($payload);
            $txId = $this->normalizer->extractTxId($normalized);
            $receivingAddress = $this->normalizer->extractReceivingAddress($normalized);
            $subscriptionType = $this->normalizer->inferSubscriptionType($normalized);

            $rawData = $rawContent !== ''
                ? $rawContent
                : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $headers = json_encode($request->headers->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $raw = TatumRawWebhook::query()->create([
                'channel' => $channel,
                'raw_data' => $rawData,
                'headers' => $headers,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'tx_id' => $txId,
                'subscription_type' => $subscriptionType !== '' ? $subscriptionType : null,
                'receiving_address' => $receivingAddress,
                'processed' => false,
            ]);

            WebhookRawPayload::query()->create([
                'provider' => 'tatum',
                'channel' => $channel,
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'tx_id' => $txId,
                'subscription_type' => $subscriptionType !== '' ? $subscriptionType : null,
                'receiving_address' => $receivingAddress,
                'tatum_raw_webhook_id' => $raw->id,
            ]);

            ProcessTatumWebhookJob::dispatch($raw->id);
        } catch (\Throwable $e) {
            Log::error('Tatum webhook raw storage failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Webhook received'], 200);
    }
}
