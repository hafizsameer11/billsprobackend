<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\FiatWallet;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFiatWalletController extends Controller
{
    public function __construct(
        protected AdminAuditService $audit
    ) {}

    public function forUser(Request $request, User $user): JsonResponse
    {
        $wallets = FiatWallet::query()->where('user_id', $user->id)->orderBy('id')->get();

        return ResponseHelper::success($wallets, 'Fiat wallets retrieved.');
    }

    public function update(Request $request, FiatWallet $fiatWallet): JsonResponse
    {
        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'locked_balance' => 'sometimes|numeric|min:0',
        ]);

        $before = $fiatWallet->only(['is_active', 'locked_balance']);
        $fiatWallet->update($data);
        $this->audit->log((int) $request->user()->id, 'fiat_wallet.update', $fiatWallet, [
            'before' => $before,
            'after' => $fiatWallet->only(['is_active', 'locked_balance']),
        ], $request);

        return ResponseHelper::success($fiatWallet->fresh(), 'Fiat wallet updated.');
    }
}
