<?php

namespace App\Services\Http;

use App\Models\IdempotencyKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyService
{
    public function resolveReplay(Request $request, int $userId, string $route): ?JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return null;
        }

        $existing = IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->where('route', $route)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (! $existing) {
            return null;
        }

        return response()->json($existing->response_body ?? [], $existing->status_code);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function store(Request $request, int $userId, string $route, int $statusCode, ?array $body): void
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return;
        }

        IdempotencyKey::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'idempotency_key' => $key,
            ],
            [
                'route' => $route,
                'status_code' => $statusCode,
                'response_body' => $body,
                'expires_at' => Carbon::now()->addDay(),
            ]
        );
    }
}
