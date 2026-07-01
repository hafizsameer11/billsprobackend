<?php

namespace App\Http\Controllers\Concerns;

use App\Helpers\ResponseHelper;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;

trait VerifiesTransactionPin
{
    protected function requireValidTransactionPin(User $user, ?string $pin): JsonResponse|true
    {
        if ($pin === null || $pin === '') {
            return ResponseHelper::error('Transaction PIN is required', 403);
        }

        $authService = app(AuthService::class);
        if (! $authService->verifyPin($user, $pin)) {
            return ResponseHelper::error('Invalid PIN', 403);
        }

        return true;
    }
}
