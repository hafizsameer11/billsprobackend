<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VirtualCard\PagocardsVirtualCardWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PagocardsVirtualCardWebhookController extends Controller
{
    public function __construct(
        protected PagocardsVirtualCardWebhookService $processor
    ) {}

    public function handle(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('mastercard.webhook_token', '');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
        }

        $result = $this->processor->handle($request);

        $body = [
            'success' => (bool) ($result['success'] ?? true),
            'message' => (string) ($result['message'] ?? 'OK'),
            'data' => $result['data'] ?? null,
        ];
        if (array_key_exists('duplicate', $result)) {
            $body['duplicate'] = (bool) $result['duplicate'];
        }

        return response()->json($body, 200);
    }
}
